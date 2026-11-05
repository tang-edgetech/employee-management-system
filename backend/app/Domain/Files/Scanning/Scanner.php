<?php

namespace App\Domain\Files\Scanning;

/** Virus scanner: returns true if clean, false if infected; throws if it can't tell. */
interface Scanner
{
    /**
     * @param  resource  $stream
     *
     * @throws ScanFailed
     */
    public function isClean($stream): bool;
}
