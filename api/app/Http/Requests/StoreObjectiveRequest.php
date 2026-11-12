<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Schema ObjectiveInput in docs/tcb_openapi.yaml.
 */
class StoreObjectiveRequest extends FormRequest
{
    /** Shared with UpdateObjectiveRequest. */
    public const MESSAGES = [
        'title.required' => 'Judul wajib diisi.',
        'title.string' => 'Judul harus berupa teks.',
        'description.string' => 'Deskripsi harus berupa teks.',
        'period_start.required' => 'Periode mulai wajib diisi.',
        'period_start.date_format' => 'Periode mulai harus berupa tanggal dengan format YYYY-MM-DD.',
        'period_end.required' => 'Periode akhir wajib diisi.',
        'period_end.date_format' => 'Periode akhir harus berupa tanggal dengan format YYYY-MM-DD.',
        'period_end.after_or_equal' => self::PERIOD_END_BEFORE_START,
        'status.in' => 'Status harus salah satu dari: active, closed, archived.',
    ];

    public const PERIOD_END_BEFORE_START = 'Periode akhir tidak boleh sebelum periode mulai.';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'period_start' => ['bail', 'required', 'date_format:Y-m-d'],
            'period_end' => ['bail', 'required', 'date_format:Y-m-d', 'after_or_equal:period_start'],
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
