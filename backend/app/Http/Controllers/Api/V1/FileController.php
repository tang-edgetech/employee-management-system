<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Files\FileService;
use App\Domain\Files\StoredFile;
use App\Exceptions\ApiProblem;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileController extends Controller
{
    public function presign(Request $request, FileService $files): JsonResponse
    {
        $data = $request->validate([
            'purpose' => ['required', 'string', Rule::in(array_keys(config('meridian.files.purposes')))],
            'filename' => ['required', 'string', 'max:255'],
            'size' => ['required', 'integer', 'min:1'],
            'mime' => ['required', 'string', 'max:127'],
        ]);

        /** @var User $user */
        $user = $request->user();
        $result = $files->presign($user, $data['purpose'], $data['filename'], (int) $data['size'], $data['mime']);

        return response()->json(['data' => [
            'file' => $this->present($result['file']),
            'upload' => $result['upload'],
        ]], 201);
    }

    /** Local disk only: the signed upload URL from presign points here. The signature is the authorisation. */
    public function upload(Request $request, StoredFile $file, FileService $files): Response
    {
        $body = $request->getContent(asResource: true);
        $files->receive($file, $body);

        return response()->noContent();
    }

    public function complete(Request $request, StoredFile $file, FileService $files): JsonResponse
    {
        $data = $request->validate(['sha256' => ['nullable', 'string', 'regex:/^[0-9a-fA-F]{64}$/']]);

        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->present($files->complete($file, $user, $data['sha256'] ?? null))]);
    }

    public function show(Request $request, StoredFile $file): JsonResponse
    {
        $this->authorizeView($request, $file);

        return response()->json(['data' => $this->present($file)]);
    }

    public function url(Request $request, StoredFile $file, FileService $files): JsonResponse
    {
        $this->authorizeView($request, $file);

        return response()->json(['data' => $files->downloadUrl($file)]);
    }

    /**
     * Local disk only: serves a clean file to a signed, short-lived URL. Sent as a
     * download-safe response: real type, no sniffing, sandboxed, not cached publicly.
     */
    public function download(StoredFile $file, FileService $files): StreamedResponse
    {
        if (! $file->isScanClean()) {
            throw ApiProblem::conflict('FILE_NOT_READY', 'This file is not available.');
        }

        $inline = str_starts_with((string) $file->mime, 'image/');

        return $files->disk($file)->response($file->storage_key, $file->original_name, [
            'Content-Type' => $file->mime,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, max-age=300',
        ], $inline ? 'inline' : 'attachment');
    }

    /**
     * Foundation rule: the uploader, admins with Global Settings, and anyone for the
     * company logo. Each module widens this for its own files (e.g. HR for resumes).
     */
    private function authorizeView(Request $request, StoredFile $file): void
    {
        /** @var User $user */
        $user = $request->user();

        // A personal picture is shown only to its owner (plan 01 §9.2), even to admins.
        $allowed = $file->purpose === 'avatar'
            ? $file->uploaded_by === $user->id
            : $file->uploaded_by === $user->id || $file->purpose === 'logo' || $user->can('system.settings');

        if (! $allowed) {
            throw new ApiProblem(403, 'FORBIDDEN', 'Forbidden', 'You can\'t view this file.');
        }
    }

    /** @return array<string, mixed> */
    private function present(StoredFile $file): array
    {
        return [
            'id' => $file->public_id,
            'purpose' => $file->purpose,
            'name' => $file->original_name,
            'mime' => $file->mime ?? $file->declared_mime,
            'size' => $file->size_bytes,
            'status' => match (true) {
                ! $file->isUploaded() => 'awaiting_upload',
                default => $file->scan_status,
            },
        ];
    }
}
