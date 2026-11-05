<?php

namespace App\Domain\Files;

use App\Exceptions\ApiProblem;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

/**
 * Upload flow (plan 01 §4, F6):
 *  1. presign: the app says what it will upload; we check purpose, size and type,
 *     create a pending row and return a short-lived upload URL.
 *  2. the browser sends the bytes straight to storage (S3 in production; a signed
 *     Laravel route on the local disk).
 *  3. complete: we check the stored bytes (size, sha256, real type by magic bytes),
 *     then queue the virus scan and photo re-encode. Until the scan says clean,
 *     the file can't be downloaded or used.
 */
final class FileService
{
    public function __construct(private readonly MimeSniffer $sniffer) {}

    /**
     * @return array{file: StoredFile, upload: array{method: string, url: string, headers: array<string, string>, expires_at: string}}
     */
    public function presign(User $user, string $purpose, string $filename, int $size, string $declaredMime): array
    {
        $rules = config("meridian.files.purposes.{$purpose}");
        if (! is_array($rules)) {
            throw ApiProblem::unprocessable('UNKNOWN_FILE_PURPOSE', "Unknown file purpose [{$purpose}].");
        }
        if ($rules['permission'] !== null && ! $user->can($rules['permission'])) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Forbidden', "Uploading a {$purpose} needs: {$rules['permission']}.");
        }
        if ($size > $rules['max_bytes']) {
            throw new ApiProblem(413, 'FILE_TOO_LARGE', 'File too large', 'The limit for a '.$purpose.' is '.self::megabytes($rules['max_bytes']).'.');
        }
        if (! in_array($declaredMime, $rules['mimes'], true)) {
            throw new ApiProblem(415, 'FILE_TYPE_NOT_ALLOWED', 'File type not allowed', 'Allowed for a '.$purpose.': '.implode(', ', $rules['mimes']).'.');
        }

        $file = StoredFile::query()->create([
            'purpose' => $purpose,
            'disk' => (string) config('meridian.files.disk'),
            // No part of the user's file name goes into the storage key.
            'storage_key' => $purpose.'/'.now()->format('Y/m').'/'.Str::ulid()->toBase32(),
            'original_name' => self::cleanName($filename),
            'declared_mime' => $declaredMime,
            'size_bytes' => $size,
            'scan_status' => StoredFile::PENDING,
            'uploaded_by' => $user->id,
        ]);

        $expires = now()->addMinutes((int) config('meridian.files.upload_url_minutes', 10));

        return ['file' => $file, 'upload' => $this->uploadTarget($file, $expires)];
    }

    /**
     * Local disk only: receives the bytes sent to the signed upload URL, refusing
     * more than the size declared at presign.
     *
     * @param  resource  $body
     */
    public function receive(StoredFile $file, $body): void
    {
        if ($file->isUploaded()) {
            throw ApiProblem::conflict('FILE_ALREADY_UPLOADED', 'This file has already been uploaded.');
        }

        $temp = tmpfile();
        $written = 0;
        while (! feof($body)) {
            $chunk = (string) fread($body, 65536);
            $written += strlen($chunk);
            if ($written > $file->size_bytes) {
                fclose($temp);
                throw new ApiProblem(413, 'FILE_TOO_LARGE', 'File too large', 'More bytes were sent than were declared.');
            }
            fwrite($temp, $chunk);
        }
        rewind($temp);

        $this->disk($file)->writeStream($file->storage_key, $temp);
        fclose($temp);
    }

    /** Verifies the uploaded bytes and queues the scan. */
    public function complete(StoredFile $file, User $user, ?string $expectedSha256): StoredFile
    {
        if ($file->uploaded_by !== $user->id) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Forbidden', 'Only the person who started this upload can complete it.');
        }
        if ($file->isUploaded()) {
            return $file;
        }

        $disk = $this->disk($file);
        if (! $disk->exists($file->storage_key)) {
            throw ApiProblem::conflict('FILE_NOT_UPLOADED', 'Upload the file before completing it.');
        }

        $local = $this->localCopy($file);
        try {
            $size = (int) filesize($local);
            $sha256 = (string) hash_file('sha256', $local);
            $mime = $this->sniffer->sniff($local);
        } finally {
            @unlink($local);
        }

        $problem = match (true) {
            $size !== $file->size_bytes => ApiProblem::unprocessable('FILE_SIZE_MISMATCH', 'The uploaded file is not the size that was declared.'),
            $expectedSha256 !== null && ! hash_equals(strtolower($expectedSha256), $sha256) => ApiProblem::unprocessable('FILE_CHECKSUM_MISMATCH', 'The uploaded file is not the one that was declared (checksum differs).'),
            ! in_array($mime, $file->rules()['mimes'], true) => ApiProblem::unprocessable('FILE_CONTENT_MISMATCH', "The file's content is {$mime}, which isn't allowed for a {$file->purpose}."),
            default => null,
        };

        if ($problem !== null) {
            $disk->delete($file->storage_key);
            $file->delete();

            throw $problem;
        }

        DB::transaction(function () use ($file, $sha256, $mime) {
            $file->forceFill(['sha256' => $sha256, 'mime' => $mime, 'confirmed_at' => now()])->save();
            ProcessUploadedFile::dispatch($file->id);
        });

        return $file;
    }

    /**
     * A short-lived download URL. Only clean files can be downloaded.
     *
     * @return array{url: string, expires_at: string}
     */
    public function downloadUrl(StoredFile $file): array
    {
        if ($file->scan_status === StoredFile::INFECTED) {
            throw ApiProblem::conflict('FILE_INFECTED', 'This file failed the virus scan and was removed.');
        }
        if (! $file->isScanClean()) {
            throw ApiProblem::conflict('FILE_NOT_READY', 'This file is still being checked. Try again in a moment.');
        }

        $expires = now()->addMinutes((int) config('meridian.files.download_url_minutes', 5));
        $disk = $this->disk($file);

        $url = $this->isLocal($file)
            ? URL::temporarySignedRoute('files.download', $expires, ['file' => $file->public_id])
            : $disk->temporaryUrl($file->storage_key, $expires, [
                'ResponseContentDisposition' => 'attachment; filename="'.addslashes($file->original_name).'"',
                'ResponseContentType' => $file->mime,
            ]);

        return ['url' => $url, 'expires_at' => $expires->toIso8601String()];
    }

    public function disk(StoredFile $file): Filesystem
    {
        return Storage::disk($file->disk);
    }

    /** A temporary local path with the file's bytes (downloads from S3 if needed). */
    public function localCopy(StoredFile $file): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'mwf');
        $stream = $this->disk($file)->readStream($file->storage_key);
        $out = fopen($path, 'wb');
        try {
            stream_copy_to_stream($stream, $out);
        } catch (Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            fclose($out);
            is_resource($stream) && fclose($stream);
        }

        return $path;
    }

    private function isLocal(StoredFile $file): bool
    {
        return config("filesystems.disks.{$file->disk}.driver") === 'local';
    }

    /**
     * @return array{method: string, url: string, headers: array<string, string>, expires_at: string}
     */
    private function uploadTarget(StoredFile $file, \DateTimeInterface $expires): array
    {
        if ($this->isLocal($file)) {
            return [
                'method' => 'PUT',
                'url' => URL::temporarySignedRoute('files.upload', $expires, ['file' => $file->public_id]),
                'headers' => ['Content-Type' => $file->declared_mime],
                'expires_at' => $expires->format(DATE_ATOM),
            ];
        }

        /** @var array{url: string, headers: array<string, string|list<string>>} $target */
        $target = $this->disk($file)->temporaryUploadUrl($file->storage_key, $expires, ['ContentType' => $file->declared_mime]);

        return [
            'method' => 'PUT',
            'url' => $target['url'],
            'headers' => array_map(fn ($v) => is_array($v) ? implode(', ', $v) : $v, $target['headers']),
            'expires_at' => $expires->format(DATE_ATOM),
        ];
    }

    private static function cleanName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"]+/u', '', $name);

        return mb_substr(trim($name) !== '' ? trim($name) : 'file', 0, 255);
    }

    private static function megabytes(int $bytes): string
    {
        return round($bytes / 1048576, 1).' MB';
    }
}
