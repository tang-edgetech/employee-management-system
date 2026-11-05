<?php

namespace App\Domain\Files\Scanning;

/**
 * Streams a file to clamd with the INSTREAM command (no temp copy on the scanner
 * side). Answers "stream: OK" when clean and "stream: <name> FOUND" when infected.
 */
final class ClamAvScanner implements Scanner
{
    private const CHUNK = 8192;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout,
    ) {}

    public function isClean($stream): bool
    {
        $socket = @fsockopen($this->host, $this->port, $errno, $error, $this->timeout);
        if ($socket === false) {
            throw new ScanFailed("Cannot reach ClamAV at {$this->host}:{$this->port} ({$error}).");
        }

        try {
            stream_set_timeout($socket, $this->timeout);
            fwrite($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK);
                if ($chunk === '') {
                    break;
                }
                fwrite($socket, pack('N', strlen($chunk)).$chunk);
            }
            fwrite($socket, pack('N', 0));

            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        return match (true) {
            str_ends_with($reply, 'OK') => true,
            str_ends_with($reply, 'FOUND') => false,
            default => throw new ScanFailed("Unexpected ClamAV reply: {$reply}"),
        };
    }
}
