<?php

use App\Models\Task;
use App\Services\TaskStateMachine;
use Illuminate\Support\Facades\DB;

// R1-09 criterion 1: TaskStateMachine reads allowed_transitions (cached per
// request); no transition list is copied into code (CLAUDE.md rule 2).

it('allows exactly the transitions in the allowed_transitions table', function () {
    $machine = new TaskStateMachine;
    $rows = DB::table('allowed_transitions')->get()
        ->map(fn ($r) => "{$r->from_state}>{$r->to_state}")->all();

    foreach (Task::STATES as $from) {
        foreach (Task::STATES as $to) {
            expect($machine->allows($from, $to))->toBe(in_array("{$from}>{$to}", $rows, true), "{$from} -> {$to}");
        }
    }
});

it('follows changes to the table instead of a copied list', function () {
    DB::table('allowed_transitions')->where(['from_state' => 'done', 'to_state' => 'ready'])->delete();
    DB::table('allowed_transitions')->insert(['from_state' => 'new', 'to_state' => 'running']);

    $machine = new TaskStateMachine;

    expect($machine->allows('done', 'ready'))->toBeFalse()
        ->and($machine->allows('new', 'running'))->toBeTrue()
        ->and($machine->targetsFrom('done'))->toBe([]);
});

it('lists the targets of a state in lifecycle order', function () {
    expect((new TaskStateMachine)->targetsFrom('ready'))->toBe(['running', 'suspended', 'cancelled'])
        ->and((new TaskStateMachine)->targetsFrom('waiting'))->toBe(['ready', 'suspended', 'cancelled']);
});

it('reads the table once per request', function () {
    DB::enableQueryLog();

    $machine = app(TaskStateMachine::class);
    $machine->allows('new', 'ready');
    $machine->allows('ready', 'running');
    app(TaskStateMachine::class)->targetsFrom('done');

    $reads = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'allowed_transitions'));
    expect($reads)->toHaveCount(1)
        ->and(app(TaskStateMachine::class))->toBe($machine);
});

it('explains a rejected transition in Indonesian, suggesting the step in between', function () {
    $machine = new TaskStateMachine;

    expect($machine->rejectionMessage('new', 'running'))
        ->toBe('Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu.')
        ->and($machine->rejectionMessage('suspended', 'running'))
        ->toBe('Task berstatus Suspended tidak bisa langsung Running. Pindahkan ke Ready dulu.')
        ->and($machine->rejectionMessage('waiting', 'done'))
        ->toBe('Task berstatus Waiting tidak bisa pindah ke Done. Status yang bisa dituju: Ready, Suspended, Cancelled.')
        ->and($machine->rejectionMessage('ready', 'ready'))
        ->toBe('Task sudah berstatus Ready.');
});
