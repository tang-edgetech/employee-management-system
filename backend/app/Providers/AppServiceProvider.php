<?php

namespace App\Providers;

use App\Domain\Access\Permission;
use App\Domain\Access\PermissionMatrix;
use App\Domain\Access\ScopeResolver;
use App\Domain\Files\Scanning\ClamAvScanner;
use App\Domain\Files\Scanning\NoScanner;
use App\Domain\Files\Scanning\Scanner;
use App\Domain\Shared\Crypto\BlindIndex;
use App\Domain\Shared\Crypto\FieldEncrypter;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FieldEncrypter::class, fn () => new FieldEncrypter(
            FieldEncrypter::parseKeys((string) config('meridian.data_keys')),
        ));

        // Per request: permissions and scope are worked out once and reused.
        $this->app->scoped(PermissionMatrix::class);
        $this->app->scoped(ScopeResolver::class);

        $this->app->bind(Scanner::class, fn () => match (config('meridian.files.scanner')) {
            'clamav' => new ClamAvScanner(
                (string) config('meridian.files.clamav.host'),
                (int) config('meridian.files.clamav.port'),
                (int) config('meridian.files.clamav.timeout'),
            ),
            default => new NoScanner,
        });

        $this->app->singleton(BlindIndex::class, function () {
            $key = (string) config('meridian.blind_index_key');

            return new BlindIndex(str_starts_with($key, 'base64:') ? (string) base64_decode(substr($key, 7), true) : $key);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->registerSchemaMacros();
        $this->registerRateLimiters();

        // Permission keys are Gate abilities: `can:system.settings`, $user->can('people.edit').
        // Deny by default; abilities that aren't permission keys fall through to policies.
        Gate::before(function (User $user, string $ability) {
            return in_array($ability, Permission::values(), true)
                ? app(PermissionMatrix::class)->allows($user, $ability)
                : null;
        });

        // Owner's choice (2026-11-05): 8+ characters, mixed case, a number and a symbol.
        Password::defaults(fn () => Password::min(8)->max(128)->mixedCase()->numbers()->symbols());
    }

    private function registerSchemaMacros(): void
    {
        // Case-sensitive, fixed-width public ID column (ADR 0001 §5.11).
        Blueprint::macro('publicId', function () {
            /** @var Blueprint $this */
            return $this->char('public_id', 16)->charset('ascii')->collation('ascii_bin')->unique();
        });

        // created_at / updated_at as DATETIME(6) in UTC, not TIMESTAMP (2038 overflow).
        Blueprint::macro('datetimes6', function () {
            /** @var Blueprint $this */
            $this->dateTime('created_at', 6)->nullable();
            $this->dateTime('updated_at', 6)->nullable();
        });
    }

    private function registerRateLimiters(): void
    {
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)
            ->by($request->user()?->getAuthIdentifier() ?? $request->ip()));

        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by($request->ip()));
    }
}
