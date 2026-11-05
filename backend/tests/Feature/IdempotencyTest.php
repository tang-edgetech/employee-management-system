<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->writes = 0;
    $test = $this;

    Route::middleware(['api', 'idempotent'])->post('/api/v1/_test/things', function (Request $r) use ($test) {
        $test->writes++;

        return response()->json(['id' => 'thing_'.$test->writes, 'name' => $r->input('name')], 201);
    });
    Route::middleware(['api', 'idempotent:required'])->post('/api/v1/_test/strict', fn () => response()->noContent());
});

it('replays the original response for a retried request instead of writing twice', function () {
    $headers = ['Idempotency-Key' => 'create-thing-0001'];

    $first = $this->postJson('/api/v1/_test/things', ['name' => 'A'], $headers)->assertCreated();
    $second = $this->postJson('/api/v1/_test/things', ['name' => 'A'], $headers)->assertCreated();

    expect($this->writes)->toBe(1)
        ->and($second->json())->toBe($first->json())
        ->and($second->headers->get('Idempotent-Replayed'))->toBe('true');
});

it('treats different keys as different requests', function () {
    $this->postJson('/api/v1/_test/things', ['name' => 'A'], ['Idempotency-Key' => 'key-number-one']);
    $this->postJson('/api/v1/_test/things', ['name' => 'A'], ['Idempotency-Key' => 'key-number-two']);

    expect($this->writes)->toBe(2);
});

it('rejects a reused key with a different body', function () {
    $headers = ['Idempotency-Key' => 'create-thing-0002'];
    $this->postJson('/api/v1/_test/things', ['name' => 'A'], $headers);

    $this->postJson('/api/v1/_test/things', ['name' => 'B'], $headers)
        ->assertStatus(422)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');

    expect($this->writes)->toBe(1);
});

it('returns 409 while the first request with the key is still running', function () {
    $headers = ['Idempotency-Key' => 'create-thing-0003'];
    $lock = Cache::lock('idempotency:'.hash('sha256', implode('|', ['ip:127.0.0.1', 'POST', 'api/v1/_test/things', 'create-thing-0003'])).':lock', 30);
    $lock->get();

    $this->postJson('/api/v1/_test/things', ['name' => 'A'], $headers)
        ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_IN_PROGRESS');

    $lock->release();
    expect($this->writes)->toBe(0);
});

it('works without a key when the key is optional', function () {
    $this->postJson('/api/v1/_test/things', ['name' => 'A'])->assertCreated();
    $this->postJson('/api/v1/_test/things', ['name' => 'A'])->assertCreated();

    expect($this->writes)->toBe(2);
});

it('requires a key where the route says so, and validates its format', function () {
    $this->postJson('/api/v1/_test/strict')->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED');
    $this->postJson('/api/v1/_test/strict', [], ['Idempotency-Key' => 'short'])
        ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
    $this->postJson('/api/v1/_test/strict', [], ['Idempotency-Key' => 'long-enough-key'])->assertNoContent();
});
