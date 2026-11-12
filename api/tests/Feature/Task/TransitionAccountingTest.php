<?php

use App\Models\ContextSnapshot;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkSession;
use App\Services\TransitionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-10: TransitionService keeps the context, work sessions, and time
// accounting (FR-07, FR-08, FR-10; SRS section 12). Contract:
// TransitionRequest.context, TaskDetail.latest_context and
// current_session_started_at, Error code context_required.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/** Move a task to Ready in SQL, then into Running through the API so a session opens. */
function startRunning(Task $task): Task
{
    walkTo($task, 'ready');
    test()->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])->assertOk();

    return $task->fresh();
}

function openSessions(Task $task): int
{
    return DB::table('work_sessions')->where('task_id', $task->id)->whereNull('ended_at')->count();
}

function snapshotCount(Task $task): int
{
    return DB::table('context_snapshots')->where('task_id', $task->id)->count();
}

/*
| Criterion: every state change (endpoint, switch, other services) goes
| through TransitionService, in one transaction, with SET LOCAL tcb.source.
*/

it('refuses a state change on the model outside TransitionService', function () {
    $task = makeTask($this->user);
    $task->forceFill(['state' => 'ready'])->save();
})->throws(LogicException::class, 'TransitionService');

it('still lets the model save other fields', function () {
    $task = makeTask($this->user);
    $task->forceFill(['title' => 'Judul baru', 'next_step' => 'Mulai'])->save();

    expect($task->fresh()->title)->toBe('Judul baru');
});

it('writes the log source given to the service', function (string $source) {
    $task = makeTask($this->user);

    app(TransitionService::class)->transition($task, 'ready', source: $source);

    $last = DB::table('state_transitions')->where('task_id', $task->id)->orderByDesc('id')->first();
    expect($last->source)->toBe($source);
})->with(['user', 'calendar', 'system']);

// One transaction: see the rollback tests at the end of this file.

/*
| Criterion: leaving Running to Ready/Waiting/Suspended without a context -> 422 context_required.
*/

it('requires a context when leaving running for ready or waiting', function (string $to, array $extra) {
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => $to] + $extra)
        ->assertUnprocessable()
        ->assertExactJson([
            'code' => 'context_required',
            'message' => 'Catat konteks dulu sebelum meninggalkan Running: sampai mana dan langkah berikutnya.',
            'errors' => ['context' => ['Catat konteks dulu sebelum meninggalkan Running: sampai mana dan langkah berikutnya.']],
        ]);

    $task->refresh();
    expect($task->state)->toBe('running')
        ->and($task->switch_count)->toBe(0)
        ->and(openSessions($task))->toBe(1)
        ->and(snapshotCount($task))->toBe(0);
})->with([
    'to ready' => ['ready', []],
    'to ready, null context' => ['ready', ['context' => null]],
    'to waiting' => ['waiting', ['reason' => 'Menunggu data asisten']],
]);

it('asks for the context of a suspend from running as well', function () {
    // running -> suspended is not in allowed_transitions today; the rule still holds if it is added.
    DB::table('allowed_transitions')->insert(['from_state' => 'running', 'to_state' => 'suspended']);
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'suspended'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'context_required');
});

it('accepts an empty context because every context field is optional', function () {
    $task = startRunning(makeTask($this->user, ['next_step' => 'Soal 3']));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => (object) []])
        ->assertOk()
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('next_step', 'Soal 3')
        ->assertJsonPath('latest_context.last_note', null)
        ->assertJsonPath('latest_context.next_step', 'Soal 3');
});

it('checks the reason before the context', function () {
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'waiting'])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'reason_required');
});

it('does not ask for a context when leaving running for done or cancelled', function (string $to, array $extra) {
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => $to] + $extra)
        ->assertOk()
        ->assertJsonPath('state', $to);

    expect(snapshotCount($task))->toBe(0);
})->with([
    'done' => ['done', []],
    'cancelled' => ['cancelled', ['reason' => 'Tidak jadi']],
]);

it('validates the context fields', function (array $context, string $field) {
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => $context])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($task->fresh()->state)->toBe('running');
})->with([
    'last_note not text' => [['last_note' => ['x']], 'context.last_note'],
    'unit_done negative' => [['unit_done' => -1], 'context.unit_done'],
    'links not a list' => [['links' => 'https://contoh.id'], 'context.links'],
    'link without url' => [['links' => [['title' => 'Draf']]], 'context.links.0.url'],
    'link url invalid' => [['links' => [['url' => 'bukan url']]], 'context.links.0.url'],
]);

it('rejects a context unit_done above the task total', function () {
    $task = startRunning(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 10, 'unit_name' => 'lembar']));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => ['unit_done' => 41]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['context.unit_done' => ['Jumlah selesai (41) tidak boleh melebihi total (40 lembar).']]);

    expect($task->fresh()->state)->toBe('running')
        ->and(snapshotCount($task))->toBe(0);
});

it('rejects a context unit_done when the task has no total', function () {
    $task = startRunning(makeTask($this->user));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => ['unit_done' => 3]])
        ->assertUnprocessable()
        ->assertJsonPath('errors', ['context.unit_done' => ['Isi total satuan dulu sebelum mencatat jumlah selesai.']]);
});

/*
| Criterion: leaving Running: the context snapshot is stored; next_step and
| unit_done of the task come from the context; the work session is closed;
| time_spent_minutes grows; switch_count +1.
*/

it('stores the context and does the accounting when leaving running', function () {
    $task = makeTask($this->user, ['unit_total' => 40, 'unit_done' => 10, 'unit_name' => 'lembar', 'next_step' => 'Soal 1']);
    $this->freezeSecond();
    $task = startRunning($task);
    $this->travel(25)->minutes();

    $response = $this->postJson("/api/v1/tasks/{$task->task_key}/transitions", [
        'to' => 'ready',
        'context' => [
            'last_note' => '22 dari 40 lembar selesai',
            'next_step' => 'Tetapkan skor parsial soal 3',
            'blocker' => null,
            'unit_done' => 22,
            'links' => [['title' => 'Rubrik', 'url' => 'https://contoh.id/rubrik']],
        ],
    ])->assertOk()
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('next_step', 'Tetapkan skor parsial soal 3')
        ->assertJsonPath('progress_unit', ['done' => 22, 'total' => 40, 'name' => 'lembar'])
        ->assertJsonPath('time_spent_minutes', 25)
        ->assertJsonPath('switch_count', 1)
        ->assertJsonPath('current_session_started_at', null)
        ->assertJsonPath('latest_context.last_note', '22 dari 40 lembar selesai')
        ->assertJsonPath('latest_context.next_step', 'Tetapkan skor parsial soal 3')
        ->assertJsonPath('latest_context.unit_done', 22)
        // jsonb keeps its own key order, so compare the fields one by one.
        ->assertJsonCount(1, 'latest_context.links')
        ->assertJsonPath('latest_context.links.0.title', 'Rubrik')
        ->assertJsonPath('latest_context.links.0.url', 'https://contoh.id/rubrik');

    expect(array_keys($response->json('latest_context')))
        ->toBe(['id', 'task_id', 'last_note', 'next_step', 'blocker', 'unit_done', 'links', 'created_at']);

    $snapshot = ContextSnapshot::where('task_id', $task->id)->sole();
    expect($snapshot->owner_id)->toBe($this->user->id)
        ->and($snapshot->blocker)->toBeNull();

    $session = WorkSession::where('task_id', $task->id)->sole();
    expect($session->ended_at)->not->toBeNull()
        ->and($session->duration_minutes)->toBe(25)
        ->and(openSessions($task))->toBe(0);
});

it('adds up time and switches over several rounds', function () {
    $task = makeTask($this->user);
    $this->freezeSecond();
    $task = startRunning($task);
    $this->travel(25)->minutes();
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => ['last_note' => 'Bab 1']])->assertOk();

    $this->travel(2)->hours();
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])->assertOk();
    $this->travel(10)->minutes();
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", [
        'to' => 'waiting',
        'reason' => 'Menunggu komentar rekan penulis',
        'context' => ['last_note' => 'Bab 2', 'blocker' => 'Komentar belum masuk'],
    ])->assertOk()
        ->assertJsonPath('time_spent_minutes', 35)
        ->assertJsonPath('switch_count', 2)
        ->assertJsonPath('latest_context.blocker', 'Komentar belum masuk');

    expect(WorkSession::where('task_id', $task->id)->orderBy('id')->pluck('duration_minutes')->all())->toBe([25, 10])
        ->and(snapshotCount($task))->toBe(2);
});

it('keeps next_step and unit_done that the context leaves out', function () {
    $task = startRunning(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 10, 'next_step' => 'Soal 1']));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => ['last_note' => 'Istirahat']])
        ->assertOk()
        ->assertJsonPath('next_step', 'Soal 1')
        ->assertJsonPath('progress_unit.done', 10)
        // The snapshot holds the registers as they were when the task was left.
        ->assertJsonPath('latest_context.next_step', 'Soal 1')
        ->assertJsonPath('latest_context.unit_done', 10);
});

it('clears next_step when the context sends null', function () {
    $task = startRunning(makeTask($this->user, ['next_step' => 'Soal 1']));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'context' => ['next_step' => null]])
        ->assertOk()
        ->assertJsonPath('next_step', null);
});

/*
| Criterion: to Done/Cancelled from Running: the session is closed and the
| time added; switch_count does not grow.
*/

it('closes the session without counting a switch when finishing', function (string $to, array $extra) {
    $task = makeTask($this->user);
    $this->freezeSecond();
    $task = startRunning($task);
    $this->travel(40)->minutes();

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => $to] + $extra)
        ->assertOk()
        ->assertJsonPath('time_spent_minutes', 40)
        ->assertJsonPath('switch_count', 0)
        ->assertJsonPath('current_session_started_at', null);

    expect(openSessions($task))->toBe(0);
})->with([
    'done' => ['done', []],
    'cancelled' => ['cancelled', ['reason' => 'Ujian dibatalkan']],
]);

it('stores a context sent with done', function () {
    $task = startRunning(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 30]));

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'done', 'context' => ['last_note' => 'Semua lembar selesai', 'unit_done' => 40]])
        ->assertOk()
        ->assertJsonPath('progress_unit.done', 40)
        ->assertJsonPath('switch_count', 0)
        ->assertJsonPath('latest_context.last_note', 'Semua lembar selesai');
});

it('does not touch time or switches outside running', function () {
    $task = walkTo(makeTask($this->user), 'ready');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'suspended'])
        ->assertOk()
        ->assertJsonPath('time_spent_minutes', 0)
        ->assertJsonPath('switch_count', 0);

    expect(DB::table('work_sessions')->where('task_id', $task->id)->count())->toBe(0);
});

/*
| Criterion: entering Running opens a new work session.
*/

it('opens a work session when the task starts running', function () {
    $task = walkTo(makeTask($this->user), 'ready');
    $this->freezeSecond();

    $response = $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])
        ->assertOk()
        ->assertJsonPath('state', 'running');

    $session = WorkSession::where('task_id', $task->id)->sole();
    expect($session->ended_at)->toBeNull()
        ->and($session->owner_id)->toBe($this->user->id)
        ->and($session->started_at->equalTo(now()))->toBeTrue()
        ->and(Carbon\Carbon::parse($response->json('current_session_started_at'))->equalTo(now()))->toBeTrue();

    $this->getJson("/api/v1/tasks/{$task->task_key}")
        ->assertJsonPath('current_session_started_at', $response->json('current_session_started_at'));
});

it('shows no session and no context on a task that never ran', function () {
    $task = makeTask($this->user);

    $this->getJson("/api/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('latest_context', null)
        ->assertJsonPath('current_session_started_at', null);
});

/*
| Criterion: when one step fails, no open session is left behind and no
| context is half saved.
*/

it('rolls back the snapshot and the closed session when the database refuses the state', function () {
    $task = startRunning(makeTask($this->user, ['next_step' => 'Soal 1']));
    skipAppChecks();

    // running -> new passes the application check but not the database trigger.
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'new', 'context' => ['last_note' => 'x', 'next_step' => 'Soal 2']])
        ->assertConflict()
        ->assertJsonPath('code', 'invalid_transition');

    $task->refresh();
    expect($task->state)->toBe('running')
        ->and($task->next_step)->toBe('Soal 1')
        ->and($task->time_spent_minutes)->toBe(0)
        ->and($task->switch_count)->toBe(0)
        ->and(snapshotCount($task))->toBe(0)
        ->and(openSessions($task))->toBe(1);
});

it('rolls back the snapshot when the task update breaks a check', function () {
    $task = startRunning(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 10]));

    // The request validation is skipped here: the service is called directly.
    try {
        app(TransitionService::class)->transition($task, 'ready', context: ['last_note' => 'x', 'unit_done' => 99]);
        $this->fail('Expected chk_tasks_unit_le_total');
    } catch (QueryException $e) {
        expect($e->getMessage())->toContain('chk_tasks_unit_le_total');
    }

    $task->refresh();
    expect($task->state)->toBe('running')
        ->and($task->unit_done)->toBe(10.0)
        ->and(snapshotCount($task))->toBe(0)
        ->and(openSessions($task))->toBe(1)
        ->and(DB::table('work_sessions')->where('task_id', $task->id)->count())->toBe(1);
});

it('opens no session and stores no context when the WIP limit refuses running', function () {
    startRunning(makeTask($this->user));
    $next = walkTo(makeTask($this->user), 'ready');

    $this->postJson("/api/v1/tasks/{$next->id}/transitions", ['to' => 'running', 'context' => ['last_note' => 'Mulai bab 3']])
        ->assertConflict()
        ->assertJsonPath('code', 'wip_full');

    expect($next->fresh()->state)->toBe('ready')
        ->and(DB::table('work_sessions')->where('task_id', $next->id)->count())->toBe(0)
        ->and(snapshotCount($next))->toBe(0);
});
