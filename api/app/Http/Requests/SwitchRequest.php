<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesContextInput;
use App\Models\Recommendation;
use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * POST /switch (schema SwitchRequest).
 *
 * Checks the request shape and resolves the two tasks (numeric ID or
 * task_key, owner scope applied) and the recommendation. Whether the
 * transitions are allowed, and whether a reason or context is missing, is
 * TransitionService's job (409/422 with their own codes), as for
 * POST /tasks/{id}/transitions. from_to defaults to ready.
 */
class SwitchRequest extends FormRequest
{
    use ValidatesContextInput;

    public const FROM_STATES = ['ready', 'waiting', 'suspended'];

    private ?Task $fromTask = null;

    private ?Task $toTask = null;

    private ?Recommendation $recommendation = null;

    /** The contract types the IDs as strings; accept a bare number too. */
    protected function prepareForValidation(): void
    {
        foreach (['from_task_id', 'to_task_id'] as $field) {
            if (is_int($this->input($field))) {
                $this->merge([$field => (string) $this->input($field)]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from_task_id' => ['required', 'string'],
            'from_to' => ['nullable', 'string', 'in:'.implode(',', self::FROM_STATES)],
            'reason' => ['nullable', 'string'],
            'context' => ['nullable', 'array'],
            'to_task_id' => ['required', 'string'],
            'recommendation_id' => ['nullable', 'integer'],
        ] + (is_array($this->input('context')) ? self::contextRules('context.') : []);
    }

    /**
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            $this->resolveTasks($validator);
            $this->resolveRecommendation($validator);

            if ($this->fromTask !== null && is_array($this->input('context'))) {
                $this->contextUnitCheck('context.', $this->fromTask)($validator);
            }
        }];
    }

    private function resolveTasks(Validator $validator): void
    {
        $errors = $validator->errors();

        if (! $errors->has('from_task_id')) {
            $this->fromTask = $this->findTask('from_task_id');
            if ($this->fromTask === null) {
                $errors->add('from_task_id', 'Task asal tidak ditemukan.');
            }
        }

        if ($errors->has('to_task_id')) {
            return;
        }

        $this->toTask = $this->findTask('to_task_id');
        if ($this->toTask === null) {
            $errors->add('to_task_id', 'Task tujuan tidak ditemukan.');
        } elseif ($this->fromTask !== null && $this->toTask->is($this->fromTask)) {
            $errors->add('to_task_id', 'Task tujuan harus berbeda dari task asal.');
        }
    }

    /** The recommendation must be the owner's and point at the target task. */
    private function resolveRecommendation(Validator $validator): void
    {
        $errors = $validator->errors();

        if ($errors->has('recommendation_id') || $this->input('recommendation_id') === null) {
            return;
        }

        $this->recommendation = Recommendation::find((int) $this->input('recommendation_id'));
        if ($this->recommendation === null) {
            $errors->add('recommendation_id', 'Rekomendasi tidak ditemukan.');
        } elseif ($this->toTask !== null && $this->recommendation->task_id !== $this->toTask->id) {
            $errors->add('recommendation_id', 'Rekomendasi ini bukan untuk task tujuan.');
        }
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'from_task_id.required' => 'Task asal (yang sedang Running) wajib diisi.',
            'from_task_id.string' => 'Task asal tidak ditemukan.',
            'to_task_id.required' => 'Task tujuan wajib diisi.',
            'to_task_id.string' => 'Task tujuan tidak ditemukan.',
            'from_to.string' => 'Status task asal tidak dikenal. Pilihan: '.implode(', ', self::FROM_STATES).'.',
            'from_to.in' => 'Status task asal tidak dikenal. Pilihan: '.implode(', ', self::FROM_STATES).'.',
            'reason.string' => 'Alasan harus berupa teks.',
            'context.array' => 'Konteks harus berupa objek.',
            'recommendation_id.integer' => 'Rekomendasi tidak ditemukan.',
        ] + self::contextMessages('context.');
    }

    public function fromTask(): Task
    {
        return $this->fromTask;
    }

    public function toTask(): Task
    {
        return $this->toTask;
    }

    public function fromTo(): string
    {
        return $this->validated('from_to') ?? 'ready';
    }

    public function recommendation(): ?Recommendation
    {
        return $this->recommendation;
    }

    /**
     * The validated context, or null when none was sent (TransitionService
     * then answers context_required). An empty object is a context.
     *
     * @return array<string, mixed>|null
     */
    public function context(): ?array
    {
        if (! is_array($this->input('context'))) {
            return null;
        }

        return $this->validated('context') ?? [];
    }

    /** Same lookup as the {task} route parameter: numeric ID or task_key. */
    private function findTask(string $field): ?Task
    {
        return (new Task)->resolveRouteBinding($this->input($field));
    }
}
