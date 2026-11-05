<?php

use App\Domain\Files\ProcessUploadedFile;
use App\Domain\Files\Scanning\ScanFailed;
use App\Domain\Files\Scanning\Scanner;
use App\Domain\Files\StoredFile;
use Database\Seeders\DemoCompanySeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Storage::fake('uploads');
    $this->seed(DemoCompanySeeder::class);
});

/** A real PNG of the given size, with a fake EXIF-like comment chunk. */
function pngBytes(int $w = 600, int $h = 400): string
{
    $img = imagecreatetruecolor($w, $h);
    imagefilledrectangle($img, 0, 0, $w, $h, imagecolorallocate($img, 30, 90, 160));
    ob_start();
    imagepng($img);

    return (string) ob_get_clean();
}

function pdfBytes(): string
{
    return "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
}

/** Runs presign + upload as the given account, returns [file public id, response of upload]. */
function startUpload($test, string $account, string $purpose, string $bytes, string $mime, string $name = 'upload.bin'): array
{
    Sanctum::actingAs(demoUser($account));
    $presign = $test->postJson('/api/v1/files/presign', [
        'purpose' => $purpose, 'filename' => $name, 'size' => strlen($bytes), 'mime' => $mime,
    ])->assertCreated();

    $upload = $test->call('PUT', $presign->json('data.upload.url'), [], [], [], ['CONTENT_TYPE' => $mime], $bytes);

    return [$presign->json('data.file.id'), $upload, $presign];
}

it('issues an upload slot within the purpose limits', function () {
    [$id, $upload, $presign] = startUpload($this, 'tang', 'logo', pngBytes(), 'image/png', '../../etc/Company Logo.png');

    $upload->assertNoContent();
    $presign->assertJsonPath('data.file.status', 'awaiting_upload')
        ->assertJsonPath('data.file.name', 'Company Logo.png')
        ->assertJsonPath('data.upload.method', 'PUT');

    $file = StoredFile::query()->where('public_id', $id)->firstOrFail();
    expect($file->storage_key)->toStartWith('logo/')->not->toContain('Logo')
        ->and(Storage::disk('uploads')->exists($file->storage_key))->toBeTrue();
});

it('enforces size, type and purpose permission at presign', function () {
    Sanctum::actingAs(demoUser('tang'));
    $this->postJson('/api/v1/files/presign', ['purpose' => 'photo', 'filename' => 'a.jpg', 'size' => 4 * 1024 * 1024, 'mime' => 'image/jpeg'])
        ->assertStatus(413)->assertJsonPath('code', 'FILE_TOO_LARGE');
    $this->postJson('/api/v1/files/presign', ['purpose' => 'photo', 'filename' => 'a.gif', 'size' => 10, 'mime' => 'image/gif'])
        ->assertStatus(415)->assertJsonPath('code', 'FILE_TYPE_NOT_ALLOWED');
    $this->postJson('/api/v1/files/presign', ['purpose' => 'avatar', 'filename' => 'a.jpg', 'size' => 10, 'mime' => 'image/jpeg'])
        ->assertStatus(422);

    Sanctum::actingAs(demoUser('hafiz'));
    $this->postJson('/api/v1/files/presign', ['purpose' => 'logo', 'filename' => 'a.png', 'size' => 10, 'mime' => 'image/png'])
        ->assertStatus(403);
    $this->postJson('/api/v1/files/presign', ['purpose' => 'evidence', 'filename' => 'a.pdf', 'size' => 10, 'mime' => 'application/pdf'])
        ->assertCreated();
});

it('refuses uploads with a bad or expired signature, and extra bytes', function () {
    Sanctum::actingAs(demoUser('tang'));
    $presign = $this->postJson('/api/v1/files/presign', ['purpose' => 'evidence', 'filename' => 'a.pdf', 'size' => 10, 'mime' => 'application/pdf']);
    $url = $presign->json('data.upload.url');

    $this->call('PUT', $url.'x', [], [], [], [], '0123456789')->assertStatus(403);
    $this->call('PUT', $url, [], [], [], [], str_repeat('x', 11))->assertStatus(413)->assertJsonPath('code', 'FILE_TOO_LARGE');

    $this->travel(11)->minutes();
    $this->call('PUT', $url, [], [], [], [], '0123456789')->assertStatus(403);
});

it('rejects a file whose content is not what it claims (PDF sent as PNG)', function () {
    $pdf = pdfBytes();
    [$id] = startUpload($this, 'tang', 'logo', $pdf, 'image/png', 'logo.png');

    $this->postJson("/api/v1/files/{$id}/complete")
        ->assertStatus(422)->assertJsonPath('code', 'FILE_CONTENT_MISMATCH');

    expect(StoredFile::query()->where('public_id', $id)->exists())->toBeFalse()
        ->and(Storage::disk('uploads')->allFiles())->toBeEmpty();
});

it('rejects a checksum that does not match', function () {
    [$id] = startUpload($this, 'hafiz', 'evidence', pdfBytes(), 'application/pdf');

    $this->postJson("/api/v1/files/{$id}/complete", ['sha256' => str_repeat('a', 64)])
        ->assertStatus(422)->assertJsonPath('code', 'FILE_CHECKSUM_MISMATCH');
});

it('lets only the uploader complete, and only after the bytes arrive', function () {
    Sanctum::actingAs(demoUser('hafiz'));
    $presign = $this->postJson('/api/v1/files/presign', ['purpose' => 'evidence', 'filename' => 'a.pdf', 'size' => 10, 'mime' => 'application/pdf']);
    $id = $presign->json('data.file.id');

    $this->postJson("/api/v1/files/{$id}/complete")->assertStatus(409)->assertJsonPath('code', 'FILE_NOT_UPLOADED');

    Sanctum::actingAs(demoUser('nadia'));
    $this->postJson("/api/v1/files/{$id}/complete")->assertStatus(403);
});

it('queues the scan after completing and keeps the file unusable until clean', function () {
    Queue::fake();
    $pdf = pdfBytes();
    [$id] = startUpload($this, 'hafiz', 'evidence', $pdf, 'application/pdf');

    $this->postJson("/api/v1/files/{$id}/complete", ['sha256' => hash('sha256', $pdf)])
        ->assertOk()->assertJsonPath('data.status', 'pending');

    Queue::assertPushed(ProcessUploadedFile::class);
    $this->getJson("/api/v1/files/{$id}/url")->assertStatus(409)->assertJsonPath('code', 'FILE_NOT_READY');
});

it('re-encodes photos to a 240x240 WebP without metadata', function () {
    [$id] = startUpload($this, 'nurul', 'photo', pngBytes(800, 500), 'image/png', 'me.png');

    $this->postJson("/api/v1/files/{$id}/complete")->assertOk();

    $file = StoredFile::query()->where('public_id', $id)->firstOrFail();
    $bytes = Storage::disk('uploads')->get($file->storage_key);
    [$w, $h] = getimagesizefromstring($bytes);

    expect($file->scan_status)->toBe('clean')
        ->and($file->mime)->toBe('image/webp')
        ->and([$w, $h])->toBe([240, 240])
        ->and($file->sha256)->toBe(hash('sha256', $bytes))
        ->and(substr($bytes, 8, 4))->toBe('WEBP');
});

it('serves a clean file through a short-lived signed URL with safe headers', function () {
    $pdf = pdfBytes();
    [$id] = startUpload($this, 'hafiz', 'evidence', $pdf, 'application/pdf', 'proof.pdf');
    $this->postJson("/api/v1/files/{$id}/complete")->assertOk();

    $url = $this->getJson("/api/v1/files/{$id}/url")->assertOk()->json('data.url');
    $download = $this->get($url)->assertOk();

    expect($download->streamedContent())->toBe($pdf);
    $download->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Content-Security-Policy', "default-src 'none'; sandbox");
    expect($download->headers->get('Content-Disposition'))->toStartWith('attachment');

    $this->travel(6)->minutes();
    $this->get($url)->assertStatus(403);
});

it('does not let other users see someone else\'s file, but everyone sees the logo', function () {
    [$evidence] = startUpload($this, 'hafiz', 'evidence', pdfBytes(), 'application/pdf');
    $this->postJson("/api/v1/files/{$evidence}/complete");
    [$logo] = startUpload($this, 'tang', 'logo', pngBytes(), 'image/png');
    $this->postJson("/api/v1/files/{$logo}/complete");

    Sanctum::actingAs(demoUser('nadia'));
    $this->getJson("/api/v1/files/{$evidence}/url")->assertStatus(403);
    $this->getJson("/api/v1/files/{$logo}/url")->assertOk();
});

it('removes infected files and refuses to serve them', function () {
    app()->bind(Scanner::class, fn () => new class implements Scanner
    {
        public function isClean($stream): bool
        {
            return false;
        }
    });
    [$id] = startUpload($this, 'hafiz', 'evidence', pdfBytes(), 'application/pdf');

    $this->postJson("/api/v1/files/{$id}/complete")->assertOk();

    $file = StoredFile::query()->where('public_id', $id)->firstOrFail();
    expect($file->scan_status)->toBe('infected')
        ->and(Storage::disk('uploads')->exists($file->storage_key))->toBeFalse();
    $this->getJson("/api/v1/files/{$id}/url")->assertStatus(409)->assertJsonPath('code', 'FILE_INFECTED');
});

it('keeps the file pending when the scanner cannot answer', function () {
    app()->bind(Scanner::class, fn () => new class implements Scanner
    {
        public function isClean($stream): bool
        {
            throw new ScanFailed('clamd down');
        }
    });
    [$id] = startUpload($this, 'hafiz', 'evidence', pdfBytes(), 'application/pdf');

    $this->postJson("/api/v1/files/{$id}/complete")->assertOk();

    expect(StoredFile::query()->where('public_id', $id)->value('scan_status'))->toBe('pending');
});

it('stores the same content twice but links it by checksum', function () {
    $pdf = pdfBytes();
    [$a] = startUpload($this, 'hafiz', 'evidence', $pdf, 'application/pdf');
    $this->postJson("/api/v1/files/{$a}/complete");
    [$b] = startUpload($this, 'hafiz', 'evidence', $pdf, 'application/pdf');
    $this->postJson("/api/v1/files/{$b}/complete");

    expect(StoredFile::query()->where('sha256', hash('sha256', $pdf))->count())->toBe(2);
});

it('prunes uploads that were never completed', function () {
    Sanctum::actingAs(demoUser('hafiz'));
    $this->postJson('/api/v1/files/presign', ['purpose' => 'evidence', 'filename' => 'a.pdf', 'size' => 10, 'mime' => 'application/pdf']);
    [$done] = startUpload($this, 'hafiz', 'evidence', pdfBytes(), 'application/pdf');
    $this->postJson("/api/v1/files/{$done}/complete");

    $this->travel(25)->hours();
    $this->artisan('files:prune-abandoned')->assertSuccessful();

    expect(StoredFile::query()->pluck('public_id')->all())->toBe([$done]);
});
