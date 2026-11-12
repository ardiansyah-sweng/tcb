<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Models\KeyResult;
use App\Models\Task;
use App\Services\TransitionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * FR-03, FR-04: quick add, list, detail, update, and soft delete of tasks.
 * Rows are limited to the signed-in owner by the BelongsToOwner global scope;
 * {task} in the URL is a numeric ID or a task_key (Task::resolveRouteBinding).
 */
class TaskController extends Controller
{
    public const PER_PAGE = 50;

    private const LIST_MESSAGES = [
        'state.regex' => 'Status tidak dikenal. Pilihan: new, ready, running, waiting, suspended, done, cancelled.',
        'key_result_id.integer' => 'key_result_id harus berupa angka.',
        'objective_id.integer' => 'objective_id harus berupa angka.',
        'orphan.in' => 'orphan harus true atau false.',
        'q.string' => 'Kata kunci pencarian harus berupa teks.',
        'label.string' => 'Label harus berupa teks.',
        'sort.in' => 'Urutan yang tersedia saat ini hanya updated.',
    ];

    public function index(Request $request): JsonResponse
    {
        $states = implode('|', Task::STATES);
        $filters = $request->validate([
            'state' => ['sometimes', 'string', "regex:/^\s*($states)\s*(,\s*($states)\s*)*$/"],
            'key_result_id' => ['sometimes', 'integer'],
            'objective_id' => ['sometimes', 'integer'],
            'label' => ['sometimes', 'string'],
            'orphan' => ['sometimes', 'in:true,false,1,0'],
            'q' => ['sometimes', 'string'],
            // score (R1-13) and deadline are in the contract but not built yet.
            'sort' => ['sometimes', 'in:updated'],
            'cursor' => ['sometimes', 'string'],
        ], self::LIST_MESSAGES);

        // Order on the raw timestamptz text: the datetime cast would put a
        // seconds-only value in the cursor and skip rows within the same second.
        $page = Task::query()
            ->select(['tasks.*', 'tasks.updated_at as cursor_updated_at'])
            ->when($filters['state'] ?? null, fn (Builder $q, string $s) => $q->whereIn('state', array_map('trim', explode(',', $s))))
            ->when($filters['key_result_id'] ?? null, fn (Builder $q, $id) => $q->where('key_result_id', $id))
            ->when($filters['objective_id'] ?? null, fn (Builder $q, $id) => $q->whereIn(
                'key_result_id',
                KeyResult::query()->select('id')->where('objective_id', $id),
            ))
            ->when($filters['label'] ?? null, fn (Builder $q, string $label) => $q->where('label', $label))
            ->when(isset($filters['orphan']), fn (Builder $q) => in_array($filters['orphan'], ['true', '1'], true)
                ? $q->whereNull('key_result_id')
                : $q->whereNotNull('key_result_id'))
            ->when($filters['q'] ?? null, function (Builder $q, string $term) {
                $pattern = '%'.addcslashes($term, '\\%_').'%';
                $q->where(fn (Builder $w) => $w->where('title', 'ilike', $pattern)->orWhere('task_key', 'ilike', $pattern));
            })
            ->orderByDesc('cursor_updated_at')
            ->orderByDesc('id')
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => TaskResource::collection($page->items()),
            'next_cursor' => $page->nextCursor()?->encode(),
        ]);
    }

    public function store(StoreTaskRequest $request, TransitionService $transitions): JsonResponse
    {
        $task = DB::transaction(function () use ($request, $transitions) {
            $task = Task::create($request->taskAttributes());

            if ($request->boolean('start_ready')) {
                $task = $transitions->transition($task, 'ready');
            }

            return $task;
        });

        return (new TaskResource($task->fresh()))->response()->setStatusCode(201);
    }

    public function show(Task $task): TaskDetailResource
    {
        return TaskDetailResource::load($task);
    }

    public function update(UpdateTaskRequest $request, Task $task): TaskDetailResource
    {
        $task->update($request->taskAttributes());

        return TaskDetailResource::load($task->fresh());
    }

    /** Soft delete: the row, its transition log, and its sessions stay for metrics. */
    public function destroy(Task $task): Response
    {
        $task->delete();

        return response()->noContent();
    }
}
