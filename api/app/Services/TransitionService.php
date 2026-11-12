<?php

namespace App\Services;

use App\Exceptions\TransitionRejected;
use App\Models\Task;
use App\Support\DatabaseErrors;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The only way to change a task's state (CLAUDE.md rule 1, SRS section 12).
 *
 * R1-09: one transaction per transition, the log source set locally, and
 * friendly checks before the database checks again. R1-10 adds the context
 * snapshot, work sessions, and time accounting; R1-12 the dependency release.
 */
class TransitionService
{
    public const SOURCES = ['user', 'calendar', 'system'];

    public function __construct(private readonly TaskStateMachine $machine) {}

    /**
     * Move $task to $to and return the updated task. $reviewOn (Y-m-d) is the
     * optional review date when suspending; leaving Suspended clears it.
     *
     * @throws TransitionRejected when a TCB rule refuses the change
     */
    public function transition(
        Task $task,
        string $to,
        ?string $reason = null,
        string $source = 'user',
        ?string $reviewOn = null,
    ): Task {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("Unknown transition source: {$source}");
        }

        $reason = $reason === null ? null : trim($reason);
        $reason = $reason === '' ? null : $reason;

        try {
            return DB::transaction(function () use ($task, $to, $reason, $source, $reviewOn) {
                // Same as SET LOCAL tcb.source (CLAUDE.md rule 4); read by the log trigger.
                DB::select("select set_config('tcb.source', ?, true)", [$source]);

                // Lock the row so the checks below see the state the update will change.
                $locked = Task::withoutGlobalScope('owner')->lockForUpdate()->findOrFail($task->id);

                $this->check($locked, $to, $reason);

                $attributes = [
                    'state' => $to,
                    // chk_tasks_review_on_suspended: only a suspended task keeps a review date.
                    'review_on' => $to === 'suspended' ? $reviewOn : null,
                ];
                if ($reason !== null) {
                    $attributes['state_reason'] = $reason;
                }
                // Without a reason the trigger decides: an old reason is cleared
                // unless the new state keeps one (waiting/suspended/cancelled).
                $locked->forceFill($attributes)->save();

                return $locked->fresh();
            });
        } catch (QueryException $e) {
            throw DatabaseErrors::rejection($e, $task->owner_id, $to) ?? $e;
        }
    }

    /**
     * Application checks, in the order a user would fix them. The database
     * enforces the transition and reason rules again.
     */
    private function check(Task $task, string $to, ?string $reason): void
    {
        if (! $this->machine->allows($task->state, $to)) {
            throw TransitionRejected::invalidTransition($this->machine, $task->state, $to);
        }

        if ($reason === null && $this->machine->requiresReason($to)) {
            throw TransitionRejected::reasonRequired($to);
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
}
