<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Schema DependencyInput in docs/tcb_openapi.yaml: exactly one of
 * depends_on_task_id (a numeric ID or task_key) or external_description.
 *
 * The database enforces "exactly one", "not itself", and uniqueness as the
 * last fence (CLAUDE.md rule 3); this request turns them into friendly 422s
 * and adds the checks the database does not have: the target must be the
 * owner's live task, not already finished, and must not close a cycle.
 */
class StoreDependencyRequest extends FormRequest
{
    public const DUPLICATE_MESSAGE = 'Task ini sudah menunggu %s.';

    /** The task waited for, once validation passes; null for an external blocker. */
    public ?Task $dependsOn = null;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // String or integer; resolved in after().
            'depends_on_task_id' => ['nullable'],
            'external_description' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'external_description.string' => 'Deskripsi hambatan eksternal harus berupa teks.',
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $ref = $this->input('depends_on_task_id');
            $hasTask = $ref !== null && $ref !== '';
            $hasExternal = $this->filled('external_description');

            if ($hasTask === $hasExternal) {
                $validator->errors()->add('depends_on_task_id', $hasTask
                    ? 'Isi salah satu: task yang ditunggu atau hambatan eksternal, tidak keduanya.'
                    : 'Isi task yang ditunggu atau hambatan eksternal.');

                return;
            }

            if ($hasTask && ($message = $this->checkTarget($ref)) !== null) {
                $validator->errors()->add('depends_on_task_id', $message);
            }
        }];
    }

    /** Resolve and check the task waited for; returns an error message or null. */
    private function checkTarget(mixed $ref): ?string
    {
        /** @var Task $task */
        $task = $this->route('task');

        $target = is_int($ref) || is_string($ref)
            ? (new Task)->resolveRouteBinding((string) $ref)
            : null;

        if ($target === null) {
            return 'Task yang ditunggu tidak ditemukan.';
        }
        if ($target->id === $task->id) {
            return 'Task tidak bisa menunggu dirinya sendiri.';
        }
        if (in_array($target->state, ['done', 'cancelled'], true)) {
            return sprintf('%s sudah %s; tidak ada yang perlu ditunggu.', $target->task_key, ucfirst($target->state));
        }
        if ($task->dependencies()->where('depends_on_task_id', $target->id)->exists()) {
            return sprintf(self::DUPLICATE_MESSAGE, $target->task_key);
        }
        if ($this->waitsFor($target->id, $task->id)) {
            return sprintf(
                'Dependensi ini membentuk siklus: %s sudah menunggu %s, langsung atau lewat task lain.',
                $target->task_key,
                $task->task_key,
            );
        }

        $this->dependsOn = $target;

        return null;
    }

    /**
     * Whether $taskId already waits for $otherId through a chain of open task
     * dependencies. Resolved ones no longer block, so they cannot deadlock.
     * UNION (not UNION ALL) stops the walk even if the data holds a cycle.
     */
    private function waitsFor(int $taskId, int $otherId): bool
    {
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE waited (id) AS (
              SELECT depends_on_task_id FROM dependencies
               WHERE task_id = ? AND status = 'open' AND depends_on_task_id IS NOT NULL
              UNION
              SELECT d.depends_on_task_id FROM dependencies d JOIN waited w ON d.task_id = w.id
               WHERE d.status = 'open' AND d.depends_on_task_id IS NOT NULL
            )
            SELECT EXISTS (SELECT 1 FROM waited WHERE id = ?) AS found
            SQL, [$taskId, $otherId]);

        return (bool) $row->found;
    }
}
