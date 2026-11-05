<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Throwable;

/**
 * Health checks for monitoring and deploys. Public, so they return only
 * pass/fail per dependency, never versions, hostnames or error text.
 */
class HealthController extends Controller
{
    /** Liveness: PHP is serving requests. */
    public function live(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }

    /** Readiness: the app can reach everything it needs to serve traffic. */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check('database', fn () => DB::select('SELECT 1')),
            'cache' => $this->check('cache', function () {
                $key = 'readyz:'.Str::random(12);
                Cache::put($key, 1, 10);
                $ok = Cache::get($key) === 1;
                Cache::forget($key);

                return $ok;
            }),
            'queue' => $this->check('queue', fn () => Queue::connection()->size()),
        ];

        $ready = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ready ? 'ok' : 'unavailable',
            'checks' => array_map(fn (bool $ok) => $ok ? 'ok' : 'fail', $checks),
        ], $ready ? 200 : 503);
    }

    private function check(string $name, callable $probe): bool
    {
        try {
            return $probe() !== false;
        } catch (Throwable $e) {
            Log::warning("Readiness check failed: {$name}", ['exception' => $e->getMessage()]);

            return false;
        }
    }
}
