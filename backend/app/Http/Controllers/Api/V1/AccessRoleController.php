<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\AccessRole;
use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\RoleKey;
use App\Domain\Shared\Concurrency\ETag;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Access Roles screen: the roles and the editable permission matrix (plan 01 §4). */
class AccessRoleController extends Controller
{
    public function index(PermissionMatrix $matrix): JsonResponse
    {
        $permissions = DB::table('permissions')->orderBy('sort')->get(['key', 'group', 'label']);
        $userCounts = User::query()->selectRaw('role_id, COUNT(*) as n')->groupBy('role_id')->pluck('n', 'role_id');

        $roles = AccessRole::query()->orderBy('id')->get()->map(fn (AccessRole $role) => [
            'key' => $role->key,
            'name' => $role->name,
            'note' => $role->note,
            'locked' => $role->locked,
            'version' => $role->version,
            'users' => (int) ($userCounts[$role->id] ?? 0),
            'permissions' => $matrix->permissionsFor(RoleKey::from($role->key)),
        ]);

        return response()->json(['data' => [
            'roles' => $roles,
            'permissions' => $permissions,
        ]]);
    }

    public function updatePermissions(Request $request, AccessRole $role, PermissionMatrix $matrix): JsonResponse
    {
        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct'],
        ]);

        /** @var User $actor */
        $actor = $request->user();
        $role = $matrix->setRolePermissions($role, $data['permissions'], ETag::expectedVersion($request), $actor);

        return response()->json(['data' => [
            'key' => $role->key,
            'version' => $role->version,
            'permissions' => $matrix->permissionsFor(RoleKey::from($role->key)),
        ]])->setEtag((string) $role->version);
    }
}
