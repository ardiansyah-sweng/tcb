<?php

namespace App\Services;

use App\Exceptions\TransitionRejected;
use App\Models\Recommendation;
use App\Models\Task;
use App\Support\DatabaseErrors;
use Illuminate\Support\Facades\DB;

/**
 * Atomic context switch (FR-06, POST /switch): leave the running task with
 * its context and start the target, in one transaction. Both state changes
 * go through TransitionService (CLAUDE.md rule 1); its own transactions
 * become savepoints here, so a failure in either step rolls back both.
 */
class SwitchService
{
    public function __construct(private readonly TransitionService $transitions) {}

    /**
     * @param  array<string, mixed>|null  $context  ContextInput for the task being left
     * @return array{left: Task, started: Task}
     *
     * @throws TransitionRejected when a TCB rule refuses either step
     */
    public function switch(
        Task $from,
        string $fromTo,
        ?array $context,
        Task $to,
        ?string $reason = null,
        ?Recommendation $recommendation = null,
        string $source = 'user',
    ): array {
        try {
            return DB::transaction(function () use ($from, $fromTo, $context, $to, $reason, $recommendation, $source) {
                // Lock both rows in ID order so two opposite switches cannot deadlock;
                // the WIP trigger locks the user row only after this.
                $locked = Task::withoutGlobalScope('owner')
                    ->whereKey([$from->id, $to->id])
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                $current = $locked[$from->id];
                if ($current->state !== 'running') {
                    throw TransitionRejected::notRunning($current);
                }

                $left = $this->transitions->transition($current, $fromTo, $reason, $source, context: $context);
                $started = $this->transitions->transition($locked[$to->id], 'running', source: $source);

                if ($recommendation !== null) {
                    $recommendation->forceFill(['decision' => 'picked', 'decided_at' => now()])->save();
                }

                return ['left' => $left, 'started' => $started];
            });
        } catch (TransitionRejected $e) {
            if (($e->body['code'] ?? null) === 'wip_full') {
                // The list inside the transaction missed the task being left;
                // read it again now that everything is rolled back.
                throw TransitionRejected::wipFull($e->body['wip_limit'], DatabaseErrors::runningTasks($from->owner_id));
            }

            throw $e;
        }
    }
}
