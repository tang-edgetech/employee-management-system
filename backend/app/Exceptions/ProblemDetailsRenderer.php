<?php

namespace App\Exceptions;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every API error as RFC 9457 Problem Details (application/problem+json)
 * with a stable `code` and the request ID, so clients can branch on `code`
 * and support can find the matching log lines.
 */
final class ProblemDetailsRenderer
{
    /** Codes for plain HTTP errors raised by the framework. */
    private const CODES = [
        400 => ['BAD_REQUEST', 'Bad request'],
        401 => ['UNAUTHENTICATED', 'Unauthenticated'],
        403 => ['FORBIDDEN', 'Forbidden'],
        404 => ['NOT_FOUND', 'Not found'],
        405 => ['METHOD_NOT_ALLOWED', 'Method not allowed'],
        409 => ['CONFLICT', 'Conflict'],
        413 => ['PAYLOAD_TOO_LARGE', 'Payload too large'],
        415 => ['UNSUPPORTED_MEDIA_TYPE', 'Unsupported media type'],
        419 => ['CSRF_TOKEN_MISMATCH', 'Session expired'],
        429 => ['RATE_LIMITED', 'Too many requests'],
        503 => ['SERVICE_UNAVAILABLE', 'Service unavailable'],
    ];

    public static function shouldRender(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public function render(Throwable $e, Request $request): JsonResponse
    {
        [$status, $code, $title, $detail, $extra, $headers] = $this->describe($e);

        $body = array_merge([
            'type' => 'urn:meridian:problem:'.strtolower($code),
            'title' => $title,
            'status' => $status,
            'code' => $code,
            'detail' => $detail,
            'instance' => '/'.ltrim($request->path(), '/'),
            'request_id' => Context::get('request_id'),
        ], $extra);

        if ($status >= 500 && config('app.debug')) {
            $body['debug'] = ['exception' => $e::class, 'message' => $e->getMessage()];
        }

        return new JsonResponse(array_filter($body, fn ($v) => $v !== null), $status, array_merge($headers, [
            'Content-Type' => 'application/problem+json',
        ]));
    }

    /**
     * @return array{int, string, string, ?string, array<string, mixed>, array<string, string>}
     */
    private function describe(Throwable $e): array
    {
        if ($e instanceof ApiProblem) {
            return [$e->status, $e->problemCode, $e->title, $e->detail, $e->extensions, $e->headers];
        }

        if ($e instanceof ValidationException) {
            return [422, 'VALIDATION_FAILED', 'Validation failed', 'One or more fields are invalid.', ['errors' => $e->errors()], []];
        }

        if ($e instanceof AuthenticationException) {
            return [401, 'UNAUTHENTICATED', 'Unauthenticated', 'Sign in to continue.', [], []];
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();
            [$code, $title] = self::CODES[$status] ?? ['HTTP_'.$status, 'Error'];
            /** @var array<string, string> $headers */
            $headers = $e->getHeaders();

            // Framework messages for 4xx are safe to show (e.g. "Too Many Attempts.");
            // "No query results for model [App\..]" is not, so 404 gets a generic detail.
            $detail = match (true) {
                $status === 404 => 'The requested resource does not exist.',
                $status === 419 => 'Your session expired. Reload the page and try again.',
                $e->getMessage() !== '' => $e->getMessage(),
                default => null,
            };

            return [$status, $code, $title, $detail, [], $headers];
        }

        return [500, 'SERVER_ERROR', 'Server error', 'Something went wrong. Quote the request ID if you contact support.', [], []];
    }
}
