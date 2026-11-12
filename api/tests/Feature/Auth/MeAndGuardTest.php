<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

// R1-03: GET /me returns profile and settings; every endpoint other than
// login and health returns 401 without a token.

it('returns the profile with default settings', function () {
    $user = User::factory()->create(['name' => 'Dosen', 'email' => 'dosen@example.com']);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/me')
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id,
            'name' => 'Dosen',
            'email' => 'dosen@example.com',
            'timezone' => 'Asia/Jakarta',
            'wip_limit' => 1,
            'score_weights' => ['goal' => 0.3, 'urgency' => 0.25, 'priority' => 0.2, 'aging' => 0.15, 'fit' => 0.1],
            'work_hours' => [
                'mon' => ['08:00', '17:00'], 'tue' => ['08:00', '17:00'], 'wed' => ['08:00', '17:00'],
                'thu' => ['08:00', '17:00'], 'fri' => ['08:00', '17:00'],
            ],
            'default_deadline_offset_minutes' => 1440,
        ]);
});

it('returns 401 with an Indonesian message without a token', function () {
    $this->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertExactJson(['code' => 'unauthenticated', 'message' => 'Silakan masuk terlebih dahulu.']);
});

it('returns 401 JSON even when the client does not ask for JSON', function () {
    $this->get('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('returns 401 for an invalid token', function () {
    $this->withToken('1|bukan-token-yang-sah')->getJson('/api/v1/me')->assertUnauthorized();
});

it('protects every API route except login and health', function () {
    $public = ['api/v1/health', 'api/v1/auth/login'];

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->reject(fn ($route) => in_array($route->uri(), $public, true));

    expect($routes)->not->toBeEmpty();

    foreach ($routes as $route) {
        $method = collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->first();
        $uri = '/'.preg_replace('/\{[^}]+\}/', '1', $route->uri());

        $this->json($method, $uri)->assertUnauthorized();
    }
});

it('keeps login and health public', function () {
    $this->getJson('/api/v1/health')->assertOk();
    $this->postJson('/api/v1/auth/login', [])->assertUnprocessable();
});
