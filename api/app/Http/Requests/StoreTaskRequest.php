<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Schema TaskCreate in docs/tcb_openapi.yaml. Only the title is required
 * (CLAUDE.md rule 6); everything else falls back to database defaults.
 *
 * The unit fields are added by R1-08. A new task has no subtasks, so its
 * parent cannot form a cycle; UpdateTaskRequest checks cycles.
 */
class StoreTaskRequest extends FormRequest
{
    /** Shared with UpdateTaskRequest. */
    public const MESSAGES = [
        'title.required' => 'Judul wajib diisi.',
        'title.string' => 'Judul harus berupa teks.',
        'description.string' => 'Deskripsi harus berupa teks.',
        'key_result_id.integer' => 'Key Result tidak ditemukan.',
        'key_result_id.exists' => 'Key Result tidak ditemukan.',
        'parent_task_id.integer' => 'Task induk tidak ditemukan.',
        'parent_task_id.exists' => 'Task induk tidak ditemukan.',
        'label.string' => 'Label harus berupa teks.',
        'priority.required' => 'Prioritas wajib diisi.',
        'priority.integer' => 'Prioritas harus bilangan bulat 1 sampai 4.',
        'priority.between' => 'Prioritas harus bilangan bulat 1 sampai 4.',
        'deadline_at.date' => 'Tenggat harus berupa tanggal dan jam yang sah.',
        'estimate_minutes.integer' => 'Estimasi harus berupa bilangan bulat menit.',
        'estimate_minutes.min' => 'Estimasi paling sedikit 1 menit.',
        'energy.required' => 'Jenis energi wajib diisi.',
        'energy.in' => 'Jenis energi harus focus atau light.',
        'next_step.string' => 'Langkah berikutnya harus berupa teks.',
        'start_ready.boolean' => 'start_ready harus true atau false.',
    ];

    /**
     * Rules shared by create and update; update prefixes each with `sometimes`.
     *
     * @return array<string, mixed>
     */
    public static function fieldRules(): array
    {
        return [
            'title' => ['required', 'string'],
            'description' => ['nullable', 'string'],
            'key_result_id' => ['bail', 'nullable', 'integer', self::ownKeyResult()],
            'parent_task_id' => ['bail', 'nullable', 'integer', self::ownParent()],
            'label' => ['nullable', 'string'],
            'priority' => ['bail', 'required', 'integer', 'between:1,4'],
            'deadline_at' => ['nullable', 'date'],
            'estimate_minutes' => ['bail', 'nullable', 'integer', 'min:1'],
            'energy' => ['required', 'in:focus,light'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = self::fieldRules();
        // Optional on create: an absent field keeps the database default.
        foreach (['priority', 'energy'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        return $rules + ['start_ready' => ['sometimes', 'boolean']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return self::MESSAGES;
    }

    /** Another owner's key result is reported as not found. */
    private static function ownKeyResult(): Exists
    {
        return Rule::exists('key_results', 'id')->where('owner_id', Auth::id());
    }

    /** The parent must be one of the owner's tasks that is not deleted. */
    private static function ownParent(): Exists
    {
        return Rule::exists('tasks', 'id')->where('owner_id', Auth::id())->whereNull('deleted_at');
    }
}
