<?php

use App\Exceptions\ApiProblem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::middleware('api')->prefix('api/v1/_test')->group(function () {
        Route::post('/validate', fn (Request $r) => $r->validate(['name' => 'required|string']));
        Route::get('/conflict', fn () => throw ApiProblem::conflict('ROLE_LOCKED', 'This role cannot be edited.'));
        Route::get('/boom', fn () => throw new RuntimeException('database password is hunter2'));
        Route::get('/private', fn () => abort(403));
    });
});

function assertProblemShape($response, int $status, string $code): void
{
    $response->assertStatus($status)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('status', $status)
        ->assertJsonPath('code', $code)
        ->assertJsonPath('type', 'urn:meridian:problem:'.strtolower($code))
        ->assertJsonStructure(['type', 'title', 'status', 'code', 'instance', 'request_id']);

    expect($response->json('request_id'))->toBe($response->headers->get('X-Request-Id'));
}

it('renders validation errors with field messages', function () {
    $response = $this->postJson('/api/v1/_test/validate', []);

    assertProblemShape($response, 422, 'VALIDATION_FAILED');
    $response->assertJsonPath('errors.name.0', 'The name field is required.');
});

it('renders unknown routes as 404 without internal details', function () {
    $response = $this->getJson('/api/v1/nothing-here');

    assertProblemShape($response, 404, 'NOT_FOUND');
    $response->assertJsonPath('instance', '/api/v1/nothing-here');
});

it('renders a wrong method as 405', function () {
    assertProblemShape($this->deleteJson('/api/v1/_test/validate'), 405, 'METHOD_NOT_ALLOWED');
});

it('renders forbidden as 403', function () {
    assertProblemShape($this->getJson('/api/v1/_test/private'), 403, 'FORBIDDEN');
});

it('renders deliberate API problems with their own code', function () {
    $response = $this->getJson('/api/v1/_test/conflict');

    assertProblemShape($response, 409, 'ROLE_LOCKED');
    $response->assertJsonPath('detail', 'This role cannot be edited.');
});

it('hides the message of unexpected errors when debug is off', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_test/boom');

    assertProblemShape($response, 500, 'SERVER_ERROR');
    expect($response->getContent())->not->toContain('hunter2')
        ->and($response->json())->not->toHaveKey('debug');
});

it('rate limits the API with a 429 problem and Retry-After', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->getJson('/api/v1/_test/private');
    }

    $response = $this->getJson('/api/v1/_test/private');

    assertProblemShape($response, 429, 'RATE_LIMITED');
    $response->assertHeader('Retry-After');
});
