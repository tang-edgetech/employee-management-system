<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Settings\CompanySettings;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MeController extends Controller
{
    /** The signed-in user. Linked employee and permissions arrive in M4. */
    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->loadMissing('role');
        $settings = CompanySettings::current();

        return response()->json(['data' => [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => ['key' => $user->role->key, 'name' => $user->role->name],
            'ui_layout' => $user->ui_layout,
            'two_factor' => [
                'enabled' => $user->hasTwoFactorEnabled(),
                'enforced' => $user->twoFactorEnforced($settings),
                'setup_required' => $user->mustSetUpTwoFactor($settings),
            ],
            'session_timeout_minutes' => $settings->session_timeout_minutes,
        ]]);
    }
}
