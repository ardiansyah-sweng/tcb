<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

// R1-03: accounts are created only with `php artisan tcb:create-user`.

it('creates a user with a hashed password from prompts', function () {
    $this->artisan('tcb:create-user')
        ->expectsQuestion('Nama', 'Dosen TIF')
        ->expectsQuestion('Email', 'dosen@example.com')
        ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia-panjang')
        ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang')
        ->expectsOutputToContain('Akun dosen@example.com dibuat.')
        ->assertSuccessful();

    $user = User::where('email', 'dosen@example.com')->firstOrFail();
    expect($user->name)->toBe('Dosen TIF');
    expect($user->password)->not->toBe('rahasia-panjang');
    expect(Hash::check('rahasia-panjang', $user->password))->toBeTrue();
    expect($user->fresh()->wip_limit)->toBe(1);
    expect($user->fresh()->timezone)->toBe('Asia/Jakarta');
});

it('accepts name and email as options', function () {
    $this->artisan('tcb:create-user', ['--name' => 'Dosen', '--email' => 'dosen@example.com'])
        ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia-panjang')
        ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang')
        ->assertSuccessful();

    expect(User::where('email', 'dosen@example.com')->exists())->toBeTrue();
});

it('lets the new account log in', function () {
    $this->artisan('tcb:create-user', ['--name' => 'Dosen', '--email' => 'dosen@example.com'])
        ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia-panjang')
        ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang')
        ->assertSuccessful();

    $this->postJson('/api/v1/auth/login', ['email' => 'dosen@example.com', 'password' => 'rahasia-panjang'])
        ->assertOk();
});

it('refuses a duplicate email', function () {
    User::factory()->create(['email' => 'dosen@example.com']);

    $this->artisan('tcb:create-user', ['--name' => 'Dosen', '--email' => 'dosen@example.com'])
        ->expectsQuestion('Kata sandi (min. 8 karakter)', 'rahasia-panjang')
        ->expectsQuestion('Ulangi kata sandi', 'rahasia-panjang')
        ->expectsOutputToContain('Email sudah dipakai.')
        ->assertFailed();

    expect(User::count())->toBe(1);
});

it('refuses an invalid email, a short password, or a mismatched confirmation', function (string $email, string $password, string $confirmation, string $message) {
    $this->artisan('tcb:create-user', ['--name' => 'Dosen', '--email' => $email])
        ->expectsQuestion('Kata sandi (min. 8 karakter)', $password)
        ->expectsQuestion('Ulangi kata sandi', $confirmation)
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(User::count())->toBe(0);
})->with([
    'invalid email' => ['bukan-email', 'rahasia-panjang', 'rahasia-panjang', 'Email tidak valid.'],
    'short password' => ['dosen@example.com', 'pendek', 'pendek', 'Kata sandi minimal 8 karakter.'],
    'mismatch' => ['dosen@example.com', 'rahasia-panjang', 'rahasia-lain', 'Konfirmasi kata sandi tidak sama.'],
]);
