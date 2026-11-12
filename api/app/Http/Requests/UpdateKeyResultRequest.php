<?php

namespace App\Http\Requests;

use App\Models\KeyResult;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Schema KeyResultUpdate in docs/tcb_openapi.yaml. Every field is optional,
 * but title, weight, and progress_mode cannot be emptied.
 */
class UpdateKeyResultRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string'],
            'weight' => ['bail', 'sometimes', 'required', 'numeric', 'gt:0', 'max:1'],
            'progress_mode' => ['sometimes', 'required', 'in:auto,manual'],
            'start_value' => ['sometimes', 'nullable', 'numeric'],
            'target_value' => ['sometimes', 'nullable', 'numeric'],
            'current_value' => ['sometimes', 'nullable', 'numeric'],
            'unit' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreKeyResultRequest::MESSAGES;
    }

    /**
     * A manual key result needs a target. A partial update is checked against
     * the stored values, so switching to manual or clearing the target cannot
     * leave a manual key result without one.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->hasAny(['progress_mode', 'target_value'])) {
                    return;
                }

                /** @var KeyResult $keyResult */
                $keyResult = $this->route('key_result');
                $mode = $this->has('progress_mode') ? $this->input('progress_mode') : $keyResult->progress_mode;
                $target = $this->has('target_value') ? $this->input('target_value') : $keyResult->target_value;

                if ($mode === 'manual' && $target === null) {
                    $validator->errors()->add('target_value', StoreKeyResultRequest::TARGET_REQUIRED);
                }
            },
        ];
    }
}
