<?php

use App\Http\Controllers\Api\TaskController;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-06: task API (FR-03, FR-04).
// Contract: /tasks and /tasks/{taskId} in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function ownKr(User $owner, array $attributes = []): KeyResult
{
    $objective = Objective::factory()->for($owner, 'owner')->create();

    return KeyResult::factory()->for($owner, 'owner')->for($objective)->create($attributes);
}

/** task_key values of a GET /tasks response, in order. */
function listedKeys($response): array
{
    return array_column($response->assertOk()->json('data'), 'task_key');
}

/*
| Criterion: POST /tasks only requires title; the task is born `new` with an
| automatic task_key.
*/

it('creates a task from a title only', function () {
    $response = $this->postJson('/api/v1/tasks', ['title' => 'Susun soal UAS']);

    $task = Task::where('title', 'Susun soal UAS')->firstOrFail();

    $response->assertCreated()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('task_key', 'TCB-1')
        ->assertJsonPath('title', 'Susun soal UAS')
        ->assertJsonPath('state', 'new')
        ->assertJsonPath('description', null)
        ->assertJsonPath('key_result_id', null)
        ->assertJsonPath('is_orphan', true)
        ->assertJsonPath('parent_task_id', null)
        ->assertJsonPath('label', null)
        ->assertJsonPath('state_reason', null)
        ->assertJsonPath('ready_since', null)
        ->assertJsonPath('priority', 2)
        ->assertJsonPath('deadline_at', null)
        ->assertJsonPath('deadline_source', 'manual')
        ->assertJsonPath('estimate_minutes', null)
        ->assertJsonPath('energy', 'focus')
        ->assertJsonPath('next_step', null)
        ->assertJsonPath('progress_unit', null)
        ->assertJsonPath('time_spent_minutes', 0)
        ->assertJsonPath('switch_count', 0)
        ->assertJsonPath('last_activity_at', null);

    expect($response->json())->toHaveKeys(['state_changed_at', 'created_at', 'updated_at']);
    expect($task->owner_id)->toBe($this->user->id);
    expect(StateTransition::where('task_id', $task->id)->pluck('to_state')->all())->toBe(['new']);
});

it('numbers task keys per owner', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Satu'])->assertJsonPath('task_key', 'TCB-1');
    $this->postJson('/api/v1/tasks', ['title' => 'Dua'])->assertJsonPath('task_key', 'TCB-2');

    freshRequest();
    Sanctum::actingAs(User::factory()->create());

    $this->postJson('/api/v1/tasks', ['title' => 'Milik orang lain'])->assertJsonPath('task_key', 'TCB-1');
});

it('stores the optional fields', function () {
    $kr = ownKr($this->user);

    $this->postJson('/api/v1/tasks', [
        'title' => 'Koreksi UTS kelas A',
        'description' => '40 lembar',
        'key_result_id' => $kr->id,
        'label' => 'Koreksi ujian',
        'priority' => 1,
        'deadline_at' => '2026-11-14T09:00:00+07:00',
        'estimate_minutes' => 240,
        'energy' => 'light',
    ])->assertCreated()
        ->assertJsonPath('description', '40 lembar')
        ->assertJsonPath('key_result_id', $kr->id)
        ->assertJsonPath('is_orphan', false)
        ->assertJsonPath('label', 'Koreksi ujian')
        ->assertJsonPath('priority', 1)
        ->assertJsonPath('estimate_minutes', 240)
        ->assertJsonPath('energy', 'light')
        ->assertJsonPath('state', 'new');

    $task = Task::firstOrFail();
    expect($task->deadline_at->equalTo(Carbon::parse('2026-11-14T02:00:00Z')))->toBeTrue();
});

it('ignores owner, key, and state columns in the body', function () {
    $other = User::factory()->create();

    $this->postJson('/api/v1/tasks', [
        'title' => 'Task',
        'owner_id' => $other->id,
        'task_key' => 'TCB-999',
        'state' => 'running',
        'time_spent_minutes' => 500,
    ])->assertCreated()
        ->assertJsonPath('task_key', 'TCB-1')
        ->assertJsonPath('state', 'new')
        ->assertJsonPath('time_spent_minutes', 0);

    expect(Task::withoutGlobalScopes()->where('owner_id', $other->id)->count())->toBe(0);
});

it('requires a title', function (array $body) {
    $this->postJson('/api/v1/tasks', $body)
        ->assertUnprocessable()
        ->assertJsonPath('errors.title.0', 'Judul wajib diisi.');
})->with([
    'missing' => [[]],
    'empty' => [['title' => '']],
    'whitespace' => [['title' => '   ']],
    'null' => [['title' => null]],
]);

it('rejects invalid optional fields', function (array $body, string $field) {
    $this->postJson('/api/v1/tasks', ['title' => 'Task'] + $body)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$field]);
})->with([
    'priority 0' => [['priority' => 0], 'priority'],
    'priority 5' => [['priority' => 5], 'priority'],
    'energy' => [['energy' => 'tinggi'], 'energy'],
    'estimate 0' => [['estimate_minutes' => 0], 'estimate_minutes'],
    'estimate text' => [['estimate_minutes' => 'lama'], 'estimate_minutes'],
    'deadline' => [['deadline_at' => 'besok'], 'deadline_at'],
    'start_ready' => [['start_ready' => 'mungkin'], 'start_ready'],
    'key result' => [['key_result_id' => 999999], 'key_result_id'],
]);

it('rejects another owner\'s key result', function () {
    $foreignKr = ownKr(User::factory()->create());

    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'key_result_id' => $foreignKr->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.key_result_id.0', 'Key Result tidak ditemukan.');
});

/*
| Criterion: start_ready: true moves the task straight to `ready`.
*/

it('moves the task to ready when start_ready is true', function () {
    $response = $this->postJson('/api/v1/tasks', ['title' => 'Siapkan slide', 'start_ready' => true])
        ->assertCreated()
        ->assertJsonPath('state', 'ready');

    expect($response->json('ready_since'))->not->toBeNull();

    $log = StateTransition::where('task_id', $response->json('id'))->orderBy('id')->get();
    expect($log->map(fn ($t) => [$t->from_state, $t->to_state, $t->source])->all())
        ->toBe([[null, 'new', 'user'], ['new', 'ready', 'user']]);
});

it('keeps the task new when start_ready is false', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Task', 'start_ready' => false])
        ->assertCreated()
        ->assertJsonPath('state', 'new')
        ->assertJsonPath('ready_since', null);
});

/*
| Criterion: GET /tasks filters (state, key_result_id, objective_id, label,
| orphan, q) and cursor pagination.
*/

it('lists only the owner\'s tasks, most recently updated first, without deleted ones', function () {
    $first = makeTask($this->user, ['title' => 'Pertama']);
    $second = makeTask($this->user, ['title' => 'Kedua']);
    $deleted = makeTask($this->user, ['title' => 'Terhapus']);
    $deleted->delete();
    makeTask(User::factory()->create(), ['title' => 'Milik orang lain']);

    // Same transaction, same now(): newer id first as tie-breaker.
    $response = $this->getJson('/api/v1/tasks');

    expect(listedKeys($response))->toBe([$second->task_key, $first->task_key]);
    $response->assertJsonPath('next_cursor', null);
});

it('filters by one or more states', function () {
    $new = makeTask($this->user);
    $ready = walkTo(makeTask($this->user), 'ready');
    $waiting = walkTo(makeTask($this->user), 'ready', 'running', 'waiting');
    walkTo(makeTask($this->user), 'ready', 'running', 'done');

    expect(listedKeys($this->getJson('/api/v1/tasks?state=new')))->toBe([$new->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?state=ready,waiting')))
        ->toEqualCanonicalizing([$ready->task_key, $waiting->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?state=ready, waiting')))
        ->toEqualCanonicalizing([$ready->task_key, $waiting->task_key]);
});

it('rejects an unknown state filter', function () {
    $this->getJson('/api/v1/tasks?state=ready,berjalan')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['state']);
});

it('filters by key result, objective, and orphan', function () {
    $kr = ownKr($this->user);
    $sibling = KeyResult::factory()->for($this->user, 'owner')->for($kr->objective)->create();
    $otherKr = ownKr($this->user);

    $inKr = makeTask($this->user, ['key_result_id' => $kr->id]);
    $inSibling = makeTask($this->user, ['key_result_id' => $sibling->id]);
    $inOther = makeTask($this->user, ['key_result_id' => $otherKr->id]);
    $orphan = makeTask($this->user);

    expect(listedKeys($this->getJson("/api/v1/tasks?key_result_id={$kr->id}")))->toBe([$inKr->task_key]);
    expect(listedKeys($this->getJson("/api/v1/tasks?objective_id={$kr->objective_id}")))
        ->toEqualCanonicalizing([$inKr->task_key, $inSibling->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?orphan=true')))->toBe([$orphan->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?orphan=1')))->toBe([$orphan->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?orphan=false')))
        ->toEqualCanonicalizing([$inKr->task_key, $inSibling->task_key, $inOther->task_key]);
});

it('rejects invalid id and orphan filters', function (string $query, string $field) {
    $this->getJson("/api/v1/tasks?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    ['key_result_id=abc', 'key_result_id'],
    ['objective_id=abc', 'objective_id'],
    ['orphan=mungkin', 'orphan'],
]);

it('filters by label', function () {
    $grading = makeTask($this->user, ['label' => 'Koreksi ujian']);
    makeTask($this->user, ['label' => 'Mengajar']);
    makeTask($this->user);

    expect(listedKeys($this->getJson('/api/v1/tasks?label='.urlencode('Koreksi ujian'))))
        ->toBe([$grading->task_key]);
});

it('searches title and task key with q', function () {
    $uas = makeTask($this->user, ['title' => 'Susun soal UAS']);
    $paper = makeTask($this->user, ['title' => 'Revisi paper jurnal']);
    makeTask($this->user, ['title' => '100% selesai']);

    expect(listedKeys($this->getJson('/api/v1/tasks?q=soal')))->toBe([$uas->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?q=PAPER')))->toBe([$paper->task_key]);
    expect(listedKeys($this->getJson('/api/v1/tasks?q='.strtolower($paper->task_key))))->toBe([$paper->task_key]);
    // LIKE wildcards in q are literal.
    expect(listedKeys($this->getJson('/api/v1/tasks?q='.urlencode('%'))))->toHaveCount(1);
    expect(listedKeys($this->getJson('/api/v1/tasks?q=_')))->toBe([]);
});

it('combines filters', function () {
    $kr = ownKr($this->user);
    $match = walkTo(makeTask($this->user, ['key_result_id' => $kr->id, 'title' => 'Bab 2']), 'ready');
    makeTask($this->user, ['key_result_id' => $kr->id, 'title' => 'Bab 3']);
    walkTo(makeTask($this->user, ['title' => 'Bab 4']), 'ready');

    expect(listedKeys($this->getJson("/api/v1/tasks?state=ready&key_result_id={$kr->id}&q=bab")))
        ->toBe([$match->task_key]);
});

it('pages through all tasks with a cursor, even within one second', function () {
    $count = TaskController::PER_PAGE + 7;
    // Distinct sub-second updated_at values, as rows written by PostgreSQL's
    // now() have: a cursor that drops microseconds would skip rows. Inserted
    // with the query builder because Eloquent writes seconds only.
    for ($i = 0; $i < $count; $i++) {
        DB::table('tasks')->insert([
            'owner_id' => $this->user->id,
            'title' => "Task {$i}",
            'updated_at' => sprintf('2026-11-12 03:00:00.%06d+00', 500000 + ($i % 9) * 1000),
        ]);
    }
    $expected = Task::pluck('task_key')->all();
    expect($expected)->toHaveCount($count);

    $seen = [];
    $url = '/api/v1/tasks';
    $pages = 0;
    do {
        $response = $this->getJson($url)->assertOk();
        $seen = [...$seen, ...array_column($response->json('data'), 'task_key')];
        $cursor = $response->json('next_cursor');
        $url = '/api/v1/tasks?cursor='.urlencode((string) $cursor);
        $pages++;
    } while ($cursor !== null && $pages < 5);

    expect($pages)->toBe(2);
    expect($seen)->toHaveCount($count);
    expect($seen)->toEqualCanonicalizing($expected);

    $updated = Task::whereIn('task_key', $seen)->get()->keyBy('task_key');
    $order = array_map(fn ($key) => [$updated[$key]->updated_at->format('u'), $updated[$key]->id], $seen);
    $sorted = $order;
    usort($sorted, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    expect($order)->toBe($sorted);
});

it('keeps filters across pages', function () {
    for ($i = 0; $i < TaskController::PER_PAGE + 1; $i++) {
        makeTask($this->user, ['label' => 'Mengajar']);
    }
    makeTask($this->user, ['label' => 'Lain']);

    $first = $this->getJson('/api/v1/tasks?label=Mengajar')->assertOk();
    $second = $this->getJson('/api/v1/tasks?label=Mengajar&cursor='.urlencode($first->json('next_cursor')))->assertOk();

    expect($first->json('data'))->toHaveCount(TaskController::PER_PAGE);
    expect($second->json('data'))->toHaveCount(1);
    expect($second->json('next_cursor'))->toBeNull();
});

it('rejects an unavailable sort order', function (string $sort) {
    $this->getJson("/api/v1/tasks?sort={$sort}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sort']);
})->with(['deadline', 'acak']);

it('accepts sort=updated', function () {
    makeTask($this->user);

    $this->getJson('/api/v1/tasks?sort=updated')->assertOk()->assertJsonCount(1, 'data');
});

/*
| Criterion: GET /tasks/{id} accepts a numeric ID or a task_key.
*/

it('shows a task by id or by task key', function (callable $ref) {
    $task = makeTask($this->user, ['title' => 'Susun soal UAS']);
    makeTask($this->user);

    $this->getJson('/api/v1/tasks/'.$ref($task))
        ->assertOk()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('task_key', $task->task_key)
        ->assertJsonPath('title', 'Susun soal UAS');
})->with([
    'id' => [fn (Task $t) => $t->id],
    'key' => [fn (Task $t) => $t->task_key],
    'lowercase key' => [fn (Task $t) => strtolower($t->task_key)],
]);

it('returns 404 for a missing, deleted, or another owner\'s task', function () {
    $foreign = makeTask(User::factory()->create()); // also TCB-1, for another owner
    $deleted = makeTask($this->user);
    $deleted->delete();

    foreach ([999999, 'TCB-999', 'abc', '99999999999999999999999', $foreign->id, $deleted->id, $deleted->task_key] as $ref) {
        $this->getJson("/api/v1/tasks/{$ref}")
            ->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => 'Task tidak ditemukan.']);
    }
});

it('resolves a task key to the owner\'s own task', function () {
    $foreign = makeTask(User::factory()->create(), ['title' => 'Milik orang lain']);
    $own = makeTask($this->user, ['title' => 'Milik sendiri']);
    expect($own->task_key)->toBe($foreign->task_key);

    $this->getJson("/api/v1/tasks/{$own->task_key}")->assertOk()->assertJsonPath('id', $own->id);
});

/*
| Criterion: PATCH /tasks/{id} rejects `state` with 422 and a message pointing
| to the transition endpoint.
*/

it('updates a task partially, by id or task key', function () {
    $kr = ownKr($this->user);
    $task = makeTask($this->user, ['title' => 'Lama', 'label' => 'Mengajar', 'priority' => 3]);

    $this->patchJson("/api/v1/tasks/{$task->task_key}", [
        'title' => 'Baru',
        'key_result_id' => $kr->id,
        'next_step' => 'Cek rubrik nomor 3',
    ])->assertOk()
        ->assertJsonPath('title', 'Baru')
        ->assertJsonPath('key_result_id', $kr->id)
        ->assertJsonPath('is_orphan', false)
        ->assertJsonPath('next_step', 'Cek rubrik nomor 3')
        ->assertJsonPath('label', 'Mengajar')
        ->assertJsonPath('priority', 3);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['key_result_id' => null, 'label' => null])
        ->assertOk()
        ->assertJsonPath('key_result_id', null)
        ->assertJsonPath('is_orphan', true)
        ->assertJsonPath('label', null);
});

it('rejects state in PATCH and points to the transition endpoint', function (mixed $state) {
    $task = makeTask($this->user, ['title' => 'Tetap']);

    $response = $this->patchJson("/api/v1/tasks/{$task->id}", ['state' => $state, 'title' => 'Berubah'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['state']);

    expect($response->json('errors.state.0'))->toContain("/tasks/{$task->id}/transitions");
    expect($task->fresh())->state->toBe('new')->title->toBe('Tetap');
})->with(['ready', 'new', null]);

it('does not let PATCH empty the title, priority, or energy', function (array $body, string $field) {
    $task = makeTask($this->user);

    $this->patchJson("/api/v1/tasks/{$task->id}", $body)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors([$field]);
})->with([
    [['title' => ''], 'title'],
    [['title' => null], 'title'],
    [['priority' => null], 'priority'],
    [['priority' => 9], 'priority'],
    [['energy' => null], 'energy'],
    [['estimate_minutes' => -5], 'estimate_minutes'],
]);

it('rejects another owner\'s key result in PATCH', function () {
    $task = makeTask($this->user);
    $foreignKr = ownKr(User::factory()->create());

    $this->patchJson("/api/v1/tasks/{$task->id}", ['key_result_id' => $foreignKr->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['key_result_id']);
});

it('returns 404 when patching or deleting another owner\'s task', function () {
    $foreign = makeTask(User::factory()->create(), ['title' => 'Asli']);

    $this->patchJson("/api/v1/tasks/{$foreign->id}", ['title' => 'Diubah'])->assertNotFound();
    $this->deleteJson("/api/v1/tasks/{$foreign->id}")->assertNotFound();

    expect(Task::withoutGlobalScopes()->find($foreign->id))
        ->title->toBe('Asli')
        ->deleted_at->toBeNull();
});

/*
| parent_task_id (TaskCreate/TaskUpdate; R1-07 criterion 1): settable on
| create and update; no self-parent, no cycles.
*/

it('creates a subtask with parent_task_id', function () {
    $parent = makeTask($this->user);

    $this->postJson('/api/v1/tasks', ['title' => 'Subtask', 'parent_task_id' => $parent->id])
        ->assertCreated()
        ->assertJsonPath('parent_task_id', $parent->id);
});

it('sets and clears parent_task_id with PATCH', function () {
    $parent = makeTask($this->user);
    $task = makeTask($this->user);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['parent_task_id' => $parent->id])
        ->assertOk()
        ->assertJsonPath('parent_task_id', $parent->id);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['parent_task_id' => null])
        ->assertOk()
        ->assertJsonPath('parent_task_id', null);
});

it('rejects a missing, deleted, or another owner\'s parent', function (callable $parentId) {
    $id = $parentId($this->user);
    $task = makeTask($this->user);

    $this->postJson('/api/v1/tasks', ['title' => 'Subtask', 'parent_task_id' => $id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_task_id.0', 'Task induk tidak ditemukan.');
    $this->patchJson("/api/v1/tasks/{$task->id}", ['parent_task_id' => $id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_task_id.0', 'Task induk tidak ditemukan.');
})->with([
    'missing' => [fn () => 999999],
    'not a number' => [fn () => 'TCB-1'],
    'deleted' => [function (User $user) {
        $task = makeTask($user);
        $task->delete();

        return $task->id;
    }],
    'another owner' => [fn () => makeTask(User::factory()->create())->id],
]);

it('rejects a task as its own parent', function () {
    $task = makeTask($this->user);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['parent_task_id' => $task->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.parent_task_id.0', 'Task tidak bisa menjadi induk dirinya sendiri.');
});

it('rejects a parent that would form a cycle', function () {
    // a <- b <- c (c is a grandchild of a)
    $a = makeTask($this->user);
    $b = makeTask($this->user, ['parent_task_id' => $a->id]);
    $c = makeTask($this->user, ['parent_task_id' => $b->id]);

    foreach ([$b, $c] as $descendant) {
        $this->patchJson("/api/v1/tasks/{$a->id}", ['parent_task_id' => $descendant->id])
            ->assertUnprocessable()
            ->assertJsonPath('errors.parent_task_id.0', 'Task induk tidak boleh subtask dari task ini (membentuk siklus).');
    }
    expect($a->fresh()->parent_task_id)->toBeNull();

    // Moving within the tree without a cycle is fine.
    $this->patchJson("/api/v1/tasks/{$c->id}", ['parent_task_id' => $a->id])
        ->assertOk()
        ->assertJsonPath('parent_task_id', $a->id);
});

/*
| Criterion: DELETE /tasks/{id} soft-deletes; a deleted task does not appear
| in the list and the log stays intact.
*/

it('soft-deletes a task and keeps its transition log', function () {
    $task = walkTo(makeTask($this->user), 'ready', 'running');
    $logBefore = StateTransition::where('task_id', $task->id)->count();
    expect($logBefore)->toBe(3);

    $this->deleteJson("/api/v1/tasks/{$task->task_key}")->assertNoContent();

    expect(Task::withTrashed()->find($task->id)->deleted_at)->not->toBeNull();
    expect(DB::table('tasks')->where('id', $task->id)->exists())->toBeTrue();
    expect(StateTransition::where('task_id', $task->id)->count())->toBe($logBefore);
    expect(listedKeys($this->getJson('/api/v1/tasks')))->toBe([]);
    $this->getJson("/api/v1/tasks/{$task->id}")->assertNotFound();
});

/*
| Criterion: is_orphan is true when key_result_id is empty.
*/

it('marks tasks orphaned when their key result is deleted', function () {
    $kr = ownKr($this->user);
    $task = makeTask($this->user, ['key_result_id' => $kr->id]);

    $this->getJson("/api/v1/tasks/{$task->id}")->assertJsonPath('is_orphan', false);

    $this->deleteJson("/api/v1/key-results/{$kr->id}")->assertNoContent();

    $this->getJson("/api/v1/tasks/{$task->id}")
        ->assertJsonPath('key_result_id', null)
        ->assertJsonPath('is_orphan', true);
});
