<?php

use App\Models\StateTransition;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// R1-02: the transition log is written by trigger, is append-only, and takes
// its source from SET LOCAL tcb.source (CLAUDE.md rule 4).

it('logs every state change with the reason', function () {
    $task = walkTo(makeTask(User::factory()->create()), 'ready', 'running', 'waiting');

    $log = DB::table('state_transitions')->where('task_id', $task->id)->orderBy('id')->get();

    expect($log->map(fn ($r) => [$r->from_state, $r->to_state])->all())->toBe([
        [null, 'new'],
        ['new', 'ready'],
        ['ready', 'running'],
        ['running', 'waiting'],
    ]);
    expect($log->last()->reason)->toBe('alasan uji');
    expect($log->every(fn ($r) => $r->owner_id === $task->owner_id))->toBeTrue();
});

it('does not log updates that leave the state unchanged', function () {
    $task = makeTask(User::factory()->create());

    DB::table('tasks')->where('id', $task->id)->update(['title' => 'Judul lain']);
    setState($task, 'new');

    expect(DB::table('state_transitions')->where('task_id', $task->id)->count())->toBe(1);
});

it('rejects updates to the log', function () {
    $task = makeTask(User::factory()->create());

    $e = dbError(fn () => DB::table('state_transitions')->where('task_id', $task->id)->update(['reason' => 'diubah']));

    expect($e->getMessage())->toContain('append-only');
});

it('rejects deletes from the log', function () {
    $task = makeTask(User::factory()->create());

    $e = dbError(fn () => DB::table('state_transitions')->where('task_id', $task->id)->delete());

    expect($e->getMessage())->toContain('append-only');
    expect(DB::table('state_transitions')->where('task_id', $task->id)->count())->toBe(1);
});

it('keeps the log when a task is soft-deleted', function () {
    $task = walkTo(makeTask(User::factory()->create()), 'ready');

    $task->delete();

    expect($task->fresh()->deleted_at)->not->toBeNull();
    expect(DB::table('state_transitions')->where('task_id', $task->id)->count())->toBe(2);
});

it('records source user by default', function () {
    $task = walkTo(makeTask(User::factory()->create()), 'ready');

    expect(DB::table('state_transitions')->where('task_id', $task->id)->pluck('source')->unique()->all())
        ->toBe(['user']);
});

it('records the source set with SET LOCAL tcb.source', function (string $source) {
    $task = makeTask(User::factory()->create());

    DB::transaction(function () use ($task, $source) {
        DB::statement('SET LOCAL tcb.source = '.DB::getPdo()->quote($source));
        setState($task, 'ready');
    });

    $last = StateTransition::where('task_id', $task->id)->orderByDesc('id')->first();
    expect($last->to_state)->toBe('ready');
    expect($last->source)->toBe($source);
})->with(['user', 'calendar', 'system']);

it('accepts a bound source via set_config(..., true), the parameterised form of SET LOCAL', function () {
    // SET does not accept bind parameters in PostgreSQL ("syntax error at or
    // near $1"), so application code binds the value through set_config with
    // is_local = true, which has the same transaction scope as SET LOCAL.
    $task = makeTask(User::factory()->create());

    DB::transaction(function () use ($task) {
        DB::select("select set_config('tcb.source', ?, true)", ['system']);
        setState($task, 'ready');
    });

    expect(StateTransition::where('task_id', $task->id)->orderByDesc('id')->value('source'))->toBe('system');
});

it('rejects an unknown source', function () {
    $task = makeTask(User::factory()->create());

    dbError(function () use ($task) {
        DB::statement("SET LOCAL tcb.source = 'robot'");
        setState($task, 'ready');
    });

    expect($task->fresh()->state)->toBe('new');
});
