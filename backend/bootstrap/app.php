<?php

use App\Exceptions\ProblemDetailsRenderer;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\IdempotencyKey;
use App\Http\Middleware\RequireIfMatch;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api/v1',
        then: function () {
            // Outside /api/v1 and without session or CSRF middleware, so load
            // balancers and uptime monitors can call them.
            Route::middleware('throttle:health')->group(function () {
                Route::get('/healthz', [HealthController::class, 'live']);
                Route::get('/readyz', [HealthController::class, 'ready']);
            });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(AssignRequestId::class);
        $middleware->throttleApi();
        $middleware->alias([
            'idempotent' => IdempotencyKey::class,
            'if-match' => RequireIfMatch::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => ProblemDetailsRenderer::shouldRender($request),
        );

        $exceptions->render(function (Throwable $e, Request $request) {
            return ProblemDetailsRenderer::shouldRender($request)
                ? app(ProblemDetailsRenderer::class)->render($e, $request)
                : null;
        });
    })->create();
