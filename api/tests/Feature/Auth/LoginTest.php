<?php

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;

// R1-03: login with email + password returns a Sanctum token; logout revokes it.

beforeEach(function () {
    $this->user = User::factory()->create([
        'email' => 'dosen@example.com',
        'password' => 'rahasia-panjang',
    ]);
});

function login(array $body = []): TestResponse
{
    return test()->postJson('/api/v1/auth/login', $body + [
        'email' => 'dosen@example.com',
        'password' => 'rahasia-panjang',
    ]);
}

it('returns a bearer token and the user profile', function () {
    $response = login()->assertOk()
        ->assertJsonStructure(['token', 'token_type', 'user' => ['id', 'name', 'email', 'timezone', 'wip_limit', 'score_weights']])
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('user.id', $this->user->id)
        ->assertJsonMissingPath('user.password');

    freshRequest();
    $this->withToken($response->json('token'))->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('email', 'dosen@example.com');
});

it('names the token after device_name, defaulting to web', function () {
    login()->assertOk();
    login(['device_name' => 'Laptop kantor'])->assertOk();

    expect($this->user->tokens()->orderBy('id')->pluck('name')->all())->toBe(['web', 'Laptop kantor']);
});

it('issues tokens that expire after 30 days', function () {
    $token = login()->json('token');

    $this->travel(29)->days();
    freshRequest();
    $this->withToken($token)->getJson('/api/v1/me')->assertOk();

    $this->travel(2)->days();
    freshRequest();
    $this->withToken($token)->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
});

it('rejects a wrong password or unknown email with the same Indonesian message', function (array $body) {
    login($body)
        ->assertUnprocessable()
        ->assertJsonPath('message', 'Email atau kata sandi salah.')
        ->assertJsonPath('errors.email.0', 'Email atau kata sandi salah.');

    expect(PersonalAccessToken::count())->toBe(0);
})->with([
    'wrong password' => [['password' => 'salah']],
    'unknown email' => [['email' => 'orang@example.com']],
]);

it('requires email and password', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Email wajib diisi.')
        ->assertJsonPath('errors.password.0', 'Kata sandi wajib diisi.');
});

it('throttles repeated failed attempts with 429', function () {
    foreach (range(1, 5) as $_) {
        login(['password' => 'salah'])->assertUnprocessable();
    }

    login()
        ->assertStatus(429)
        ->assertJsonPath('code', 'too_many_attempts')
        ->assertJson(fn ($json) => $json->where('message', fn ($m) => str_starts_with($m, 'Terlalu banyak percobaan masuk.'))->etc());
});

it('clears the failed-attempt counter after a successful login', function () {
    foreach (range(1, 4) as $_) {
        login(['password' => 'salah']);
    }
    login()->assertOk();

    foreach (range(1, 4) as $_) {
        login(['password' => 'salah'])->assertUnprocessable();
    }
});

it('revokes only the current token on logout', function () {
    $phone = login(['device_name' => 'Ponsel'])->json('token');
    $laptop = login(['device_name' => 'Laptop'])->json('token');

    freshRequest();
    $this->withToken($laptop)->postJson('/api/v1/auth/logout')->assertNoContent();

    freshRequest();
    $this->withToken($laptop)->getJson('/api/v1/me')->assertUnauthorized();

    freshRequest();
    $this->withToken($phone)->getJson('/api/v1/me')->assertOk();
});

it('has no public registration endpoint', function (string $uri) {
    $this->postJson($uri, ['name' => 'X', 'email' => 'x@example.com', 'password' => 'rahasia-panjang'])
        ->assertNotFound();

    expect(User::where('email', 'x@example.com')->exists())->toBeFalse();
})->with(['/api/v1/auth/register', '/api/v1/register', '/api/v1/users']);
