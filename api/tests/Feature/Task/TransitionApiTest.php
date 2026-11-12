<?php

use App\Models\Task;
use App\Models\User;
use App\Services\TransitionService;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-09: state machine and transition endpoints (FR-05, FR-09).
// Contract: /tasks/{taskId}/transitions, TransitionRequest, Transition,
// Error, and WipFullError in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function transitionLog(Task $task): array
{
    return DB::table('state_transitions')->where('task_id', $task->id)->orderBy('id')
        ->get()->map(fn ($r) => [$r->from_state, $r->to_state])->all();
}

/*
| Criterion: POST /tasks/{id}/transitions with {to, reason?, context?}.
| Criterion: New -> Ready needs only the title (SRS v1.1).
*/

it('moves a new task with only a title to ready', function () {
    $task = makeTask($this->user, ['title' => 'Koreksi UTS']);

    $this->postJson("/api/v1/tasks/{$task->task_key}/transitions", ['to' => 'ready'])
        ->assertOk()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('state_reason', null)
        ->assertJsonPath('subtasks', [])
        ->assertJsonPath('open_dependencies', []);

    $task->refresh();
    expect($task->state)->toBe('ready')
        ->and($task->ready_since)->not->toBeNull()
        ->and(transitionLog($task))->toBe([[null, 'new'], ['new', 'ready']]);
});

it('accepts the task by numeric id or lowercase key', function (callable $ref) {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$ref($task)}/transitions", ['to' => 'ready'])
        ->assertOk()
        ->assertJsonPath('state', 'ready');
})->with([
    'id' => [fn (Task $t) => $t->id],
    'lowercase key' => [fn (Task $t) => strtolower($t->task_key)],
]);

it('stores the reason with the state and in the log', function (array $path, string $to) {
    $task = walkTo(makeTask($this->user), ...$path);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", [
        'to' => $to,
        'reason' => '  Menunggu nilai praktikum dari asisten  ',
        // Leaving Running needs a context (R1-10); the other states accept one.
        'context' => ['last_note' => 'Nilai teori sudah masuk semua'],
    ])->assertOk()
        ->assertJsonPath('state', $to)
        ->assertJsonPath('state_reason', 'Menunggu nilai praktikum dari asisten');

    $last = DB::table('state_transitions')->where('task_id', $task->id)->orderByDesc('id')->first();
    expect($last->to_state)->toBe($to)
        ->and($last->reason)->toBe('Menunggu nilai praktikum dari asisten')
        ->and($last->source)->toBe('user');
})->with([
    'running to waiting' => [['ready', 'running'], 'waiting'],
    'new to cancelled' => [[], 'cancelled'],
    'ready to suspended' => [['ready'], 'suspended'],
]);

it('clears the old reason when the task becomes ready again', function () {
    $task = walkTo(makeTask($this->user), 'ready', 'running', 'waiting');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready'])
        ->assertOk()
        ->assertJsonPath('state', 'ready')
        ->assertJsonPath('state_reason', null);
});

it('accepts context from the contract', function () {
    // Storing the context is covered in TransitionAccountingTest (R1-10).
    $task = walkTo(makeTask($this->user), 'ready', 'running');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", [
        'to' => 'ready',
        'context' => ['last_note' => '22 dari 40 lembar', 'next_step' => 'Soal 3'],
    ])->assertOk()->assertJsonPath('state', 'ready');
});

it('stores review_on when suspending and shows it on the task', function (array $path) {
    $task = walkTo(makeTask($this->user), ...$path);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", [
        'to' => 'suspended',
        'review_on' => '2026-12-01',
    ])->assertOk()
        ->assertJsonPath('state', 'suspended')
        ->assertJsonPath('review_on', '2026-12-01');

    $this->getJson("/api/v1/tasks/{$task->task_key}")->assertJsonPath('review_on', '2026-12-01');
    $this->getJson('/api/v1/tasks')->assertJsonPath('data.0.review_on', '2026-12-01');
})->with([
    'from ready' => [['ready']],
    'from waiting' => [['ready', 'running', 'waiting']],
]);

it('suspends without a review date', function () {
    $task = walkTo(makeTask($this->user), 'ready');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'suspended', 'review_on' => null])
        ->assertOk()
        ->assertJsonPath('review_on', null);
});

it('clears review_on when the task leaves suspended', function (string $to, array $extra) {
    $task = walkTo(makeTask($this->user), 'ready');
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'suspended', 'review_on' => '2026-12-01'])->assertOk();

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => $to] + $extra)
        ->assertOk()
        ->assertJsonPath('state', $to)
        ->assertJsonPath('review_on', null);
})->with([
    'to ready' => ['ready', []],
    'to cancelled' => ['cancelled', ['reason' => 'Tidak jadi']],
]);

it('rejects review_on for a state other than suspended', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready', 'review_on' => '2026-12-01'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.review_on.0', 'Tanggal tinjau ulang hanya untuk status Suspended.');

    expect($task->fresh()->state)->toBe('new');
});

it('does not accept review_on through PATCH /tasks', function () {
    $task = walkTo(makeTask($this->user), 'ready', 'suspended');

    $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Judul baru', 'review_on' => '2026-12-01'])
        ->assertOk()
        ->assertJsonPath('review_on', null);
});

it('maps the review_on check from the database to a 422', function () {
    $task = walkTo(makeTask($this->user), 'ready');

    try {
        DB::transaction(fn () => DB::table('tasks')->where('id', $task->id)->update(['review_on' => '2026-12-01']));
        $this->fail('Expected a check violation');
    } catch (QueryException $e) {
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/tasks', 'PATCH'), $e);
    }

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['errors']['review_on'][0])
        ->toBe('Tanggal tinjau ulang hanya untuk status Suspended.');
});

it('validates the request body', function (array $body, string $field) {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect($task->fresh()->state)->toBe('new');
})->with([
    'to missing' => [[], 'to'],
    'to unknown' => [['to' => 'paused'], 'to'],
    'reason not text' => [['to' => 'cancelled', 'reason' => ['x']], 'reason'],
    'context not an object' => [['to' => 'ready', 'context' => 'catatan'], 'context'],
    'review_on not a date' => [['to' => 'ready', 'review_on' => 'besok'], 'review_on'],
]);

it('returns 404 for a task of another owner or a deleted task', function () {
    $other = makeTask(User::factory()->create());
    $deleted = makeTask($this->user);
    $deleted->delete();

    foreach ([$other, $deleted] as $task) {
        $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready'])
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
        $this->getJson("/api/v1/tasks/{$task->id}/transitions")->assertNotFound();
    }
});

/*
| Criterion: invalid transition -> 409 invalid_transition with an Indonesian message.
*/

it('rejects an invalid transition with 409 and a helpful message', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])
        ->assertConflict()
        ->assertExactJson([
            'code' => 'invalid_transition',
            'message' => 'Task berstatus New tidak bisa langsung Running. Pindahkan ke Ready dulu.',
            'details' => ['from' => 'new', 'to' => 'running', 'allowed' => ['ready', 'cancelled']],
        ]);

    expect($task->fresh()->state)->toBe('new')
        ->and(transitionLog($task))->toBe([[null, 'new']]);
});

it('rejects a transition to the current state', function () {
    $task = walkTo(makeTask($this->user), 'ready');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready'])
        ->assertConflict()
        ->assertJsonPath('code', 'invalid_transition')
        ->assertJsonPath('message', 'Task sudah berstatus Ready.');
});

it('checks the transition before the reason', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'waiting'])
        ->assertConflict()
        ->assertJsonPath('code', 'invalid_transition');
});

/*
| Criterion: Waiting/Cancelled without reason -> 422 reason_required.
*/

it('requires a reason for waiting and cancelled', function (array $path, string $to, array $extra, string $label) {
    $task = walkTo(makeTask($this->user), ...$path);
    $before = $task->state;

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => $to] + $extra)
        ->assertUnprocessable()
        ->assertExactJson([
            'code' => 'reason_required',
            'message' => "Alasan wajib diisi untuk status {$label}.",
            'errors' => ['reason' => ["Alasan wajib diisi untuk status {$label}."]],
        ]);

    expect($task->fresh()->state)->toBe($before);
})->with([
    'waiting, no reason' => [['ready', 'running'], 'waiting', [], 'Waiting'],
    'waiting, blank reason' => [['ready', 'running'], 'waiting', ['reason' => '   '], 'Waiting'],
    'cancelled, null reason' => [['ready'], 'cancelled', ['reason' => null], 'Cancelled'],
]);

/*
| Criterion: Running -> Done is rejected (409) while a subtask is not Done or Cancelled.
*/

it('rejects done while a subtask is still open', function () {
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 3]);
    $parent = walkTo(makeTask($this->user, ['title' => 'Rekap nilai']), 'ready', 'running');
    $open = makeTask($this->user, ['parent_task_id' => $parent->id]);
    walkTo(makeTask($this->user, ['parent_task_id' => $parent->id]), 'ready', 'running', 'done');
    $waiting = walkTo(makeTask($this->user, ['parent_task_id' => $parent->id]), 'ready', 'running', 'waiting');

    $this->postJson("/api/v1/tasks/{$parent->id}/transitions", ['to' => 'done'])
        ->assertConflict()
        ->assertExactJson([
            'code' => 'conflict',
            'message' => "Task belum bisa Done karena masih ada subtask yang belum selesai: {$open->task_key}, {$waiting->task_key}. Selesaikan atau batalkan dulu.",
            'details' => ['open_subtasks' => [$open->task_key, $waiting->task_key]],
        ]);

    expect($parent->fresh()->state)->toBe('running');
});

it('allows done when every subtask is done, cancelled, or deleted', function () {
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 2]);
    $parent = walkTo(makeTask($this->user), 'ready', 'running');
    walkTo(makeTask($this->user, ['parent_task_id' => $parent->id]), 'ready', 'running', 'done');
    walkTo(makeTask($this->user, ['parent_task_id' => $parent->id]), 'cancelled');
    makeTask($this->user, ['parent_task_id' => $parent->id])->delete();

    $this->postJson("/api/v1/tasks/{$parent->id}/transitions", ['to' => 'done'])
        ->assertOk()
        ->assertJsonPath('state', 'done');
});

/*
| Criterion: GET /tasks/{id}/transitions returns the log, newest first, with its source.
*/

it('lists the transition log newest first with its source', function () {
    $task = makeTask($this->user);
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'ready'])->assertOk();
    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'cancelled', 'reason' => 'Ujian diundur'])->assertOk();
    app(TransitionService::class)->transition($task->fresh(), 'ready', null, 'system');

    $response = $this->getJson("/api/v1/tasks/{$task->task_key}/transitions")->assertOk();

    $rows = DB::table('state_transitions')->where('task_id', $task->id)->orderByDesc('id')->get();
    expect($response->json('data'))->toHaveCount(4);
    expect(collect($response->json('data'))->map(fn ($t) => [$t['id'], $t['from_state'], $t['to_state'], $t['reason'], $t['source']])->all())
        ->toBe([
            [$rows[0]->id, 'cancelled', 'ready', null, 'system'],
            [$rows[1]->id, 'ready', 'cancelled', 'Ujian diundur', 'user'],
            [$rows[2]->id, 'new', 'ready', null, 'user'],
            [$rows[3]->id, null, 'new', null, 'user'],
        ]);
    expect(array_keys($response->json('data.0')))
        ->toBe(['id', 'from_state', 'to_state', 'reason', 'source', 'occurred_at']);
});

it('rejects an unknown source', function () {
    app(TransitionService::class)->transition(makeTask($this->user), 'ready', null, 'robot');
})->throws(InvalidArgumentException::class);

/*
| Criterion: database errors (invalid_transition, wip_full, chk_*) map to the
| same API responses, so the rules hold even when application checks are skipped.
*/

it('maps the database invalid_transition error to the same 409', function () {
    $checked = makeTask($this->user);
    $unchecked = makeTask($this->user);

    $expected = $this->postJson("/api/v1/tasks/{$checked->id}/transitions", ['to' => 'running'])
        ->assertConflict()->json();

    skipAppChecks();

    $this->postJson("/api/v1/tasks/{$unchecked->id}/transitions", ['to' => 'running'])
        ->assertConflict()
        ->assertExactJson($expected);

    expect($unchecked->fresh()->state)->toBe('new')
        ->and(transitionLog($unchecked))->toBe([[null, 'new']]);
});

it('maps the database reason check to the same 422', function () {
    DB::table('users')->where('id', $this->user->id)->update(['wip_limit' => 2]);
    $checked = walkTo(makeTask($this->user), 'ready', 'running');
    $unchecked = walkTo(makeTask($this->user), 'ready', 'running');

    $body = ['to' => 'waiting', 'context' => ['last_note' => 'Bab 2 selesai']];
    $expected = $this->postJson("/api/v1/tasks/{$checked->id}/transitions", $body)
        ->assertUnprocessable()->json();

    skipAppChecks();

    $this->postJson("/api/v1/tasks/{$unchecked->id}/transitions", $body)
        ->assertUnprocessable()
        ->assertExactJson($expected);

    expect($unchecked->fresh()->state)->toBe('running');
});

it('maps the database wip_full error to 409 with the running tasks', function () {
    $running = walkTo(makeTask($this->user, ['title' => 'Menulis paper']), 'ready', 'running');
    $next = walkTo(makeTask($this->user), 'ready');

    $response = $this->postJson("/api/v1/tasks/{$next->id}/transitions", ['to' => 'running'])
        ->assertConflict()
        ->assertJsonPath('code', 'wip_full')
        ->assertJsonPath('wip_limit', 1)
        ->assertJsonPath('running.0.task_key', $running->task_key)
        ->assertJsonCount(1, 'running');

    expect(array_keys($response->json()))->toBe(['code', 'message', 'wip_limit', 'running'])
        ->and($response->json('message'))->toBe('WIP limit penuh: sudah ada 1 task Running. Simpan konteks task yang sedang berjalan lalu pindah, atau selesaikan dulu.')
        ->and($next->fresh()->state)->toBe('ready');
});

it('maps database errors raised outside the endpoint as well', function (callable $act, int $status, string $code) {
    $task = makeTask($this->user);

    try {
        DB::transaction(fn () => $act($this, $task));
        $this->fail('Expected the database to reject the statement');
    } catch (QueryException $e) {
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/tasks', 'POST'), $e);
    }

    expect($response->getStatusCode())->toBe($status)
        ->and(json_decode($response->getContent(), true)['code'])->toBe($code);
})->with([
    'invalid_transition' => [fn ($t, Task $task) => setState($task, 'done'), 409, 'invalid_transition'],
    'chk_tasks_reason_required' => [fn ($t, Task $task) => setState($task, 'cancelled'), 422, 'reason_required'],
    'wip_full' => [function ($t, Task $task) {
        walkTo(makeTask($t->user), 'ready', 'running');
        walkTo($task, 'ready', 'running');
    }, 409, 'wip_full'],
]);

it('maps the own-parent check to a 422 on parent_task_id', function () {
    $task = makeTask($this->user);

    try {
        DB::transaction(fn () => DB::table('tasks')->where('id', $task->id)->update(['parent_task_id' => $task->id]));
        $this->fail('Expected a check violation');
    } catch (QueryException $e) {
        $response = app(ExceptionHandler::class)->render(Request::create('/api/v1/tasks', 'PATCH'), $e);
    }

    expect($response->getStatusCode())->toBe(422)
        ->and(json_decode($response->getContent(), true)['errors'])->toHaveKey('parent_task_id');
});
