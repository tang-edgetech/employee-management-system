<?php

namespace App\Domain\Access;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which role has which permission (brief §5.3). Deny by default: a permission is
 * granted only by a role_permissions row with granted = 1. System Admin always
 * has everything and can't be edited.
 *
 * The matrix is cached and the cache is cleared whenever it changes.
 */
final class PermissionMatrix
{
    private const CACHE_KEY = 'access:matrix';

    /** @var array<string, list<string>>|null role key => granted permission keys */
    private ?array $matrix = null;

    public function allows(User $user, Permission|string $permission): bool
    {
        $key = $permission instanceof Permission ? $permission->value : $permission;

        return in_array($key, $this->permissionsFor($user->roleKey()), true);
    }

    /** @return list<string> */
    public function permissionsFor(RoleKey $role): array
    {
        if ($role === RoleKey::Sysadmin) {
            return Permission::values();
        }

        return $this->matrix()[$role->value] ?? [];
    }

    /** @return array<string, list<string>> */
    public function matrix(): array
    {
        return $this->matrix ??= Cache::rememberForever(self::CACHE_KEY, function () {
            $rows = DB::table('role_permissions as rp')
                ->join('access_roles as r', 'r.id', '=', 'rp.role_id')
                ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
                ->where('rp.granted', true)
                ->orderBy('p.sort')
                ->get(['r.key as role', 'p.key as permission']);

            $matrix = [];
            foreach ($rows as $row) {
                $matrix[$row->role][] = $row->permission;
            }

            return $matrix;
        });
    }

    /**
     * Replaces a role's grants. Refuses the locked System Admin role, and refuses
     * to let someone remove Global Settings access from their own role, which
     * would lock them out of this screen.
     *
     * @param  list<string>  $granted
     */
    public function setRolePermissions(AccessRole $role, array $granted, int $expectedVersion, User $actor): AccessRole
    {
        $unknown = array_diff($granted, Permission::values());
        if ($unknown !== []) {
            throw ApiProblem::unprocessable('UNKNOWN_PERMISSION', 'Unknown permission: '.implode(', ', $unknown).'.');
        }
        if ($role->locked) {
            throw ApiProblem::conflict('ROLE_LOCKED', 'The '.$role->name.' role always has every permission and can\'t be edited.');
        }
        if ($actor->role_id === $role->id && ! in_array(Permission::SystemSettings->value, $granted, true)) {
            throw ApiProblem::conflict('CANNOT_REMOVE_OWN_ACCESS', 'You can\'t remove Global Settings access from your own role.');
        }

        $role = DB::transaction(function () use ($role, $granted, $expectedVersion) {
            /** @var AccessRole $locked */
            $locked = AccessRole::query()->lockForUpdate()->findOrFail($role->id);
            if ($locked->version !== $expectedVersion) {
                throw ApiProblem::preconditionFailed();
            }

            $before = $this->grantedKeys($locked->id);
            $after = array_values(array_intersect(Permission::values(), $granted));

            if ($before === $after) {
                return $locked;
            }

            $ids = DB::table('permissions')->pluck('id', 'key');
            foreach ($ids as $key => $permissionId) {
                DB::table('role_permissions')->updateOrInsert(
                    ['role_id' => $locked->id, 'permission_id' => $permissionId],
                    ['granted' => in_array($key, $after, true)],
                );
            }

            $locked->version = $locked->version + 1;
            $locked->save();

            app(AuditLogger::class)->record('access_roles.permissions_changed', $locked, [
                'before' => ['granted' => $before],
                'after' => ['granted' => $after],
                'added' => array_values(array_diff($after, $before)),
                'removed' => array_values(array_diff($before, $after)),
            ]);

            return $locked;
        });

        $this->forget();

        return $role;
    }

    public function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->matrix = null;
    }

    /** @return list<string> in catalogue order */
    private function grantedKeys(int $roleId): array
    {
        return DB::table('role_permissions as rp')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('rp.role_id', $roleId)->where('rp.granted', true)
            ->orderBy('p.sort')->pluck('p.key')->all();
    }
}
