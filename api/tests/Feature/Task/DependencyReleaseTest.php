<?php

use App\Models\Dependency;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-12: DependencyService releases waiting tasks (FR-11, SRS section 12).
// Contract: POST /tasks/{id}/transitions, PATCH /dependencies/{id},
// DELETE /tasks/{id} in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/** A task in Waiting (walked in SQL; no task is left running). */
function waitingTask(User $owner, array $attributes = []): Task
{
    return walkTo(makeTask($owner, $attributes), 'ready', 'running', 'waiting');
}

function waitFor(Task $task, Task $on): Dependency
{
    return Dependency::create(['owner_id' => $task->owner_id, 'task_id' => $task->id, 'depends_on_task_id' => $on->id]);
}

function waitForExternal(Task $task, string $what = 'Nilai praktikum dari asisten'): Dependency
{
    return Dependency::create(['owner_id' => $task->owner_id, 'task_id' => $task->id, 'external_description' => $what]);
}

/** Finish $task through the API (it must not be running yet). */
function finish(Task $task): void
{
    walkTo($task, 'ready', 'running');
    test()->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'done'])->assertOk();
}

function lastTransition(Task $task): object
{
    return DB::table('state_transitions')->where('task_id', $task->id)->orderByDesc('id')->first();
}

/*
| Criterion: when a task becomes Done, the dependencies waiting for it are
| marked resolved in the same transaction.
*/

it('resolves the dependencies waiting for a task that becomes done', function () {
    $paper = makeTask($this->user, ['title' => 'Menulis paper']);
    $a = waitFor(waitingTask($this->user), $paper);
    $b = waitFor(walkTo(makeTask($this->user), 'ready'), $paper);
    $unrelated = waitFor(waitingTask($this->user), makeTask($this->user));

    finish($paper);

    foreach ([$a, $b] as $dependency) {
        $dependency->refresh();
        expect($dependency->status)->toBe('resolved')
            ->and($dependency->resolved_at)->not->toBeNull();
    }
    expect($unrelated->fresh()->status)->toBe('open');
});

it('resolves in the same transaction as the done transition', function () {
    $paper = makeTask($this->user);
    $waiting = waitingTask($this->user);
    $dependency = waitFor($waiting, $paper);

    finish($paper);

    // now() is the transaction start time: one transaction, one timestamp.
    $resolvedAt = DB::table('dependencies')->where('id', $dependency->id)->value('resolved_at');
    expect(lastTransition($paper)->occurred_at)->toBe(lastTransition($waiting)->occurred_at)
        ->and(DB::selectOne('select ?::timestamptz = ?::timestamptz as same', [$resolvedAt, lastTransition($paper)->occurred_at])->same)->toBeTrue();
});

it('resolves nothing when the done transition is refused', function () {
    $paper = makeTask($this->user);
    makeTask($this->user, ['parent_task_id' => $paper->id]);
    $waiting = waitingTask($this->user);
    $dependency = waitFor($waiting, $paper);
    walkTo($paper, 'ready', 'running');

    $this->postJson("/api/v1/tasks/{$paper->id}/transitions", ['to' => 'done'])->assertConflict();

    expect($dependency->fresh()->status)->toBe('open')
        ->and($waiting->fresh()->state)->toBe('waiting');
});

/*
| Decision 13 Nov 2026 (from the R1-12 note): a task that becomes Cancelled
| releases its waiting tasks like Done and soft delete, so they are not
| stuck in Waiting forever.
*/

it('resolves the dependencies waiting for a task that is cancelled and releases the waiting task', function () {
    $paper = walkTo(makeTask($this->user), 'ready');
    $waiting = waitingTask($this->user, ['title' => 'Kirim revisi']);
    $dependency = waitFor($waiting, $paper);

    $this->postJson("/api/v1/tasks/{$paper->id}/transitions", ['to' => 'cancelled', 'reason' => 'Tidak jadi'])->assertOk();

    $dependency->refresh();
    $waiting->refresh();
    $log = lastTransition($waiting);
    expect($dependency->status)->toBe('resolved')
        ->and($dependency->resolved_at)->not->toBeNull()
        ->and($waiting->state)->toBe('ready')
        ->and([$log->from_state, $log->to_state, $log->source])->toBe(['waiting', 'ready', 'system'])
        ->and(lastTransition($paper)->source)->toBe('user');
});

it('keeps a waiting task waiting when the cancelled task was not its only dependency', function () {
    $paper = walkTo(makeTask($this->user), 'ready');
    $waiting = waitingTask($this->user);
    waitFor($waiting, $paper);
    waitForExternal($waiting);

    $this->postJson("/api/v1/tasks/{$paper->id}/transitions", ['to' => 'cancelled', 'reason' => 'Tidak jadi'])->assertOk();

    expect($waiting->fresh()->state)->toBe('waiting');
});

it('resolves nothing when the cancel transition is refused', function () {
    $paper = walkTo(makeTask($this->user), 'ready');
    $dependency = waitFor(waitingTask($this->user), $paper);

    // Cancelled needs a reason (422 reason_required).
    $this->postJson("/api/v1/tasks/{$paper->id}/transitions", ['to' => 'cancelled'])->assertUnprocessable();

    expect($dependency->fresh()->status)->toBe('open');
});

/*
| Criterion: a Waiting task without open dependencies moves to Ready with
| source system in the log.
*/

it('moves a waiting task without open dependencies to ready with source system', function () {
    $paper = makeTask($this->user);
    $waiting = waitingTask($this->user, ['title' => 'Kirim revisi']);
    waitFor($waiting, $paper);

    finish($paper);

    $waiting->refresh();
    $log = lastTransition($waiting);
    expect($waiting->state)->toBe('ready')
        ->and($waiting->state_reason)->toBeNull()
        ->and([$log->from_state, $log->to_state, $log->source])->toBe(['waiting', 'ready', 'system'])
        // The finished task keeps its own source.
        ->and(lastTransition($paper)->source)->toBe('user');
});

it('releases every waiting task that no longer has open dependencies', function () {
    $paper = makeTask($this->user);
    $first = waitingTask($this->user);
    $second = waitingTask($this->user);
    waitFor($first, $paper);
    waitFor($second, $paper);

    finish($paper);

    expect($first->fresh()->state)->toBe('ready')
        ->and($second->fresh()->state)->toBe('ready');
});

it('leaves a dependent task alone when it is not waiting', function (string $state) {
    $paper = makeTask($this->user);
    $dependent = makeTask($this->user);
    if ($state !== 'new') {
        walkTo($dependent, ...match ($state) {
            'ready' => ['ready'],
            'suspended' => ['ready', 'suspended'],
        });
    }
    waitFor($dependent, $paper);
    $logRows = DB::table('state_transitions')->where('task_id', $dependent->id)->count();

    finish($paper);

    expect($dependent->fresh()->state)->toBe($state)
        ->and(DB::table('state_transitions')->where('task_id', $dependent->id)->count())->toBe($logRows);
})->with(['new', 'ready', 'suspended']);

it('leaves a deleted waiting task alone', function () {
    $paper = makeTask($this->user);
    $waiting = waitingTask($this->user);
    $dependency = waitFor($waiting, $paper);
    $waiting->delete();

    finish($paper);

    expect($dependency->fresh()->status)->toBe('resolved')
        ->and(Task::withTrashed()->find($waiting->id)->state)->toBe('waiting');
});

/*
| Criterion: marking an external blocker resolved runs the same check.
*/

it('moves a waiting task to ready when its external blocker is resolved', function () {
    $waiting = waitingTask($this->user);
    $blocker = waitForExternal($waiting);

    $this->patchJson("/api/v1/dependencies/{$blocker->id}", ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('status', 'resolved');

    $log = lastTransition($waiting);
    expect($waiting->fresh()->state)->toBe('ready')
        ->and([$log->from_state, $log->to_state, $log->source])->toBe(['waiting', 'ready', 'system']);
});

it('keeps a waiting task waiting when another blocker is still open', function () {
    $waiting = waitingTask($this->user);
    $blocker = waitForExternal($waiting);
    $other = waitForExternal($waiting, 'Tanda tangan kaprodi');

    $this->patchJson("/api/v1/dependencies/{$blocker->id}", ['status' => 'resolved'])->assertOk();

    expect($waiting->fresh()->state)->toBe('waiting')
        ->and($other->fresh()->status)->toBe('open');
});

it('does not move a task that is not waiting when its blocker is resolved', function () {
    $task = walkTo(makeTask($this->user), 'ready', 'suspended');
    $blocker = waitForExternal($task);

    $this->patchJson("/api/v1/dependencies/{$blocker->id}", ['status' => 'resolved'])->assertOk();

    expect($task->fresh()->state)->toBe('suspended');
});

it('runs the check again when a resolved blocker is resolved again', function () {
    // E.g. the task was put back to Waiting by hand after its only blocker was resolved.
    $waiting = waitingTask($this->user);
    $blocker = waitForExternal($waiting);
    DB::table('dependencies')->where('id', $blocker->id)
        ->update(['status' => 'resolved', 'resolved_at' => '2026-11-01 08:00:00+00']);
    $resolvedAt = DB::table('dependencies')->where('id', $blocker->id)->value('resolved_at');

    $this->patchJson("/api/v1/dependencies/{$blocker->id}", ['status' => 'resolved'])->assertOk();

    expect(DB::table('dependencies')->where('id', $blocker->id)->value('resolved_at'))->toBe($resolvedAt)
        ->and($waiting->fresh()->state)->toBe('ready');
});

/*
| Criterion: a Waiting task that still has other open dependencies stays
| Waiting.
*/

it('keeps a waiting task waiting while another dependency is open', function (string $kind) {
    $paper = makeTask($this->user);
    $waiting = waitingTask($this->user);
    waitFor($waiting, $paper);
    $other = $kind === 'task' ? waitFor($waiting, makeTask($this->user)) : waitForExternal($waiting);
    $logRows = DB::table('state_transitions')->where('task_id', $waiting->id)->count();

    finish($paper);

    expect($waiting->fresh()->state)->toBe('waiting')
        ->and($other->fresh()->status)->toBe('open')
        ->and(DB::table('state_transitions')->where('task_id', $waiting->id)->count())->toBe($logRows);
})->with(['task', 'external']);

/*
| Decision 12 Nov 2026 (R1-07 note): deleting a task releases the
| dependencies waiting for it, so the waiting task is not stuck forever.
*/

it('releases the tasks waiting for a deleted task', function () {
    $paper = makeTask($this->user);
    $waiting = waitingTask($this->user);
    $stillBlocked = waitingTask($this->user);
    $dependency = waitFor($waiting, $paper);
    waitFor($stillBlocked, $paper);
    waitForExternal($stillBlocked);

    $this->deleteJson("/api/v1/tasks/{$paper->id}")->assertNoContent();

    $log = lastTransition($waiting);
    expect($dependency->fresh()->status)->toBe('resolved')
        ->and($waiting->fresh()->state)->toBe('ready')
        ->and([$log->from_state, $log->to_state, $log->source])->toBe(['waiting', 'ready', 'system'])
        ->and($stillBlocked->fresh()->state)->toBe('waiting')
        ->and(Task::withTrashed()->find($paper->id)->trashed())->toBeTrue();
});
