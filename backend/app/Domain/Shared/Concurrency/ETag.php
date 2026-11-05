<?php

namespace App\Domain\Shared\Concurrency;

use App\Exceptions\ApiProblem;
use Illuminate\Http\Request;

/**
 * Optimistic concurrency: resources with a `version` column are returned with
 * ETag "<version>", and updates must send it back in If-Match. A mismatch means
 * someone else saved first, so the update is refused with 412.
 */
final class ETag
{
    public static function for(int $version): string
    {
        return '"'.$version.'"';
    }

    /**
     * The version the client last read. Throws 428 when If-Match is missing and
     * 412 when it isn't a version this API issued.
     */
    public static function expectedVersion(Request $request): int
    {
        $header = trim((string) $request->headers->get('If-Match', ''));
        if ($header === '') {
            throw ApiProblem::preconditionRequired();
        }

        if (! preg_match('/^(?:W\/)?"(\d{1,10})"$/', $header, $m)) {
            throw ApiProblem::preconditionFailed('If-Match must be the ETag returned when the resource was read.');
        }

        return (int) $m[1];
    }

    public static function assertMatches(int $expected, int $current): void
    {
        if ($expected !== $current) {
            throw ApiProblem::preconditionFailed();
        }
    }
}
