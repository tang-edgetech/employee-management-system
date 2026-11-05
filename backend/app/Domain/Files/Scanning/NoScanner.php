<?php

namespace App\Domain\Files\Scanning;

use RuntimeException;

/**
 * Development only: treats every file as clean, because XAMPP has no ClamAV.
 * Refuses to run in production, where FILE_SCANNER must be "clamav".
 */
final class NoScanner implements Scanner
{
    public function isClean($stream): bool
    {
        if (app()->isProduction()) {
            throw new RuntimeException('FILE_SCANNER=none is not allowed in production; configure ClamAV.');
        }

        return true;
    }
}
