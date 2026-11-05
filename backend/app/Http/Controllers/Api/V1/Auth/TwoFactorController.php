<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * Authenticator-app 2FA for the signed-in user (plan 01 §4). Every route needs a
 * recently confirmed password. Enabling is two steps: setup returns the secret
 * and QR code, confirm proves the app works and returns the recovery codes.
 */
class TwoFactorController extends Controller
{
    public function setup(Request $request, EnableTwoFactorAuthentication $enable): JsonResponse
    {
        $user = $this->user($request);

        if ($user->hasTwoFactorEnabled()) {
            throw ApiProblem::conflict('TWO_FACTOR_ALREADY_ENABLED', 'Two-factor authentication is already on. Turn it off first to set up a new device.');
        }

        // A fresh secret each time, so an abandoned setup can't be completed later with an old QR code.
        $enable($user, force: true);
        $user->refresh();

        return response()->json(['data' => [
            'secret' => decrypt((string) $user->two_factor_secret),
            'otpauth_url' => $user->twoFactorQrCodeUrl(),
            'qr_svg' => $user->twoFactorQrCodeSvg(),
        ]]);
    }

    public function confirm(Request $request, ConfirmTwoFactorAuthentication $confirm): JsonResponse
    {
        $user = $this->user($request);
        $request->validate(['code' => ['required', 'string', 'digits:6']]);

        if ($user->two_factor_secret === null) {
            throw ApiProblem::conflict('TWO_FACTOR_NOT_SET_UP', 'Start the setup first.');
        }
        if ($user->hasTwoFactorEnabled()) {
            throw ApiProblem::conflict('TWO_FACTOR_ALREADY_ENABLED', 'Two-factor authentication is already on.');
        }

        DB::transaction(function () use ($user, $request, $confirm) {
            $confirm($user, (string) $request->input('code'));
            app(AuditLogger::class)->record('user.two_factor_enabled', $user);
        });

        return response()->json(['data' => ['recovery_codes' => $user->fresh()?->recoveryCodes() ?? []]]);
    }

    public function disable(Request $request, DisableTwoFactorAuthentication $disable): Response
    {
        $user = $this->user($request);

        if ($user->twoFactorEnforced()) {
            throw ApiProblem::conflict('TWO_FACTOR_ENFORCED', 'Your role requires two-factor authentication, so it can\'t be turned off.');
        }

        DB::transaction(function () use ($user, $disable) {
            $wasEnabled = $user->hasTwoFactorEnabled();
            $disable($user);
            if ($wasEnabled) {
                app(AuditLogger::class)->record('user.two_factor_disabled', $user);
            }
        });

        return response()->noContent();
    }

    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->enabledUser($request);

        return response()->json(['data' => ['recovery_codes' => $user->recoveryCodes()]]);
    }

    public function regenerateRecoveryCodes(Request $request, GenerateNewRecoveryCodes $generate): JsonResponse
    {
        $user = $this->enabledUser($request);

        DB::transaction(function () use ($user, $generate) {
            $generate($user);
            app(AuditLogger::class)->record('user.two_factor_recovery_codes_regenerated', $user);
        });

        return response()->json(['data' => ['recovery_codes' => $user->fresh()?->recoveryCodes() ?? []]]);
    }

    private function user(Request $request): User
    {
        /** @var User */
        return $request->user();
    }

    private function enabledUser(Request $request): User
    {
        $user = $this->user($request);
        if (! $user->hasTwoFactorEnabled()) {
            throw ApiProblem::conflict('TWO_FACTOR_NOT_ENABLED', 'Two-factor authentication is off.');
        }

        return $user;
    }
}
