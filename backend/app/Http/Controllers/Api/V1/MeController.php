<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\ScopeResolver;
use App\Domain\Audit\AuditLogger;
use App\Domain\Files\StoredFile;
use App\Domain\Settings\CompanySettings;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public const LOCALES = ['en', 'zh-CN'];

    /** The signed-in user and their linked employee (none for System Admins). */
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->user($request))]);
    }

    /**
     * Display name and personal picture (plan 01 §9.2). The picture is shown only to
     * the user; everyone else sees the first letter of the display name.
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'display_name' => ['sometimes', 'required', 'string', 'max:60'],
            'avatar_file_id' => ['sometimes', 'nullable', 'string'],
        ]);

        $avatarId = $user->avatar_file_id;
        if (array_key_exists('avatar_file_id', $data)) {
            $avatarId = null;
            if ($data['avatar_file_id'] !== null) {
                $file = StoredFile::query()->where('public_id', $data['avatar_file_id'])->first();
                if ($file === null || $file->uploaded_by !== $user->id || $file->purpose !== 'avatar') {
                    throw ApiProblem::unprocessable('INVALID_AVATAR', 'Upload the picture as "avatar" first, then use its file ID.');
                }
                if (! $file->isScanClean()) {
                    throw ApiProblem::conflict('FILE_NOT_READY', 'The picture is still being checked. Try again in a moment.');
                }
                $avatarId = $file->id;
            }
        }

        DB::transaction(function () use ($user, $data, $avatarId) {
            $before = ['display_name' => $user->display_name, 'avatar_file_id' => $user->avatar_file_id];
            if (array_key_exists('display_name', $data)) {
                $user->display_name = trim($data['display_name']);
            }
            $user->avatar_file_id = $avatarId;
            if ($user->isDirty(['display_name', 'avatar_file_id'])) {
                $user->save();
                app(AuditLogger::class)->record('user.profile_changed', $user, [
                    'before' => $before,
                    'after' => ['display_name' => $user->display_name, 'avatar_file_id' => $user->avatar_file_id],
                ]);
            }
        });

        return response()->json(['data' => $this->present($user->fresh() ?? $user)]);
    }

    /**
     * What the user may do, for building the navigation. The server checks every
     * request again; this only decides what the app shows.
     */
    public function permissions(Request $request, PermissionMatrix $matrix, ScopeResolver $scopes): JsonResponse
    {
        $user = $this->user($request);
        $scope = $scopes->for($user);

        return response()->json(['data' => [
            'permissions' => $matrix->permissionsFor($user->roleKey()),
            'scope' => [
                'all' => $scope->all,
                'has_reports' => $scope->hasReports,
                'leads_teams' => $scope->leadsTeams,
                'heads_department' => $scope->headsDepartment,
            ],
        ]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(self::LOCALES)],
        ]);

        $user = $this->user($request);

        DB::transaction(function () use ($user, $data) {
            $before = $user->locale;
            $user->locale = $data['locale'];
            if ($user->isDirty('locale')) {
                $user->save();
                app(AuditLogger::class)->record('user.preferences_changed', $user, [
                    'before' => ['locale' => $before], 'after' => ['locale' => $user->locale],
                ]);
            }
        });

        return response()->json(['data' => ['locale' => $user->locale]]);
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        $user->loadMissing(['role', 'employee.department']);
        $settings = CompanySettings::current();
        $avatar = $user->avatar_file_id ? StoredFile::query()->find($user->avatar_file_id) : null;

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'display_name' => $user->displayName(),
            'email' => $user->email,
            'avatar_file_id' => $avatar?->public_id,
            'locale' => $user->locale,
            'role' => ['key' => $user->role->key, 'name' => $user->role->name],
            'employee' => $user->employee ? [
                'id' => $user->employee->public_id,
                'employee_no' => $user->employee->employee_no,
                'full_name' => $user->employee->full_name,
                'department' => $user->employee->department?->name,
            ] : null,
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'enforced' => $user->twoFactorEnforced($settings),
                'setup_required' => $user->mustSetUpTwoFactor($settings),
            ],
            'session_timeout_minutes' => $settings->session_timeout_minutes,
        ];
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }
}
