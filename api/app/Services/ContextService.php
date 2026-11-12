<?php

namespace App\Services;

use App\Models\ContextSnapshot;
use App\Models\Task;
use Illuminate\Support\Facades\DB;

/**
 * The "registers" of a task (FR-07, FR-08): context snapshots, notes, and
 * the draft that pre-fills the context form (input-minimal principle).
 *
 * tasks.next_step and tasks.unit_done are a copy of the newest snapshot for
 * quick display, so every snapshot updates them too.
 */
class ContextService
{
    /**
     * Insert a snapshot of $task and copy next_step and unit_done onto it
     * (unsaved; the caller saves the task in the same transaction). Fields
     * left out of $input keep the task's current next_step and unit_done, so
     * the snapshot always holds the full registers.
     *
     * @param  array<string, mixed>  $input  ContextInput, already validated
     */
    public function capture(Task $task, array $input): ContextSnapshot
    {
        $snapshot = $task->contextSnapshots()->create([
            'owner_id' => $task->owner_id,
            'last_note' => $input['last_note'] ?? null,
            'next_step' => array_key_exists('next_step', $input) ? $input['next_step'] : $task->next_step,
            'blocker' => $input['blocker'] ?? null,
            'unit_done' => array_key_exists('unit_done', $input) ? $input['unit_done'] : $task->unit_done,
            'links' => self::links($input['links'] ?? null),
        ]);

        $task->forceFill(['next_step' => $snapshot->next_step, 'unit_done' => $snapshot->unit_done]);

        return $snapshot;
    }

    /**
     * POST /tasks/{id}/contexts: a note that leaves the state alone.
     *
     * @param  array<string, mixed>  $input
     */
    public function note(Task $task, array $input): ContextSnapshot
    {
        return DB::transaction(function () use ($task, $input) {
            $locked = Task::withoutGlobalScope('owner')->lockForUpdate()->findOrFail($task->id);
            $snapshot = $this->capture($locked, $input);
            $locked->save();

            return $snapshot;
        });
    }

    /**
     * Pre-filled ContextInput: the newest snapshot, with next_step and
     * unit_done taken from the task, which may have been edited since.
     *
     * @return array{last_note: ?string, next_step: ?string, blocker: ?string, unit_done: float|null, links: list<array<string, string>>}
     */
    public function draft(Task $task): array
    {
        $latest = $task->latestContext;

        return [
            'last_note' => $latest?->last_note,
            'next_step' => $task->next_step,
            'blocker' => $latest?->blocker,
            // Same rule as Task.progress_unit: nothing done yet is 0 once a total exists.
            'unit_done' => $task->unit_total === null ? null : ($task->unit_done ?? 0.0),
            'links' => $latest?->links ?? [],
        ];
    }

    /**
     * Keep only title and url of each link, in that order.
     *
     * @param  list<array<string, mixed>>|null  $links
     * @return list<array<string, string>>
     */
    private static function links(?array $links): array
    {
        return array_map(
            fn (array $link) => array_filter(
                ['title' => $link['title'] ?? null, 'url' => $link['url']],
                fn ($value) => $value !== null,
            ),
            array_values($links ?? []),
        );
    }
}
