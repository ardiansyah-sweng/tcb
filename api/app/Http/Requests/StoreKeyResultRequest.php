<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schema KeyResultInput in docs/tcb_openapi.yaml. Only the title is required;
 * weight defaults to 1 and progress_mode to auto (database defaults).
 */
class StoreKeyResultRequest extends FormRequest
{
    /** Shared with UpdateKeyResultRequest. */
    public const MESSAGES = [
        'title.required' => 'Judul wajib diisi.',
        'title.string' => 'Judul harus berupa teks.',
        'weight.required' => 'Bobot wajib diisi.',
        'weight.numeric' => 'Bobot harus berupa angka.',
        'weight.gt' => 'Bobot harus lebih dari 0.',
        'weight.max' => 'Bobot paling besar 1.',
        'progress_mode.required' => 'Mode progres wajib diisi.',
        'progress_mode.in' => 'Mode progres harus auto atau manual.',
        'start_value.numeric' => 'Nilai awal harus berupa angka.',
        'target_value.numeric' => 'Target harus berupa angka.',
        'target_value.required_if' => self::TARGET_REQUIRED,
        'current_value.numeric' => 'Nilai saat ini harus berupa angka.',
        'unit.string' => 'Satuan harus berupa teks.',
    ];

    public const TARGET_REQUIRED = 'Target wajib diisi bila mode progres manual.';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'weight' => ['bail', 'sometimes', 'required', 'numeric', 'gt:0', 'max:1'],
            'progress_mode' => ['sometimes', 'required', 'in:auto,manual'],
            'start_value' => ['nullable', 'numeric'],
            'target_value' => ['nullable', 'numeric', 'required_if:progress_mode,manual'],
            'current_value' => ['nullable', 'numeric'],
            'unit' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::MESSAGES;
    }
}
