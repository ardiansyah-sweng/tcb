<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// R1-02: rules on tasks enforced by PostgreSQL (task_key, state machine,
// WIP limit, CHECK constraints). The database is the last line of defence.

describe('task_key', function () {
    it('assigns TCB-1, TCB-2, ... in order per user', function () {
        $user = User::factory()->create();

        $keys = collect(range(1, 3))->map(fn () => makeTask($user)->task_key)->all();

        expect($keys)->toBe(['TCB-1', 'TCB-2', 'TCB-3']);
        expect($user->fresh()->next_task_seq)->toBe(4);
    });

    it('keeps a separate sequence for each user', function () {
        $a = User::factory()->create();
        $b = User::factory()->create();

        makeTask($a);
        makeTask($a);

        expect(makeTask($b)->task_key)->toBe('TCB-1');
        expect(makeTask($a)->task_key)->toBe('TCB-3');
    });

    it('ignores a task_key supplied by the caller', function () {
        $user = User::factory()->create();

        $task = makeTask($user, ['task_key' => 'TCB-999']);

        expect($task->task_key)->toBe('TCB-1');
    });
});

describe('state machine', function () {
    it('starts tasks as new and logs the birth with no from_state', function () {
        $task = makeTask(User::factory()->create());

        expect($task->state)->toBe('new');
        expect($task->ready_since)->toBeNull();

        $log = DB::table('state_transitions')->where('task_id', $task->id)->get();
        expect($log)->toHaveCount(1);
        expect($log[0]->from_state)->toBeNull();
        expect($log[0]->to_state)->toBe('new');
    });

    it('sets ready_since when a task is born ready', function () {
        $task = makeTask(User::factory()->create(), ['state' => 'ready']);

        expect($task->ready_since)->not->toBeNull();
    });

    it('rejects an invalid transition with hint invalid_transition', function () {
        $task = makeTask(User::factory()->create());

        expectDbHint(fn () => setState($task, 'running'), 'invalid_transition');
        expectDbHint(fn () => setState($task, 'done'), 'invalid_transition');

        expect($task->fresh()->state)->toBe('new');
    });

    it('accepts a valid transition and updates state_changed_at and ready_since', function () {
        $task = makeTask(User::factory()->create());
        DB::table('tasks')->where('id', $task->id)
            ->update(['state_changed_at' => now()->subDay(), 'ready_since' => null]);

        $ready = walkTo($task, 'ready');
        expect($ready->state)->toBe('ready');
        expect($ready->state_changed_at->isToday())->toBeTrue();
        expect($ready->ready_since)->not->toBeNull();

        $running = walkTo($task, 'running');
        expect($running->ready_since)->toBeNull();
    });

    it('clears state_reason when moving to a state that does not need one', function () {
        $task = walkTo(makeTask(User::factory()->create()), 'ready', 'running', 'waiting');
        expect($task->state_reason)->toBe('alasan uji');

        $task = walkTo($task, 'ready');
        expect($task->state_reason)->toBeNull();
    });
});

describe('WIP limit', function () {
    it('rejects a second running task with hint wip_full when the limit is 1', function () {
        $user = User::factory()->create();
        walkTo(makeTask($user), 'ready', 'running');
        $second = walkTo(makeTask($user), 'ready');

        expectDbHint(fn () => setState($second, 'running'), 'wip_full');

        expect($second->fresh()->state)->toBe('ready');
    });

    it('allows as many running tasks as the user limit', function () {
        $user = User::factory()->create(['wip_limit' => 2]);
        walkTo(makeTask($user), 'ready', 'running');
        $second = walkTo(makeTask($user), 'ready', 'running');

        expect($second->state)->toBe('running');
    });

    it('counts running tasks per user only', function () {
        walkTo(makeTask(User::factory()->create()), 'ready', 'running');

        $other = walkTo(makeTask(User::factory()->create()), 'ready', 'running');

        expect($other->state)->toBe('running');
    });

    it('does not count soft-deleted running tasks', function () {
        $user = User::factory()->create();
        $old = walkTo(makeTask($user), 'ready', 'running');
        $old->delete();

        expect(walkTo(makeTask($user), 'ready', 'running')->state)->toBe('running');
    });

    it('also applies when a task is inserted as running', function () {
        $user = User::factory()->create();
        walkTo(makeTask($user), 'ready', 'running');

        expectDbHint(fn () => makeTask($user, ['state' => 'running']), 'wip_full');
    });

    it('rejects a wip_limit outside 1..3', function () {
        $user = User::factory()->create();

        dbError(fn () => DB::table('users')->where('id', $user->id)->update(['wip_limit' => 4]));
        dbError(fn () => DB::table('users')->where('id', $user->id)->update(['wip_limit' => 0]));
    });
});

describe('CHECK constraints', function () {
    it('requires a reason for waiting and cancelled', function (array $path, string $to) {
        $task = walkTo(makeTask(User::factory()->create()), ...$path);

        expectDbConstraint(fn () => setState($task, $to), 'chk_tasks_reason_required');
        expectDbConstraint(fn () => setState($task, $to, '   '), 'chk_tasks_reason_required');

        expect(walkTo($task, $to)->state)->toBe($to);
    })->with([
        'waiting' => [['ready', 'running'], 'waiting'],
        'cancelled' => [[], 'cancelled'],
    ]);

    it('rejects unit progress above the total', function () {
        $user = User::factory()->create();

        expectDbConstraint(
            fn () => makeTask($user, ['unit_total' => 40, 'unit_done' => 41]),
            'chk_tasks_unit_le_total',
        );

        $task = makeTask($user, ['unit_total' => 40, 'unit_done' => 40]);
        expectDbConstraint(
            fn () => DB::table('tasks')->where('id', $task->id)->update(['unit_done' => 40.5]),
            'chk_tasks_unit_le_total',
        );
    });

    it('rejects unit progress without a total', function () {
        expectDbConstraint(
            fn () => makeTask(User::factory()->create(), ['unit_done' => 3]),
            'chk_tasks_unit_needs_total',
        );
    });

    it('rejects a blank title', function () {
        dbError(fn () => makeTask(User::factory()->create(), ['title' => '   ']));
    });

    it('rejects a task that is its own parent', function () {
        $task = makeTask(User::factory()->create());

        expectDbConstraint(
            fn () => DB::table('tasks')->where('id', $task->id)->update(['parent_task_id' => $task->id]),
            'chk_tasks_not_own_parent',
        );
    });

    it('rejects a priority outside 1..4', function () {
        dbError(fn () => makeTask(User::factory()->create(), ['priority' => 5]));
    });

    it('fills defaults so only the title is needed', function () {
        $user = User::factory()->create();
        $id = DB::table('tasks')->insertGetId(['owner_id' => $user->id, 'title' => 'Koreksi UTS']);

        $task = Task::find($id);
        expect($task->state)->toBe('new');
        expect($task->priority)->toBe(2);
        expect($task->energy)->toBe('focus');
        expect($task->deadline_source)->toBe('manual');
        expect($task->time_spent_minutes)->toBe(0);
        expect($task->switch_count)->toBe(0);
    });
});

it('sets updated_at on every update', function () {
    $task = makeTask(User::factory()->create());
    DB::table('tasks')->where('id', $task->id)->update(['updated_at' => '2020-01-01 00:00:00+00']);

    DB::table('tasks')->where('id', $task->id)->update(['title' => 'Judul baru']);

    expect($task->fresh()->updated_at->year)->toBeGreaterThan(2020);
});
