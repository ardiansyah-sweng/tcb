<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskRequest;
use App\Http\Requests\UpdateTaskRequest;
use App\Http\Resources\TaskDetailResource;
use App\Http\Resources\TaskResource;
use App\Models\KeyResult;
use App\Models\Task;
use App\Services\DependencyService;
use App\Services\Recommendation\RecommendationService;
use App\Services\Recommendation\ScoredTask;
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
        'sort.in' => 'Urutan yang tersedia saat ini hanya updated dan score.',
    ];

    public function index(Request $request, RecommendationService $recommendations): JsonResponse
    {
        $states = implode('|', Task::STATES);
        $filters = $request->validate([
            'state' => ['sometimes', 'string', "regex:/^\s*($states)\s*(,\s*($states)\s*)*$/"],
            'key_result_id' => ['sometimes', 'integer'],
            'objective_id' => ['sometimes', 'integer'],
            'label' => ['sometimes', 'string'],
            'orphan' => ['sometimes', 'in:true,false,1,0'],
            'q' => ['sometimes', 'string'],
            // deadline is in the contract but not built yet.
            'sort' => ['sometimes', 'in:updated,score'],
            'cursor' => ['sometimes', 'string'],
        ], self::LIST_MESSAGES);

        $query = Task::query()
            // Order on the raw timestamptz text: the datetime cast would put a
            // seconds-only value in the cursor and skip rows within the same second.
            ->select(['tasks.*', 'tasks.updated_at as cursor_updated_at']);

        if (($filters['sort'] ?? 'updated') === 'score') {
            $this->orderByCurrentScore($query, $request->user()->id, $recommendations->currentRanking($request->user()));
        }

        $page = $query
            ->with('keyResult:id,title')
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

    /**
     * sort=score (R1-18, decision 13 Nov 2026): Ready tasks in the order of
     * their current recommendation score, then every other task by the usual
     * order. Each task is joined to its rank key (count − position for a
     * scored task, 0 otherwise) and score (Task.score), computed in a
     * subquery so the cursor compares a plain column, never a NULL.
     *
     * @param  Builder<Task>  $query
     * @param  list<ScoredTask>  $ranked
     */
    private function orderByCurrentScore(Builder $query, int $ownerId, array $ranked): void
    {
        $scores = DB::table('tasks as st')->where('st.owner_id', $ownerId)->select('st.id as task_id');

        if ($ranked === []) {
            $scores->selectRaw('null::double precision as score, 0 as rank_key');
        } else {
            $count = count($ranked);
            $bindings = [];
            foreach ($ranked as $position => $scored) {
                array_push($bindings, $scored->taskId, $scored->score, $count - $position);
            }
            $rows = implode(', ', array_fill(0, $count, '(?::bigint, ?::double precision, ?::integer)'));
            $values = DB::query()->fromRaw("(values {$rows}) as v(task_id, score, rank_key)", $bindings)->select(['task_id', 'score', 'rank_key']);

            $scores->leftJoinSub($values, 'v', 'v.task_id', '=', 'st.id')
                ->selectRaw('v.score, coalesce(v.rank_key, 0) as rank_key');
        }

        $query->joinSub($scores, 'sr', 'sr.task_id', '=', 'tasks.id')
            ->addSelect(['sr.score as current_score', 'sr.rank_key as cursor_score_rank'])
            ->orderByDesc('cursor_score_rank');
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
    /**
     * Soft delete. Tasks waiting for this one are released like when it is
     * Done (decision 12 Nov 2026), so they do not stay Waiting forever.
     */
    public function destroy(Task $task, DependencyService $dependencies): Response
    {
        DB::transaction(function () use ($task, $dependencies) {
            $task->delete();
            $dependencies->resolveWaitingOn($task);
        });

        return response()->noContent();
    }
}
