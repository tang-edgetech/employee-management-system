<?php

use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;
use Laravel\Fortify\Http\Controllers\TwoFactorAuthenticatedSessionController;

// Every route here is served under /api/v1 (bootstrap/app.php) and rate limited
// by the "api" limiter. The web app authenticates with Sanctum's session cookie
// and CSRF token. Access roles and settings arrive in M4, files in M5.

// "signed-in": a valid, active, non-idle session.
// "app": additionally blocks users who must set up 2FA first (TWO_FACTOR_SETUP_REQUIRED).
$signedIn = ['auth:sanctum', 'auth.session', 'session.valid'];
$app = [...$signedIn, '2fa.enforced'];

Route::prefix('auth')->group(function () use ($signedIn, $app) {
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::post('two-factor-challenge', [TwoFactorAuthenticatedSessionController::class, 'store'])->middleware('throttle:two-factor');
    Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:password-reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset');

    Route::middleware($signedIn)->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->middleware('throttle:login');

        Route::middleware('password.recent')->prefix('2fa')->group(function () {
            Route::post('setup', [TwoFactorController::class, 'setup']);
            Route::post('confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor');
            Route::delete('/', [TwoFactorController::class, 'disable']);
            Route::get('recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });
    });

    Route::put('password', [PasswordController::class, 'update'])->middleware($app);
});

Route::middleware($signedIn)->group(function () {
    Route::get('me', [MeController::class, 'show']);
});

Route::middleware($app)->group(function () {
    // Module endpoints (M4+) go here.
});
