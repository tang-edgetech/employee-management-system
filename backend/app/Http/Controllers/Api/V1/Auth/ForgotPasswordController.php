<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

/**
 * Sends a reset link to active accounts. Always answers 202 with the same body,
 * whether or not the email has an account, so it can't be used to discover
 * accounts (plan 01 §4). Fortify's version answers 422 for unknown emails.
 */
class ForgotPasswordController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'string', 'email', 'max:190']]);

        Password::broker(config('fortify.passwords'))->sendResetLink([
            'email' => mb_strtolower(trim((string) $request->input('email'))),
            'status' => User::STATUS_ACTIVE,
        ]);

        return response()->json([
            'message' => 'If that email belongs to an active account, a reset link is on its way.',
        ], 202);
    }
}
