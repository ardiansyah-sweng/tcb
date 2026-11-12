<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

/**
 * The only way to create an account; there is no public registration (R1-03).
 * The password is always asked interactively so it never lands in shell history.
 */
class CreateUser extends Command
{
    protected $signature = 'tcb:create-user
        {--name= : Nama pengguna}
        {--email= : Email untuk login}';

    protected $description = 'Membuat akun TCB (tidak ada registrasi publik)';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('Nama');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = (string) $this->secret('Kata sandi (min. 8 karakter)');
        $confirmation = (string) $this->secret('Ulangi kata sandi');

        $validator = Validator::make(
            compact('name', 'email', 'password') + ['password_confirmation' => $confirmation],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ],
            [
                'name.required' => 'Nama wajib diisi.',
                'email.required' => 'Email wajib diisi.',
                'email.email' => 'Email tidak valid.',
                'email.unique' => 'Email sudah dipakai.',
                'password.required' => 'Kata sandi wajib diisi.',
                'password.min' => 'Kata sandi minimal 8 karakter.',
                'password.confirmed' => 'Konfirmasi kata sandi tidak sama.',
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        // The 'hashed' cast on User hashes the password.
        User::create(['name' => $name, 'email' => $email, 'password' => $password]);

        $this->info("Akun {$email} dibuat.");

        return self::SUCCESS;
    }
}
