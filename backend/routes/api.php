<?php

use App\Http\Controllers\Api\V1\AccessRoleController;
use App\Http\Controllers\Api\V1\Auth\ForgotPasswordController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorChallengeController;
use App\Http\Controllers\Api\V1\Auth\TwoFactorController;
use App\Http\Controllers\Api\V1\DesignationController;
use App\Http\Controllers\Api\V1\EmployeeNumberController;
use App\Http\Controllers\Api\V1\EmploymentTypeController;
use App\Http\Controllers\Api\V1\FileController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TaskTagController;
use App\Http\Controllers\Api\V1\VersionController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;
use Laravel\Fortify\Http\Controllers\ConfirmablePasswordController;
use Laravel\Fortify\Http\Controllers\NewPasswordController;
use Laravel\Fortify\Http\Controllers\PasswordController;

// Every route here is served under /api/v1 (bootstrap/app.php) and rate limited
// by the "api" limiter. The web app authenticates with Sanctum's session cookie
// and CSRF token.

// "signed-in": a valid, active, non-idle session.
// "app": additionally blocks users who must set up 2FA first (TWO_FACTOR_SETUP_REQUIRED).
$signedIn = ['auth:sanctum', 'auth.session', 'session.valid'];
$app = [...$signedIn, '2fa.enforced'];

Route::prefix('auth')->group(function () use ($signedIn, $app) {
    Route::post('login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::post('two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:two-factor');
    Route::post('forgot-password', ForgotPasswordController::class)->middleware('throttle:password-reset');
    Route::post('reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:password-reset');

    Route::middleware($signedIn)->group(function () {
        Route::post('logout', [AuthenticatedSessionController::class, 'destroy']);
        Route::post('confirm-password', [ConfirmablePasswordController::class, 'store'])->middleware('throttle:login');

        Route::middleware('password.recent')->prefix('2fa')->group(function () {
            Route::get('devices', [TwoFactorController::class, 'index']);
            Route::post('devices', [TwoFactorController::class, 'store']);
            Route::post('devices/{device}/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor');
            Route::delete('devices/{device}', [TwoFactorController::class, 'destroy']);
            Route::get('recovery-codes', [TwoFactorController::class, 'recoveryCodes']);
            Route::post('recovery-codes', [TwoFactorController::class, 'regenerateRecoveryCodes']);
        });
    });

    Route::put('password', [PasswordController::class, 'update'])->middleware($app);
});

Route::middleware($signedIn)->group(function () {
    Route::get('me', [MeController::class, 'show']);
    Route::get('version', VersionController::class);
});

Route::middleware($app)->group(function () {
    Route::get('me/permissions', [MeController::class, 'permissions']);
    Route::patch('me', [MeController::class, 'update']);
    Route::patch('me/preferences', [MeController::class, 'updatePreferences']);

    Route::get('settings', [SettingsController::class, 'show']);
    Route::patch('settings', [SettingsController::class, 'update'])->middleware('if-match');

    Route::post('files/presign', [FileController::class, 'presign']);
    Route::post('files/{file}/complete', [FileController::class, 'complete']);
    Route::get('files/{file}', [FileController::class, 'show']);
    Route::get('files/{file}/url', [FileController::class, 'url']);

    // Catalogues (plan 01 §9.4). Everyone in the app reads them; editing needs the permission.
    Route::get('designations', [DesignationController::class, 'index']);
    Route::get('employment-types', [EmploymentTypeController::class, 'index']);
    Route::get('task-tags', [TaskTagController::class, 'index']);

    Route::middleware('can:org.structure.manage')->group(function () {
        Route::post('designations', [DesignationController::class, 'store']);
        Route::put('designations/order', [DesignationController::class, 'reorder'])->middleware('if-match');
        Route::patch('designations/{designation}', [DesignationController::class, 'update'])->middleware('if-match');
        Route::delete('designations/{designation}', [DesignationController::class, 'destroy'])->middleware('if-match');

        Route::post('employment-types', [EmploymentTypeController::class, 'store']);
        Route::patch('employment-types/{employmentType}', [EmploymentTypeController::class, 'update'])->middleware('if-match');
        Route::delete('employment-types/{employmentType}', [EmploymentTypeController::class, 'destroy'])->middleware('if-match');
    });

    Route::middleware('can:org.task_tags.manage')->group(function () {
        Route::post('task-tags', [TaskTagController::class, 'store']);
        Route::patch('task-tags/{taskTag}', [TaskTagController::class, 'update'])->middleware('if-match');
        Route::delete('task-tags/{taskTag}', [TaskTagController::class, 'destroy'])->middleware('if-match');
    });

    Route::get('employee-numbers', [EmployeeNumberController::class, 'preview'])->middleware('can:people.edit');

    Route::middleware('can:system.settings')->group(function () {
        Route::post('employee-numbers/renumber', [EmployeeNumberController::class, 'renumber'])->middleware('if-match');
        Route::get('access-roles', [AccessRoleController::class, 'index']);
        Route::put('access-roles/{role:key}/permissions', [AccessRoleController::class, 'updatePermissions'])->middleware('if-match');
    });
});

// Local disk only (production uses S3 presigned URLs): the signed URL is the
// authorisation, so these sit outside the signed-in group and skip CSRF.
Route::middleware('signed')->group(function () {
    Route::put('files/{file}/content', [FileController::class, 'upload'])->name('files.upload');
    Route::get('files/{file}/download', [FileController::class, 'download'])->name('files.download');
});
