<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A deliberate API error with a stable machine-readable code, rendered as
 * RFC 9457 Problem Details by ProblemDetailsRenderer.
 */
class ApiProblem extends RuntimeException
{
    /**
     * @param  array<string, mixed>  $extensions  extra members merged into the problem body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $problemCode,
        public readonly string $title,
        public readonly ?string $detail = null,
        public readonly array $extensions = [],
        public readonly array $headers = [],
    ) {
        parent::__construct($detail ?? $title);
    }

    public static function conflict(string $code, string $detail): self
    {
        return new self(409, $code, 'Conflict', $detail);
    }

    public static function preconditionFailed(string $detail = 'The resource was changed by someone else. Reload it and try again.'): self
    {
        return new self(412, 'PRECONDITION_FAILED', 'Precondition failed', $detail);
    }

    public static function preconditionRequired(): self
    {
        return new self(428, 'PRECONDITION_REQUIRED', 'Precondition required', 'Send the If-Match header with the ETag you last read.');
    }

    public static function badRequest(string $code, string $detail): self
    {
        return new self(400, $code, 'Bad request', $detail);
    }

    public static function unprocessable(string $code, string $detail): self
    {
        return new self(422, $code, 'Unprocessable content', $detail);
    }
}
