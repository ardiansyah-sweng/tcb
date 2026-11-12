<?php

use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

// R1-11: atomic context switch and WIP limit (FR-06).
// Contract: POST /switch, SwitchRequest, WipFullError in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/** A task in Running with an open work session, started through the API. */
function runningTask(User $owner, array $attributes = []): Task
{
    $task = walkTo(makeTask($owner, $attributes), 'ready');
    test()->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])->assertOk();

    return $task->fresh();
}

function recommendationFor(Task $task, int $rank = 1): Recommendation
{
    return Recommendation::create([
        'owner_id' => $task->owner_id, 'task_id' => $task->id,
        'batch_id' => (string) Str::uuid(), 'rank' => $rank, 'score' => 0.5,
        'breakdown' => ['goal' => 0.5],
    ])->fresh();
}

/** Everything a switch may touch, to prove a failed switch changed nothing. */
function switchSnapshot(Task ...$tasks): array
{
    $ids = array_map(fn (Task $t) => $t->id, $tasks);

    return [
        'tasks' => DB::table('tasks')->whereIn('id', $ids)->orderBy('id')
            ->get(['id', 'state', 'state_reason', 'next_step', 'unit_done', 'time_spent_minutes', 'switch_count'])
            ->map(fn ($r) => (array) $r)->all(),
        'transitions' => DB::table('state_transitions')->whereIn('task_id', $ids)->count(),
        'contexts' => DB::table('context_snapshots')->whereIn('task_id', $ids)->count(),
        'sessions' => DB::table('work_sessions')->whereIn('task_id', $ids)
            ->orderBy('id')->get(['id', 'task_id', 'ended_at'])->map(fn ($r) => (array) $r)->all(),
        'recommendations' => DB::table('recommendations')->whereIn('task_id', $ids)
            ->orderBy('id')->get(['id', 'decision', 'decided_at'])->map(fn ($r) => (array) $r)->all(),
    ];
}

/*
| Criterion: POST /switch with {from_task_id, from_to, context, to_task_id,
| reason?, recommendation_id?} runs two transitions in one transaction.
*/

it('leaves the running task with its context and starts the target', function () {
    $from = runningTask($this->user, ['title' => 'Koreksi UTS', 'unit_total' => 40, 'unit_name' => 'lembar']);
    $to = walkTo(makeTask($this->user, ['title' => 'Menulis paper']), 'ready');

    $response = $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key,
        'from_to' => 'ready',
        'context' => ['last_note' => '22 dari 40 lembar', 'next_step' => 'Skor parsial soal 3', 'unit_done' => 22],
        'to_task_id' => $to->task_key,
    ])->assertOk();

    expect(array_keys($response->json()))->toBe(['left', 'started'])
        ->and($response->json('left.task_key'))->toBe($from->task_key)
        ->and($response->json('left.state'))->toBe('ready')
        ->and($response->json('left.next_step'))->toBe('Skor parsial soal 3')
        ->and($response->json('left.switch_count'))->toBe(1)
        ->and($response->json('left'))->not->toHaveKey('latest_context')
        ->and($response->json('started.task_key'))->toBe($to->task_key)
        ->and($response->json('started.state'))->toBe('running')
        ->and($response->json('started.current_session_started_at'))->not->toBeNull()
        ->and($response->json('started'))->toHaveKey('latest_context');

    $from->refresh();
    expect($from->state)->toBe('ready')
        ->and((float) $from->unit_done)->toBe(22.0)
        ->and($from->latestContext->last_note)->toBe('22 dari 40 lembar')
        ->and(DB::table('work_sessions')->where('task_id', $from->id)->whereNull('ended_at')->count())->toBe(0)
        ->and(DB::table('work_sessions')->where('task_id', $to->id)->whereNull('ended_at')->count())->toBe(1)
        ->and($to->fresh()->state)->toBe('running');
});

it('logs both transitions in the same transaction with source user', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => (string) $from->id, 'from_to' => 'ready', 'context' => (object) [], 'to_task_id' => (string) $to->id,
    ])->assertOk();

    $left = DB::table('state_transitions')->where('task_id', $from->id)->orderByDesc('id')->first();
    $started = DB::table('state_transitions')->where('task_id', $to->id)->orderByDesc('id')->first();

    expect([$left->from_state, $left->to_state])->toBe(['running', 'ready'])
        ->and([$started->from_state, $started->to_state])->toBe(['ready', 'running'])
        ->and([$left->source, $started->source])->toBe(['user', 'user'])
        // now() is the transaction start time, so one transaction shares one timestamp.
        ->and($left->occurred_at)->toBe($started->occurred_at);
});

it('moves the left task to waiting with its reason', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'waiting', 'reason' => 'Menunggu nilai dari asisten',
        'context' => ['blocker' => 'Nilai praktikum belum masuk'], 'to_task_id' => $to->task_key,
    ])->assertOk()
        ->assertJsonPath('left.state', 'waiting')
        ->assertJsonPath('left.state_reason', 'Menunggu nilai dari asisten')
        ->assertJsonPath('started.state', 'running');
});

it('defaults from_to to ready', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', ['from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => $to->task_key])
        ->assertOk()
        ->assertJsonPath('left.state', 'ready');
});

it('accepts task keys in any case', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => strtolower($from->task_key), 'from_to' => 'ready', 'context' => (object) [], 'to_task_id' => strtolower($to->task_key),
    ])->assertOk();
});

it('switches between running tasks when the WIP limit allows several', function () {
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 2]);
    $from = runningTask($this->user);
    $other = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'ready', 'context' => (object) [], 'to_task_id' => $to->task_key,
    ])->assertOk();

    expect(Task::where('state', 'running')->orderBy('id')->pluck('id')->all())->toBe([$other->id, $to->id]);
});

/*
| Criterion: a failure at any step changes nothing (tested with a target that
| is not Ready).
*/

it('changes nothing when the target is not ready', function (string $state) {
    // Walk the target first: its path may pass through Running.
    $to = makeTask($this->user);
    if ($state !== 'new') {
        walkTo($to, ...match ($state) {
            'waiting' => ['ready', 'running', 'waiting'],
            'suspended' => ['ready', 'suspended'],
            'done' => ['ready', 'running', 'done'],
            'cancelled' => ['cancelled'],
        });
    }
    $from = runningTask($this->user, ['unit_total' => 40]);
    $rec = recommendationFor($to);
    $before = switchSnapshot($from, $to);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'ready',
        'context' => ['last_note' => 'Sampai lembar 22', 'next_step' => 'Lanjut lembar 23', 'unit_done' => 22],
        'to_task_id' => $to->task_key, 'recommendation_id' => $rec->id,
    ])->assertConflict()
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('details.from', $state)
        ->assertJsonPath('details.to', 'running');

    expect(switchSnapshot($from, $to))->toBe($before)
        ->and($from->fresh()->state)->toBe('running');
})->with(['new', 'waiting', 'suspended', 'done', 'cancelled']);

it('changes nothing when the left task needs a reason it did not get', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $before = switchSnapshot($from, $to);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'waiting', 'context' => ['last_note' => 'x'], 'to_task_id' => $to->task_key,
    ])->assertUnprocessable()
        ->assertJsonPath('code', 'reason_required');

    expect(switchSnapshot($from, $to))->toBe($before);
});

it('requires a context for the left task', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $before = switchSnapshot($from, $to);

    $this->postJson('/api/v1/switch', ['from_task_id' => $from->task_key, 'from_to' => 'ready', 'to_task_id' => $to->task_key])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'context_required')
        ->assertJsonValidationErrors('context');

    expect(switchSnapshot($from, $to))->toBe($before);
});

it('refuses a switch from a task that is not running', function (string $state) {
    $from = makeTask($this->user);
    walkTo($from, ...match ($state) {
        'ready' => ['ready'],
        'waiting' => ['ready', 'running', 'waiting'],
    });
    $to = walkTo(makeTask($this->user), 'ready');
    $before = switchSnapshot($from, $to);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'ready', 'context' => (object) [], 'to_task_id' => $to->task_key,
    ])->assertConflict()
        ->assertJsonPath('code', 'conflict')
        ->assertJsonPath('details.from_state', $state);

    expect(switchSnapshot($from, $to))->toBe($before);
})->with(['ready', 'waiting']);

it('rolls back the left task when the database refuses the target', function () {
    // Two tasks run although the limit is now 1 (the trigger only checks on entry),
    // so leaving one still leaves the limit full when the target starts.
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 2]);
    $from = runningTask($this->user, ['title' => 'Koreksi UTS']);
    $other = runningTask($this->user, ['title' => 'Menulis paper']);
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 1]);
    $to = walkTo(makeTask($this->user), 'ready');
    $before = switchSnapshot($from, $other, $to);

    $response = $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'from_to' => 'ready', 'context' => ['last_note' => 'x'], 'to_task_id' => $to->task_key,
    ])->assertConflict()
        ->assertJsonPath('code', 'wip_full')
        ->assertJsonPath('wip_limit', 1);

    // The list is read after the rollback, so the left task is still in it.
    expect(array_column($response->json('running'), 'task_key'))->toBe([$from->task_key, $other->task_key])
        ->and(switchSnapshot($from, $other, $to))->toBe($before);
});

/*
| Request shape.
*/

it('validates the request body', function (array $body, string $field) {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $valid = ['from_task_id' => $from->task_key, 'from_to' => 'ready', 'context' => (object) [], 'to_task_id' => $to->task_key];

    $body = array_map(fn ($v) => match ($v) {
        '{from}' => $from->task_key,
        default => $v,
    }, $body);

    $this->postJson('/api/v1/switch', array_merge($valid, $body))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect($from->fresh()->state)->toBe('running');
})->with([
    'from_task_id missing' => [['from_task_id' => null], 'from_task_id'],
    'from_task_id unknown' => [['from_task_id' => 'TCB-999999'], 'from_task_id'],
    'from_task_id malformed' => [['from_task_id' => 'abc'], 'from_task_id'],
    'to_task_id missing' => [['to_task_id' => null], 'to_task_id'],
    'to_task_id unknown' => [['to_task_id' => '999999'], 'to_task_id'],
    'to_task_id same as from' => [['to_task_id' => '{from}'], 'to_task_id'],
    'from_to unknown' => [['from_to' => 'done'], 'from_to'],
    'context not an object' => [['context' => 'catatan'], 'context'],
    'context link without url' => [['context' => ['links' => [['title' => 'x']]]], 'context.links.0.url'],
    'reason not text' => [['reason' => ['a']], 'reason'],
    'recommendation_id not an integer' => [['recommendation_id' => 'x'], 'recommendation_id'],
    'recommendation_id unknown' => [['recommendation_id' => 999999], 'recommendation_id'],
]);

it('checks context.unit_done against the left task', function () {
    $from = runningTask($this->user, ['unit_total' => 40]);
    $to = walkTo(makeTask($this->user), 'ready');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => ['unit_done' => 41], 'to_task_id' => $to->task_key,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('context.unit_done');
});

it('does not see tasks or recommendations of another owner', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $stranger = User::factory()->create();
    $foreign = walkTo(makeTask($stranger), 'ready');
    $foreignRec = recommendationFor($foreign);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => (string) $foreign->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('to_task_id');

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => $to->task_key, 'recommendation_id' => $foreignRec->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('recommendation_id');

    expect($foreign->fresh()->state)->toBe('ready')
        ->and($foreignRec->fresh()->decision)->toBeNull();
});

it('requires authentication', function () {
    freshRequest();

    $this->postJson('/api/v1/switch', [])->assertUnauthorized();
});

/*
| Criterion: recommendation_id given -> the recommendation decision is
| recorded as picked.
*/

it('records the recommendation as picked', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $rec = recommendationFor($to);
    $sibling = recommendationFor(walkTo(makeTask($this->user), 'ready'), 2);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => $to->task_key, 'recommendation_id' => $rec->id,
    ])->assertOk();

    $rec->refresh();
    expect($rec->decision)->toBe('picked')
        ->and($rec->decided_at)->not->toBeNull()
        ->and($sibling->fresh()->decision)->toBeNull();
});

it('leaves recommendations alone without recommendation_id', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $rec = recommendationFor($to);

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => $to->task_key, 'recommendation_id' => null,
    ])->assertOk();

    expect($rec->fresh()->decision)->toBeNull();
});

it('rejects a recommendation for a different task', function () {
    $from = runningTask($this->user);
    $to = walkTo(makeTask($this->user), 'ready');
    $rec = recommendationFor(walkTo(makeTask($this->user), 'ready'));

    $this->postJson('/api/v1/switch', [
        'from_task_id' => $from->task_key, 'context' => (object) [], 'to_task_id' => $to->task_key, 'recommendation_id' => $rec->id,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors('recommendation_id');

    expect($rec->fresh()->decision)->toBeNull()
        ->and($from->fresh()->state)->toBe('running');
});

/*
| Criterion: entering Running while the WIP limit is full -> 409 wip_full with
| wip_limit and the running tasks.
*/

it('answers wip_full with the limit and every running task', function (int $limit) {
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => $limit]);
    $running = [];
    for ($i = 0; $i < $limit; $i++) {
        $running[] = runningTask($this->user, ['title' => "Task berjalan {$i}"])->task_key;
    }
    $next = walkTo(makeTask($this->user), 'ready');

    $response = $this->postJson("/api/v1/tasks/{$next->task_key}/transitions", ['to' => 'running'])
        ->assertConflict()
        ->assertJsonPath('code', 'wip_full')
        ->assertJsonPath('wip_limit', $limit);

    expect(array_keys($response->json()))->toBe(['code', 'message', 'wip_limit', 'running'])
        ->and(array_column($response->json('running'), 'task_key'))->toBe($running)
        ->and($response->json('running.0'))->toHaveKeys(['id', 'task_key', 'title', 'state'])
        ->and($next->fresh()->state)->toBe('ready')
        ->and(DB::table('work_sessions')->where('task_id', $next->id)->count())->toBe(0);
})->with([1, 2, 3]);
