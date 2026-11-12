<?php

namespace App\Services;

use App\Exceptions\TransitionRejected;
use App\Models\Task;
use App\Models\WorkSession;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way to change a task's state (CLAUDE.md rule 1, SRS section 12).
 *
 * One transaction per transition, in the order of SRS section 12:
 * 1. set the log source locally;
 * 2. friendly checks (the database checks the rules again);
 * 3. leaving Running: save the context, copy next_step and unit_done to the
 *    task, close the work session, add the time, count a switch unless the
 *    task is finished;
 * 4. change the state; entering Running opens a new work session.
 * Any failure rolls back every step. R1-12 adds the dependency release.
 */
class TransitionService
{
    public const SOURCES = ['user', 'calendar', 'system'];

    public function __construct(
        private readonly TaskStateMachine $machine,
        private readonly ContextService $contexts,
    ) {}

    /**
     * Move $task to $to and return the updated task. $reviewOn (Y-m-d) is the
     * optional review date when suspending; leaving Suspended clears it.
     * $context (ContextInput, validated) is required when the task leaves
     * Running unfinished, and stored whenever it is given.
     *
     * @param  array<string, mixed>|null  $context
     *
     * @throws TransitionRejected when a TCB rule refuses the change
     */
    public function transition(
        Task $task,
        string $to,
        ?string $reason = null,
        string $source = 'user',
        ?string $reviewOn = null,
        ?array $context = null,
    ): Task {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown transition source: {$source}");
        }

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        try {
            return DB::transaction(function () use ($task, $to, $reason, $source, $reviewOn, $context) {
                // Same as SET LOCAL tcb.source (CLAUDE.md rule 4); read by the log trigger.
                DB::select("select set_config('tcb.source', ?, true)", [$source]);

                // Lock the row so the checks below see the state the update will change.
                $locked = Task::withoutGlobalScope('owner')->lockForUpdate()->findOrFail($task->id);
                $from = $locked->state;

                $this->check($locked, $to, $reason, $context);

                if ($context !== null) {
                    $this->contexts->capture($locked, $context);
                }

                if ($from === 'running') {
                    $locked->time_spent_minutes += $this->closeSession($locked);
                    if ($this->machine->isContextSwitch($from, $to)) {
                        $locked->switch_count++;
                    }
                }

                $locked->forceFill([
                    'state' => $to,
                    // chk_tasks_review_on_suspended: only a suspended task keeps a review date.
                    'review_on' => $to === 'suspended' ? $reviewOn : null,
                ]);
                if ($reason !== null) {
                    $locked->state_reason = $reason;
                }
                // Without a reason the trigger decides: an old reason is cleared
                // unless the new state keeps one (waiting/suspended/cancelled).
                Task::changingState(fn () => $locked->save());

                if ($to === 'running') {
                    $this->openSession($locked);
                }

                return $locked->fresh();
            });
        } catch (QueryException $e) {
            throw DatabaseErrors::rejection($e, $task->owner_id, $to) ?? $e;
        }
    }

    /**
     * Application checks, in the order a user would fix them. The database
     * enforces the transition and reason rules again.
     *
     * @param  array<string, mixed>|null  $context
     */
    private function check(Task $task, string $to, ?string $reason, ?array $context): void
    {
        if (! $this->machine->allows($task->state, $to)) {
            throw TransitionRejected::invalidTransition($this->machine, $task->state, $to);
        }

        if ($reason === null && $this->machine->requiresReason($to)) {
            throw TransitionRejected::reasonRequired($to);
        }

        if ($context === null && $this->machine->isContextSwitch($task->state, $to)) {
            throw TransitionRejected::contextRequired();
        }

        if ($to === 'done') {
            $open = $task->subtasks()
                ->whereNotIn('state', ['done', 'cancelled'])
                ->orderBy('id')
                ->pluck('task_key')
                ->all();

            if ($open !== []) {
                throw TransitionRejected::openSubtasks($open);
            }
        }
    }

    private function openSession(Task $task): void
    {
        // uq_work_sessions_open guards against a second open session.
        WorkSession::create([
            'owner_id' => $task->owner_id,
            'task_id' => $task->id,
            'started_at' => now(),
        ]);
    }

    /**
     * Close the open session and return its minutes (the generated
     * duration_minutes column), or 0 when there is none.
     */
    private function closeSession(Task $task): int
    {
        $closed = DB::selectOne(
            'update work_sessions set ended_at = greatest(?::timestamptz, started_at)
             where task_id = ? and ended_at is null
             returning duration_minutes',
            [now()->format('Y-m-d H:i:sP'), $task->id],
        );

        return (int) ($closed->duration_minutes ?? 0);
    }
}
