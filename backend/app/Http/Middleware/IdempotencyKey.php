<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response as IlluminateResponse;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes retried POSTs safe: a request repeated with the same Idempotency-Key
 * gets the original response replayed instead of writing twice.
 *
 * Usage: ->middleware('idempotent') (key optional) or 'idempotent:required'.
 * Keys are scoped to the caller and route. Reusing a key with a different body
 * is rejected; a duplicate sent while the first is still running gets 409.
 * Only responses below 500 are stored, so a server error can be retried.
 */
class IdempotencyKey
{
    public const HEADER = 'Idempotency-Key';

    public function handle(Request $request, Closure $next, string $mode = 'optional'): Response
    {
        $key = $request->headers->get(self::HEADER);

        if ($key === null || $key === '') {
            if ($mode === 'required') {
                throw ApiProblem::badRequest('IDEMPOTENCY_KEY_REQUIRED', 'Send an Idempotency-Key header with this request.');
            }

            return $next($request);
        }

        if (! preg_match('/^[\x21-\x7E]{8,255}$/', $key)) {
            throw ApiProblem::badRequest('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key must be 8-255 printable ASCII characters.');
        }

        $caller = $request->user()?->getAuthIdentifier() ?? 'ip:'.$request->ip();
        $cacheKey = 'idempotency:'.hash('sha256', implode('|', [$caller, $request->method(), $request->path(), $key]));
        $fingerprint = hash('sha256', $request->getContent());

        $stored = Cache::get($cacheKey);
        if (is_array($stored)) {
            return $this->replay($stored, $fingerprint);
        }

        $lock = Cache::lock($cacheKey.':lock', (int) config('meridian.idempotency.lock_seconds', 30));
        if (! $lock->get()) {
            throw ApiProblem::conflict('IDEMPOTENCY_IN_PROGRESS', 'A request with this Idempotency-Key is still being processed.');
        }

        try {
            // Another request may have finished between the first check and taking the lock.
            $stored = Cache::get($cacheKey);
            if (is_array($stored)) {
                return $this->replay($stored, $fingerprint);
            }

            $response = $next($request);

            if ($response->getStatusCode() < 500) {
                Cache::put($cacheKey, [
                    'fingerprint' => $fingerprint,
                    'status' => $response->getStatusCode(),
                    'headers' => array_intersect_key(
                        $response->headers->all(),
                        array_flip(['content-type', 'location', 'etag']),
                    ),
                    'body' => $response->getContent(),
                ], now()->addHours((int) config('meridian.idempotency.ttl_hours', 24)));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{fingerprint: string, status: int, headers: array<string, list<string|null>>, body: string}  $stored
     */
    private function replay(array $stored, string $fingerprint): Response
    {
        if (! hash_equals($stored['fingerprint'], $fingerprint)) {
            throw ApiProblem::unprocessable('IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used with a different request body.');
        }

        $response = new IlluminateResponse($stored['body'], $stored['status'], $stored['headers']);
        $response->headers->set('Idempotent-Replayed', 'true');

        return $response;
    }
}
