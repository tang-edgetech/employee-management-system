<?php

use App\Domain\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

it('assigns a ULID request ID to every response', function () {
    $id = $this->get('/healthz')->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('keeps a well-formed incoming request ID', function () {
    $this->get('/healthz', ['X-Request-Id' => '01JC8Z3Y4QK7M2N5P6R8S9T0VW'])
        ->assertHeader('X-Request-Id', '01JC8Z3Y4QK7M2N5P6R8S9T0VW');
});

it('replaces a malformed incoming request ID', function () {
    $id = $this->get('/healthz', ['X-Request-Id' => "evil\nheader"])->headers->get('X-Request-Id');

    expect($id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');
});

it('stamps the request ID on audit rows written during the request', function () {
    Route::middleware('api')->post('/api/v1/_test/audit', function () {
        DB::transaction(fn () => app(AuditLogger::class)->record('test.performed'));

        return response()->noContent();
    });

    $id = $this->postJson('/api/v1/_test/audit')->assertNoContent()->headers->get('X-Request-Id');

    expect(DB::table('audit_log')->where('action', 'test.performed')->value('request_id'))->toBe($id);
});
