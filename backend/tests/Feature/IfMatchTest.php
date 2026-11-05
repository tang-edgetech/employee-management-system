<?php

use App\Domain\Shared\Concurrency\ETag;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware(['api', 'if-match'])->patch('/api/v1/_test/versioned', function (Request $r) {
        ETag::assertMatches(ETag::expectedVersion($r), 3);

        return response()->json(['ok' => true])->setEtag('4');
    });
});

it('requires If-Match on updates (428)', function () {
    $this->patchJson('/api/v1/_test/versioned')
        ->assertStatus(428)->assertJsonPath('code', 'PRECONDITION_REQUIRED');
});

it('refuses a stale version (412)', function () {
    $this->patchJson('/api/v1/_test/versioned', [], ['If-Match' => '"2"'])
        ->assertStatus(412)->assertJsonPath('code', 'PRECONDITION_FAILED');
});

it('refuses an ETag it did not issue (412)', function () {
    $this->patchJson('/api/v1/_test/versioned', [], ['If-Match' => '*'])
        ->assertStatus(412)->assertJsonPath('code', 'PRECONDITION_FAILED');
});

it('accepts the current version, strong or weak', function (string $etag) {
    $this->patchJson('/api/v1/_test/versioned', [], ['If-Match' => $etag])->assertOk();
})->with(['"3"', 'W/"3"']);

it('formats ETags from versions', function () {
    expect(ETag::for(12))->toBe('"12"');
});
