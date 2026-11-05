<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Domain\Audit\AuditLogger;
use App\Domain\Auth\TwoFactorDevice;
use App\Domain\Auth\TwoFactorDevices;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

/**
 * The signed-in user's authenticator devices (up to two) and recovery codes
 * (plan 01 §9.2). Every route needs a recently confirmed password.
 */
class TwoFactorController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);

        return response()->json(['data' => [
            'enabled' => $user->hasTwoFactorEnabled(),
            'enforced' => $user->twoFactorEnforced(),
            'max_devices' => TwoFactorDevice::MAX_PER_USER,
            'devices' => $user->twoFactorDevices()->whereNotNull('confirmed_at')->orderBy('confirmed_at')->get()->map(fn (TwoFactorDevice $d) => [
                'id' => $d->public_id,
                'name' => $d->name,
                'added_at' => $d->confirmed_at?->toIso8601String(),
                'last_used_at' => $d->last_used_at?->toIso8601String(),
            ]),
        ]]);
    }

    public function store(Request $request, TwoFactorDevices $devices): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:60']]);
        $result = $devices->begin($this->user($request), $data['name']);

        return response()->json(['data' => [
            'id' => $result['device']->public_id,
            'name' => $result['device']->name,
            'secret' => $result['secret'],
            'otpauth_url' => $result['otpauth_url'],
            'qr_svg' => $result['qr_svg'],
        ]], 201);
    }

    public function confirm(Request $request, TwoFactorDevice $device, TwoFactorDevices $devices): JsonResponse
    {
        $request->validate(['code' => ['required', 'string', 'digits:6']]);
        $codes = $devices->confirm($this->user($request), $device, (string) $request->input('code'));

        return response()->json(['data' => ['id' => $device->public_id, 'recovery_codes' => $codes]]);
    }

    public function destroy(Request $request, TwoFactorDevice $device, TwoFactorDevices $devices): Response
    {
        $devices->remove($this->user($request), $device);

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
