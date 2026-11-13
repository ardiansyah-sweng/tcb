<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** GET /recommendations?limit= (1–10, default 3). */
class RecommendationIndexRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 3;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:10'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $message = 'Batas rekomendasi harus bilangan bulat 1 sampai 10.';

        return [
            'limit.integer' => $message,
            'limit.min' => $message,
            'limit.max' => $message,
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated('limit') ?? self::DEFAULT_LIMIT);
    }
}
