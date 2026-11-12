<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Schema TaskUpdate in docs/tcb_openapi.yaml. Every field is optional, but
 * title, priority, and energy cannot be emptied.
 *
 * `state` is refused: a state change must go through the transition endpoint
 * so the state machine, WIP limit, and context rules run (CLAUDE.md rule 1).
 */
class UpdateTaskRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_map(
            fn (array $fieldRules) => ['sometimes', ...$fieldRules],
            StoreTaskRequest::fieldRules() + ['next_step' => ['nullable', 'string']],
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return StoreTaskRequest::MESSAGES;
    }

    /**
     * The `prohibited` rule lets an empty value through, but any `state` key,
     * even null, signals a client trying to change status here.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($this->exists('state')) {
                    /** @var Task $task */
                    $task = $this->route('task');
                    $validator->errors()->add(
                        'state',
                        "Status tidak bisa diubah di sini. Gunakan POST /tasks/{$task->id}/transitions.",
                    );
                }
            },
        ];
    }
}
