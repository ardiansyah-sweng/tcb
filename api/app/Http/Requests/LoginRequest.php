<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Email tidak valid.',
            'password.required' => 'Kata sandi wajib diisi.',
            'device_name.max' => 'Nama perangkat maksimal 100 karakter.',
        ];
    }

    /**
     * Rate-limit key: one counter per email and IP address.
     */
    public function throttleKey(): string
    {
        return 'login|'.mb_strtolower((string) $this->input('email')).'|'.$this->ip();
    }
}
