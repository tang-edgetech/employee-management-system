<?php

namespace App\Providers;

use App\Actions\Fortify\ResetUserPassword;
use App\Actions\Fortify\UpdateUserPassword;
use App\Domain\Auth\CredentialsCheck;
use App\Domain\Auth\EnsureLoginIsNotLocked;
use App\Domain\Auth\RequireTwoFactorChallenge;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Routes are registered in routes/api.php under /api/v1/auth.
        Fortify::ignoreRoutes();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Fortify::updateUserPasswordsUsing(UpdateUserPassword::class);
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);

        Fortify::authenticateUsing(fn (Request $request) => app(CredentialsCheck::class)($request));

        Fortify::authenticateThrough(fn () => [
            EnsureLoginIsNotLocked::class,
            config('fortify.lowercase_usernames') ? CanonicalizeUsername::class : null,
            // Our own step: the code can come from any of up to two devices (plan 01 §9.2).
            RequireTwoFactorChallenge::class,
            AttemptToAuthenticate::class,
            // Regenerates the session ID, so a session fixed before login is useless.
            PrepareAuthenticatedSession::class,
        ]);

        Event::listen(Login::class, function (Login $event) {
            if ($event->user instanceof User) {
                // Query update: not an audited change and must not bump the version.
                User::query()->whereKey($event->user->getKey())->update(['last_login_at' => now()]);
            }
        });

        // Per IP, on top of the per-email lockout. Generous enough for an office
        // where everyone signs in from one public IP at 9 am.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(20)->by($request->ip()));

        RateLimiter::for('two-factor', fn (Request $request) => Limit::perMinute(5)
            ->by($request->session()->get('login.id') ?? $request->ip()));

        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(5)->by('email:'.mb_strtolower((string) $request->input('email'))),
        ]);
    }
}
