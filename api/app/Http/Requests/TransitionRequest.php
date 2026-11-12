<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /tasks/{id}/transitions (schema TransitionRequest).
 *
 * Only the request shape is checked here; whether the transition is allowed
 * and whether it needs a reason are TransitionService's job (409/422 with
 * their own codes). context is accepted as the contract says; R1-10
 * validates and stores it. review_on is only for `to: suspended`.
 */
class TransitionRequest extends FormRequest
{
    public const REVIEW_ON_MESSAGE = 'Tanggal tinjau ulang hanya untuk status Suspended.';

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', 'in:'.implode(',', Task::STATES)],
            'reason' => ['nullable', 'string'],
            'context' => ['nullable', 'array'],
            'review_on' => ['nullable', 'date_format:Y-m-d', 'prohibited_unless:to,suspended'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $states = 'Pilihan: '.implode(', ', Task::STATES).'.';

        return [
            'to.required' => "Status tujuan wajib diisi. {$states}",
            'to.string' => "Status tujuan tidak dikenal. {$states}",
            'to.in' => "Status tujuan tidak dikenal. {$states}",
            'reason.string' => 'Alasan harus berupa teks.',
            'context.array' => 'Konteks harus berupa objek.',
            'review_on.date_format' => 'Tanggal tinjau ulang harus berformat YYYY-MM-DD.',
            'review_on.prohibited_unless' => self::REVIEW_ON_MESSAGE,
        ];
    }
}
