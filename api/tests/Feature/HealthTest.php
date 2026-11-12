<?php

use Illuminate\Support\Facades\DB;

// R1-01: GET /api/v1/health returns {"status":"ok"} plus database connectivity.

it('runs the test suite against PostgreSQL, not SQLite', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('returns ok with a reachable database', function () {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertExactJson(['status' => 'ok', 'database' => 'ok']);
});

it('does not require an auth token', function () {
    $this->getJson('/api/v1/health')->assertOk();
});

it('returns 503 with database error when the database is unreachable', function () {
    $default = config('database.default');
    config(["database.connections.{$default}.host" => '127.0.0.1']);
    config(["database.connections.{$default}.port" => 1]);
    DB::purge($default);

    $this->getJson('/api/v1/health')
        ->assertStatus(503)
        ->assertExactJson(['status' => 'ok', 'database' => 'error']);
});
