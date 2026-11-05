<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\ScopeResolver;
use App\Domain\Audit\AuditLogger;
use App\Domain\Settings\CompanySettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    public const LAYOUTS = ['indigo', 'pandan', 'blueprint', 'plum', 'kopi'];

    /** The signed-in user and their linked employee (none for System Admins). */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing(['role', 'employee']);
        $settings = CompanySettings::current();

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['key' => $user->role->key, 'name' => $user->role->name],
            'employee' => $user->employee ? [
                'id' => $user->employee->public_id,
                'employee_no' => $user->employee->employee_no,
                'full_name' => $user->employee->full_name,
            ] : null,
            'ui_layout' => $user->ui_layout,
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'enforced' => $user->twoFactorEnforced($settings),
                'setup_required' => $user->mustSetUpTwoFactor($settings),
            ],
            'session_timeout_minutes' => $settings->session_timeout_minutes,
        ]]);
    }

    /**
     * What the user may do, for building the navigation. The server checks every
     * request again; this only decides what the app shows.
     */
    public function permissions(Request $request, PermissionMatrix $matrix, ScopeResolver $scopes): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $scope = $scopes->for($user);

        return response()->json(['data' => [
            'permissions' => $matrix->permissionsFor($user->roleKey()),
            'scope' => [
                'all' => $scope->all,
                'has_reports' => $scope->hasReports,
                'leads_teams' => $scope->leadsTeams,
            ],
        ]]);
    }

    public function updatePreferences(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ui_layout' => ['required', 'string', Rule::in(self::LAYOUTS)],
        ]);

        /** @var User $user */
        $user = $request->user();

        DB::transaction(function () use ($user, $data) {
            $before = $user->ui_layout;
            $user->ui_layout = $data['ui_layout'];
            if ($user->isDirty('ui_layout')) {
                $user->save();
                app(AuditLogger::class)->record('user.preferences_changed', $user, [
                    'before' => ['ui_layout' => $before], 'after' => ['ui_layout' => $user->ui_layout],
                ]);
            }
        });

        return response()->json(['data' => ['ui_layout' => $user->ui_layout]]);
    }
}
