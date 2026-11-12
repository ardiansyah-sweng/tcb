<?php

use App\Models\AllowedTransition;
use App\Models\CalendarLink;
use App\Models\ContextSnapshot;
use App\Models\Dependency;
use App\Models\Integration;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Proposal;
use App\Models\Recommendation;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

// R1-02: an Eloquent model for every table with correct casts
// (jsonb -> array, numeric -> float, timestamptz -> Carbon).

it('casts user settings', function () {
    $user = User::factory()->create()->fresh();

    expect($user->timezone)->toBe('Asia/Jakarta');
    expect($user->wip_limit)->toBe(1);
    // jsonb does not keep key order, so compare sorted.
    expect(collect($user->score_weights)->sortKeys()->all())->toBe(['aging' => 0.15, 'fit' => 0.1, 'goal' => 0.3, 'priority' => 0.2, 'urgency' => 0.25]);
    expect($user->work_hours['mon'])->toBe(['08:00', '17:00']);
    expect($user->default_deadline_offset_minutes)->toBe(1440);
});

it('casts task columns', function () {
    $task = makeTask(User::factory()->create(), [
        'unit_total' => 40, 'unit_done' => 22.5, 'estimate_minutes' => 90,
        'deadline_at' => '2026-11-14 09:00:00+07',
    ]);

    expect($task->unit_total)->toBe(40.0);
    expect($task->unit_done)->toBe(22.5);
    expect($task->estimate_minutes)->toBe(90);
    expect($task->priority)->toBe(2);
    expect($task->deadline_at)->toBeInstanceOf(CarbonInterface::class);
    expect($task->deadline_at->utc()->format('Y-m-d H:i'))->toBe('2026-11-14 02:00');
    expect($task->state_changed_at)->toBeInstanceOf(CarbonInterface::class);
});

it('casts key result numerics to float', function () {
    $user = User::factory()->create();
    $kr = KeyResult::factory()->for($user, 'owner')
        ->for(Objective::factory()->for($user, 'owner'))
        ->create(['weight' => 0.5, 'progress_mode' => 'manual', 'target_value' => 4, 'current_value' => 1.5])
        ->fresh();

    expect($kr->weight)->toBe(0.5);
    expect($kr->target_value)->toBe(4.0);
    expect($kr->current_value)->toBe(1.5);
    expect($kr->start_value)->toBeNull();
    expect($kr->objective->period_start->format('Y-m-d'))->toBeString();
});

it('casts context snapshot links to an array', function () {
    $task = makeTask(User::factory()->create(), ['unit_total' => 40]);

    $snapshot = ContextSnapshot::create([
        'owner_id' => $task->owner_id, 'task_id' => $task->id,
        'last_note' => 'Sampai nomor 3', 'unit_done' => 22,
        'links' => [['title' => 'Draf', 'url' => 'https://example.com/draf']],
    ])->fresh();

    expect($snapshot->links)->toEqual([['title' => 'Draf', 'url' => 'https://example.com/draf']]);
    expect($snapshot->unit_done)->toBe(22.0);
    expect(ContextSnapshot::create(['owner_id' => $task->owner_id, 'task_id' => $task->id])->fresh()->links)->toBe([]);
});

it('casts recommendations: score float, breakdown array, reasons text[] as array', function () {
    $task = makeTask(User::factory()->create());

    $rec = Recommendation::create([
        'owner_id' => $task->owner_id, 'task_id' => $task->id,
        'batch_id' => '7d1f6a4e-7c1a-4b8e-9a51-0c6f9d1b2a10', 'rank' => 1, 'score' => 0.7312,
        'breakdown' => ['goal' => 0.8, 'urgency' => 0.5],
        'reasons' => ['Deadline Sabtu, 14 Nov', 'Prioritas "tinggi", {penting}'],
    ])->fresh();

    expect($rec->score)->toBe(0.7312);
    expect($rec->breakdown)->toBe(['goal' => 0.8, 'urgency' => 0.5]);
    expect($rec->reasons)->toBe(['Deadline Sabtu, 14 Nov', 'Prioritas "tinggi", {penting}']);
    expect($rec->generated_at)->toBeInstanceOf(CarbonInterface::class);
    expect(DB::table('recommendations')->where('id', $rec->id)->value('reasons'))
        ->toBe('{"Deadline Sabtu, 14 Nov","Prioritas \"tinggi\", {penting}"}');
});

it('encrypts integration tokens and casts scopes to an array', function () {
    $user = User::factory()->create();

    $integration = Integration::create([
        'owner_id' => $user->id, 'provider' => 'google_calendar',
        'access_token_enc' => 'ya29.secret', 'scopes' => ['calendar.readonly', 'calendar.events'],
    ])->fresh();

    expect($integration->access_token_enc)->toBe('ya29.secret');
    expect(DB::table('integrations')->value('access_token_enc'))->not->toBe('ya29.secret');
    expect($integration->scopes)->toBe(['calendar.readonly', 'calendar.events']);
});

it('has a model for every remaining table', function () {
    $task = makeTask(User::factory()->create());
    $other = makeTask($task->owner);
    $owner = ['owner_id' => $task->owner_id, 'task_id' => $task->id];

    expect(AllowedTransition::count())->toBe(16);
    expect(StateTransition::where('task_id', $task->id)->first()->occurred_at)->toBeInstanceOf(CarbonInterface::class);

    $session = WorkSession::create($owner)->fresh();
    expect($session->started_at)->toBeInstanceOf(CarbonInterface::class);
    expect($session->duration_minutes)->toBeNull();

    $dep = Dependency::create($owner + ['depends_on_task_id' => $other->id])->fresh();
    expect($dep->status)->toBe('open');
    expect($dep->dependsOn->is($other))->toBeTrue();

    $link = CalendarLink::create($owner + [
        'calendar_id' => 'primary', 'event_id' => 'ev-1', 'kind' => 'deadline_anchor', 'offset_minutes' => 60,
    ])->fresh();
    expect($link->event_deleted)->toBeFalse();
    expect($link->offset_minutes)->toBe(60);

    $proposal = Proposal::create($owner + ['kind' => 'start_task', 'proposed_state' => 'running', 'message' => 'Mulai?'])->fresh();
    expect($proposal->status)->toBe('pending');
    expect($proposal->task->is($task))->toBeTrue();
});

it('soft-deletes tasks', function () {
    $task = makeTask(User::factory()->create());

    $task->delete();

    expect(Task::find($task->id))->toBeNull();
    expect(Task::withTrashed()->find($task->id))->not->toBeNull();
});
