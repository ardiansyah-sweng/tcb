<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** POST /recommendations/{id}/decision. */
class RecommendationDecisionRequest extends FormRequest
{
    public const DECISIONS = ['picked', 'skipped', 'scheduled'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', 'in:'.implode(',', self::DECISIONS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $unknown = 'Keputusan harus picked, skipped, atau scheduled.';

        return [
            'decision.required' => 'Keputusan wajib diisi.',
            'decision.string' => $unknown,
            'decision.in' => $unknown,
        ];
    }
}
