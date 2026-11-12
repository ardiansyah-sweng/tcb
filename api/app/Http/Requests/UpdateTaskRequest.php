<?php

namespace App\Http\Requests;

use App\Models\Task;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Validator;

/**
 * Schema TaskUpdate in docs/tcb_openapi.yaml. Every field is optional, but
 * title, priority, and energy cannot be emptied. A task cannot be its own
 * parent or get one of its descendants as parent.
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
            function (Validator $validator) {
                $parentId = $this->input('parent_task_id');
                if ($parentId === null || $validator->errors()->has('parent_task_id')) {
                    return;
                }

                /** @var Task $task */
                $task = $this->route('task');
                if ((int) $parentId === $task->id) {
                    $validator->errors()->add('parent_task_id', 'Task tidak bisa menjadi induk dirinya sendiri.');
                } elseif ($this->isAncestorOf($task->id, (int) $parentId)) {
                    $validator->errors()->add('parent_task_id', 'Task induk tidak boleh subtask dari task ini (membentuk siklus).');
                }
            },
        ];
    }

    /**
     * Whether $ancestorId appears in the parent chain above $taskId. UNION
     * (not UNION ALL) stops the walk even if the data already holds a cycle.
     */
    private function isAncestorOf(int $ancestorId, int $taskId): bool
    {
        $row = DB::selectOne(<<<'SQL'
            WITH RECURSIVE chain (id, parent_task_id) AS (
              SELECT id, parent_task_id FROM tasks WHERE id = ?
              UNION
              SELECT t.id, t.parent_task_id FROM tasks t JOIN chain c ON t.id = c.parent_task_id
            )
            SELECT EXISTS (SELECT 1 FROM chain WHERE id = ?) AS found
            SQL, [$taskId, $ancestorId]);

        return (bool) $row->found;
    }
}
