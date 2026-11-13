<?php

namespace App\Services;

use App\Models\Dependency;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * Releases waiting tasks (FR-11, SRS section 12): the only automatic state
 * change without confirmation. Runs when a task becomes Done or Cancelled
 * (called by TransitionService in the same transaction; Cancelled since the
 * decision of 13 Nov 2026), when an external blocker is
 * marked resolved, and when a task is deleted (decision 12 Nov 2026, so a
 * task waiting for a deleted one is not stuck forever).
 *
 * Queries filter by owner themselves instead of relying on the owner scope,
 * so the service also works without a signed-in user (queued jobs).
 */
class DependencyService
{
    /**
     * Resolve the open dependencies waiting for $task, then move each waiting
     * task without open dependencies to Ready. Call inside the transaction
     * that finishes, cancels or deletes $task.
     */
    public function resolveWaitingOn(Task $task): void
    {
        $dependents = Dependency::withoutGlobalScope('owner')
            ->where('owner_id', $task->owner_id)
            ->where('depends_on_task_id', $task->id)
            ->where('status', 'open')
            ->pluck('task_id')
            ->all();

        if ($dependents === []) {
            return;
        }

        Dependency::withoutGlobalScope('owner')
            ->where('owner_id', $task->owner_id)
            ->where('depends_on_task_id', $task->id)
            ->where('status', 'open')
            // Database time: the same timestamp as the transition log of this transaction.
            ->update(['status' => 'resolved', 'resolved_at' => DB::raw('now()')]);

        $this->releaseUnblocked($task->owner_id, $dependents);
    }

    /**
     * Mark an external blocker resolved and run the same release check.
     * Repeating it keeps resolved_at but still runs the check.
     */
    public function resolveExternal(Dependency $dependency): Dependency
    {
        return DB::transaction(function () use ($dependency) {
            Dependency::withoutGlobalScope('owner')
                ->whereKey($dependency->id)
                ->where('status', 'open')
                ->update(['status' => 'resolved', 'resolved_at' => DB::raw('now()')]);

            $this->releaseUnblocked($dependency->owner_id, [$dependency->task_id]);

            return $dependency->fresh();
        });
    }

    /**
     * Move the waiting, not deleted tasks among $taskIds that have no open
     * dependency left to Ready, with source system in the log.
     *
     * @param  list<int>  $taskIds
     */
    private function releaseUnblocked(int $ownerId, array $taskIds): void
    {
        $unblocked = Task::withoutGlobalScope('owner')
            ->where('owner_id', $ownerId)
            ->whereIn('id', $taskIds)
            ->where('state', 'waiting')
            ->whereDoesntHave('dependencies', fn ($q) => $q->withoutGlobalScope('owner')->where('status', 'open'))
            ->orderBy('id')
            ->get();

        // Resolved here, not injected: TransitionService depends on this service.
        $transitions = app(TransitionService::class);

        foreach ($unblocked as $task) {
            $transitions->transition($task, 'ready', source: 'system');
        }
    }
}
