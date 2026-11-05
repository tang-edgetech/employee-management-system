<?php

use Illuminate\Support\Facades\DB;

it('reports liveness without touching dependencies', function () {
    $this->get('/healthz')->assertOk()->assertExactJson(['status' => 'ok']);
});

it('reports readiness of the database, cache and queue', function () {
    $this->get('/readyz')->assertOk()->assertExactJson([
        'status' => 'ok',
        'checks' => ['database' => 'ok', 'cache' => 'ok', 'queue' => 'ok'],
    ]);
});

it('returns 503 and names the failing dependency, without error details', function () {
    DB::shouldReceive('select')->andThrow(new RuntimeException('SQLSTATE[HY000] secret host'));

    $response = $this->get('/readyz');

    $response->assertStatus(503)->assertJsonPath('checks.database', 'fail');
    expect($response->getContent())->not->toContain('secret host');
});

it('no longer serves the default /up route', function () {
    $this->get('/up')->assertNotFound();
});
