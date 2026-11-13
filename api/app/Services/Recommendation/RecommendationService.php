<?php

namespace App\Services\Recommendation;

use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Psr\Clock\ClockInterface;
use Throwable;

/**
 * GET /recommendations (FR-12, SRS bagian 6): load the owner's Ready tasks,
 * rank them with RecommendationScorer, drop tasks skipped today, and store
 * the top ones as one batch.
 *
 * The database work lives here; RecommendationScorer stays pure (R1-13).
 * Queries filter by owner_id explicitly, so this also works without a
 * signed-in user.
 */
class RecommendationService
{
    public const STALE_TASKS_LIMIT = 3;

    public function __construct(
        private readonly RecommendationScorer $scorer,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @return array{
     *     batch_id: string,
     *     generated_at: CarbonImmutable,
     *     running_full: bool,
     *     items: list<array{recommendation: Recommendation, scored: ScoredTask, task: Task}>,
     *     stale_tasks: list<Task>
     * }
     */
    public function generate(User $user, int $limit = 3): array
    {
        $now = CarbonImmutable::instance($this->clock->now());
        // Read the settings from the table: an in-memory user may lack the column defaults.
        $user = User::query()->findOrFail($user->id, ['id', 'timezone', 'wip_limit', 'score_weights', 'work_hours']);
        $tasks = $this->readyTasks($user);

        $ranked = $this->scorer->rank(
            $tasks->map(fn (Task $task) => $this->candidate($task)),
            $this->profile($user),
        );

        // A task skipped today stays out unless nothing else is left; the
        // remaining slots are not filled with skipped tasks (13 Nov 2026).
        $skipped = $this->skippedToday($user, $now);
        $notSkipped = array_values(array_filter($ranked, fn (ScoredTask $s) => ! isset($skipped[$s->taskId])));
        $chosen = array_slice($notSkipped !== [] ? $notSkipped : $ranked, 0, $limit);

        $batchId = (string) Str::uuid();

        $items = DB::transaction(fn () => array_map(fn (ScoredTask $scored, int $i) => [
            'recommendation' => Recommendation::create([
                'owner_id' => $user->id,
                'task_id' => $scored->taskId,
                'batch_id' => $batchId,
                'rank' => $i + 1,
                'score' => $scored->score,
                'breakdown' => $scored->breakdown,
                'reasons' => $scored->reasons,
                'generated_at' => $now,
            ]),
            'scored' => $scored,
            'task' => $tasks[$scored->taskId],
        ], $chosen, array_keys($chosen)));

        return [
            'batch_id' => $batchId,
            'generated_at' => $now,
            'running_full' => $this->runningFull($user),
            'items' => $items,
            'stale_tasks' => $this->staleTasks($ranked, $chosen, $skipped, $tasks),
        ];
    }

    /**
     * The current score of every eligible Ready task of $user, best first,
     * in the same order and with the same scores as generate() (R1-18,
     * GET /tasks?sort=score). Nothing is stored and tasks skipped today are
     * kept: this is the board order, not a recommendation batch.
     *
     * @return list<ScoredTask>
     */
    public function currentRanking(User $user): array
    {
        $user = User::query()->findOrFail($user->id, ['id', 'timezone', 'wip_limit', 'score_weights', 'work_hours']);

        return $this->scorer->rank(
            $this->readyTasks($user)->map(fn (Task $task) => $this->candidate($task)),
            $this->profile($user),
        );
    }

    /**
     * Eligible stale tasks left out of the batch, so the "kerjakan, tunda,
     * atau batalkan" reminder shows even when their score is low. Longest
     * waiting first (ready_since, then id), at most STALE_TASKS_LIMIT.
     * Tasks skipped today stay out here as well (decision 13 Nov 2026).
     * Computed on request, not stored.
     *
     * @param  list<ScoredTask>  $ranked  eligible tasks only (scorer rank())
     * @param  list<ScoredTask>  $chosen
     * @param  array<int, true>  $skipped
     * @param  Collection<int, Task>  $tasks
     * @return list<Task>
     */
    private function staleTasks(array $ranked, array $chosen, array $skipped, Collection $tasks): array
    {
        $inItems = array_flip(array_map(fn (ScoredTask $s) => $s->taskId, $chosen));

        return collect($ranked)
            ->filter(fn (ScoredTask $s) => $s->stale && ! isset($inItems[$s->taskId]) && ! isset($skipped[$s->taskId]))
            ->map(fn (ScoredTask $s) => $tasks[$s->taskId])
            ->sortBy([
                fn (Task $a, Task $b) => $a->ready_since <=> $b->ready_since,
                fn (Task $a, Task $b) => $a->id <=> $b->id,
            ])
            ->take(self::STALE_TASKS_LIMIT)
            ->values()
            ->all();
    }

    /**
     * Ready tasks with what eligibility and scoring need: open dependency
     * and open subtask counts, and the Key Result weight and progress.
     *
     * @return Collection<int, Task> keyed by task id
     */
    private function readyTasks(User $user): Collection
    {
        return Task::withoutGlobalScope('owner')
            ->where('tasks.owner_id', $user->id)
            ->where('tasks.state', 'ready')
            ->leftJoin('key_result_progress as krp', 'krp.key_result_id', '=', 'tasks.key_result_id')
            ->select('tasks.*', 'krp.weight as kr_weight', 'krp.progress as kr_progress')
            ->withCount([
                'dependencies as open_dependencies_count' => fn (Builder $q) => $q->where('status', 'open'),
                'subtasks as open_subtasks_count' => fn (Builder $q) => $q->whereNotIn('state', ['done', 'cancelled']),
            ])
            ->with('keyResult:id,title') // Task.key_result_title in the response
            ->get()
            ->keyBy('id');
    }

    private function candidate(Task $task): ScoringCandidate
    {
        return new ScoringCandidate(
            taskId: $task->id,
            state: $task->state,
            deleted: $task->trashed(),
            openDependencies: (int) $task->open_dependencies_count,
            openSubtasks: (int) $task->open_subtasks_count,
            keyResultWeight: $task->kr_weight === null ? null : (float) $task->kr_weight,
            keyResultProgress: $task->kr_progress === null ? null : (float) $task->kr_progress,
            priority: $task->priority,
            deadlineAt: $this->immutable($task->deadline_at),
            estimateMinutes: $task->estimate_minutes,
            readySince: $this->immutable($task->ready_since),
        );
    }

    private function profile(User $user): ScoringProfile
    {
        return new ScoringProfile(
            $user->score_weights ?? ScoringProfile::DEFAULT_WEIGHTS,
            $user->work_hours ?? ScoringProfile::DEFAULT_WORK_HOURS,
            $user->timezone ?? ScoringProfile::DEFAULT_TIMEZONE,
        );
    }

    /**
     * Task ids with a recommendation skipped since midnight in the user's timezone.
     *
     * @return array<int, true>
     */
    private function skippedToday(User $user, CarbonImmutable $now): array
    {
        try {
            $startOfDay = $now->setTimezone($user->timezone ?? ScoringProfile::DEFAULT_TIMEZONE)->startOfDay();
        } catch (Throwable) {
            $startOfDay = $now->setTimezone(ScoringProfile::DEFAULT_TIMEZONE)->startOfDay();
        }

        return Recommendation::withoutGlobalScope('owner')
            ->where('owner_id', $user->id)
            ->where('decision', 'skipped')
            ->where('decided_at', '>=', $startOfDay->utc())
            ->distinct()
            ->pluck('task_id')
            ->mapWithKeys(fn (int $id) => [$id => true])
            ->all();
    }

    /** Starting another task now would need a context switch (/switch). */
    private function runningFull(User $user): bool
    {
        $running = Task::withoutGlobalScope('owner')
            ->where('owner_id', $user->id)
            ->where('state', 'running')
            ->count();

        return $running >= $user->wip_limit;
    }

    private function immutable(?\DateTimeInterface $value): ?DateTimeImmutable
    {
        return $value === null ? null : DateTimeImmutable::createFromInterface($value);
    }
}
