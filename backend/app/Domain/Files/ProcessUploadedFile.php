<?php

namespace App\Domain\Files;

use App\Domain\Files\Scanning\ScanFailed;
use App\Domain\Files\Scanning\Scanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * Virus-scans a verified upload and, for photos and logos, re-encodes it (which
 * strips EXIF and any hidden payload). Infected files are deleted from storage
 * and marked infected. If the scanner can't answer, the job retries and the file
 * stays pending (unusable) meanwhile.
 */
class ProcessUploadedFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $fileId) {}

    public function handle(FileService $files, Scanner $scanner, ImageNormaliser $images): void
    {
        $file = StoredFile::query()->find($this->fileId);
        if ($file === null || ! $file->isUploaded() || $file->scan_status !== StoredFile::PENDING) {
            return;
        }

        $disk = $files->disk($file);
        $stream = $disk->readStream($file->storage_key);
        try {
            $clean = $scanner->isClean($stream);
        } catch (ScanFailed $e) {
            Log::warning('Virus scan failed; will retry', ['file' => $file->public_id, 'error' => $e->getMessage()]);
            $this->release($this->backoff[min($this->attempts() - 1, count($this->backoff) - 1)]);

            return;
        } finally {
            is_resource($stream) && fclose($stream);
        }

        if (! $clean) {
            $disk->delete($file->storage_key);
            $file->forceFill(['scan_status' => StoredFile::INFECTED, 'scanned_at' => now()])->save();
            Log::warning('Infected upload removed', ['file' => $file->public_id, 'purpose' => $file->purpose, 'uploaded_by' => $file->uploaded_by]);

            return;
        }

        $normalise = $file->rules()['normalise'];
        if ($normalise !== null) {
            $local = $files->localCopy($file);
            try {
                $encoded = $images->normalise($local, $normalise);
            } finally {
                @unlink($local);
            }
            $disk->put($file->storage_key, $encoded);
            $file->forceFill([
                'mime' => 'image/webp',
                'size_bytes' => strlen($encoded),
                'sha256' => hash('sha256', $encoded),
            ]);
        }

        $file->forceFill(['scan_status' => StoredFile::CLEAN, 'scanned_at' => now()])->save();
    }
}
