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
    // A connection to a port nothing listens on, made the default for this request only.
    config(['database.connections.unreachable' => array_merge(config('database.connections.mariadb'), [
        'port' => 1, 'database' => 'secret_db_name', 'options' => [PDO::ATTR_TIMEOUT => 1],
    ])]);
    DB::setDefaultConnection('unreachable');

    try {
        $response = $this->get('/readyz');
    } finally {
        DB::setDefaultConnection('mariadb');
    }

    $response->assertStatus(503)->assertJsonPath('checks.database', 'fail')->assertJsonPath('checks.cache', 'ok');
    expect($response->getContent())->not->toContain('secret_db_name')->not->toContain('SQLSTATE');
});

it('no longer serves the default /up route', function () {
    $this->get('/up')->assertNotFound();
});
