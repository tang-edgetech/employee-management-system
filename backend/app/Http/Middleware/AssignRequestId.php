<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a ULID that appears in logs, audit rows, error bodies and
 * the X-Request-Id response header. A well-formed incoming ID (e.g. from a load
 * balancer) is kept so one request can be traced across services.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = (string) $request->headers->get(self::HEADER, '');
        $id = preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $incoming) ? $incoming : (string) Str::ulid();

        Context::add('request_id', $id);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
