<?php

namespace App\Http\Requests\Concerns;

use App\Http\Requests\StoreTaskRequest;
use App\Models\Task;
use App\Support\ProgressUnit;
use Closure;
use Illuminate\Validation\Validator;

/**
 * ContextInput (docs/tcb_openapi.yaml): every field optional. Used at the top
 * level by POST /tasks/{id}/contexts and under "context." by the transition
 * request (and /switch in R1-11).
 *
 * unit_done is copied to the task, so it is checked against the task's
 * unit_total like ValidatesProgressUnit does; the CHECK constraints
 * chk_tasks_unit_needs_total and chk_tasks_unit_le_total still guard.
 */
trait ValidatesContextInput
{
    /**
     * @return array<string, mixed>
     */
    public static function contextRules(string $prefix = ''): array
    {
        return [
            "{$prefix}last_note" => ['nullable', 'string'],
            "{$prefix}next_step" => ['nullable', 'string'],
            "{$prefix}blocker" => ['nullable', 'string'],
            "{$prefix}unit_done" => ['bail', 'nullable', 'numeric', 'min:0'],
            "{$prefix}links" => ['nullable', 'array', 'list'],
            "{$prefix}links.*" => ['array'],
            "{$prefix}links.*.title" => ['nullable', 'string'],
            "{$prefix}links.*.url" => ['required', 'url'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function contextMessages(string $prefix = ''): array
    {
        return [
            "{$prefix}last_note.string" => 'Catatan "sampai mana" harus berupa teks.',
            "{$prefix}next_step.string" => 'Langkah berikutnya harus berupa teks.',
            "{$prefix}blocker.string" => 'Hambatan harus berupa teks.',
            "{$prefix}unit_done.numeric" => 'Jumlah selesai harus berupa angka.',
            "{$prefix}unit_done.min" => 'Jumlah selesai tidak boleh negatif.',
            "{$prefix}links.array" => 'Tautan harus berupa daftar.',
            "{$prefix}links.list" => 'Tautan harus berupa daftar.',
            "{$prefix}links.*.array" => 'Setiap tautan harus berupa objek dengan url.',
            "{$prefix}links.*.title.string" => 'Judul tautan harus berupa teks.',
            "{$prefix}links.*.url.required" => 'Alamat tautan wajib diisi.',
            "{$prefix}links.*.url.url" => 'Alamat tautan tidak valid.',
        ];
    }

    /**
     * unit_done against the task's stored total.
     */
    protected function contextUnitCheck(string $prefix, Task $task): Closure
    {
        return function (Validator $validator) use ($prefix, $task) {
            $field = "{$prefix}unit_done";
            $done = $this->input($field);

            if ($validator->errors()->has($field) || $done === null) {
                return;
            }

            if ($task->unit_total === null) {
                $validator->errors()->add($field, StoreTaskRequest::NEEDS_TOTAL_MESSAGE);
            } elseif ((float) $done > $task->unit_total) {
                $name = $task->unit_name ?? ProgressUnit::DEFAULT_NAME;
                $validator->errors()->add($field, 'Jumlah selesai ('.ProgressUnit::formatNumber($done)
                    .') tidak boleh melebihi total ('.ProgressUnit::formatNumber($task->unit_total)." {$name}).");
            }
        };
    }
}
