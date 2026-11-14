<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-19 (decision 14 Nov 2026): TaskDetail.allowed_transitions, so the task
// detail page shows only the actions that are valid for the current state
// without copying the transition list into TypeScript (CLAUDE.md rule 2).
// The expected values are read from the allowed_transitions table itself.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/**
 * @return list<string> to_states of $from in the table, in lifecycle order
 */
function targetsInTable(string $from): array
{
    $order = array_flip(Task::STATES);

    return DB::table('allowed_transitions')->where('from_state', $from)->pluck('to_state')
        ->sortBy(fn (string $to) => $order[$to])
        ->values()->all();
}

it('lists the valid target states of the task in the detail', function (string $state, array $path) {
    $task = walkTo(makeTask($this->user), ...$path);

    $this->getJson("/api/v1/tasks/{$task->task_key}")
        ->assertOk()
        ->assertJsonPath('state', $state)
        ->assertJsonPath('allowed_transitions', targetsInTable($state));
})->with([
    'new' => ['new', []],
    'ready' => ['ready', ['ready']],
    'running' => ['running', ['ready', 'running']],
    'waiting' => ['waiting', ['ready', 'running', 'waiting']],
    'suspended' => ['suspended', ['ready', 'suspended']],
    'done' => ['done', ['ready', 'running', 'done']],
    'cancelled' => ['cancelled', ['cancelled']],
]);

it('follows the table, so a removed row disappears from the list', function () {
    $task = makeTask($this->user);
    DB::table('allowed_transitions')->where(['from_state' => 'new', 'to_state' => 'cancelled'])->delete();

    $this->getJson("/api/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('allowed_transitions', ['ready']);
});

it('returns the targets of the new state after a transition', function () {
    $task = walkTo(makeTask($this->user), 'ready');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])
        ->assertOk()
        ->assertJsonPath('allowed_transitions', targetsInTable('running'));
});

it('returns the targets of the started task after a switch', function () {
    $running = walkTo(makeTask($this->user), 'ready', 'running');
    $next = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $running->task_key,
        'from_to' => 'ready',
        'context' => ['last_note' => 'Lembar 1–8 selesai'],
        'to_task_id' => $next->task_key,
    ])
        ->assertOk()
        ->assertJsonPath('started.allowed_transitions', targetsInTable('running'))
        ->assertJsonMissingPath('left.allowed_transitions');
});

it('is not part of the task list', function () {
    makeTask($this->user);

    $this->getJson('/api/v1/tasks')
        ->assertOk()
        ->assertJsonMissingPath('data.0.allowed_transitions');
});
