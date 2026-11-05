<?php

namespace App\Http\Middleware;

use App\Domain\Shared\Concurrency\ETag;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rejects updates that don't say which version they were based on (428),
 * before any work is done. The controller compares the version itself, inside
 * the transaction that locks the row (see ETag).
 */
class RequireIfMatch
{
    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['PUT', 'PATCH', 'DELETE'], true)) {
            ETag::expectedVersion($request);
        }

        return $next($request);
    }
}
