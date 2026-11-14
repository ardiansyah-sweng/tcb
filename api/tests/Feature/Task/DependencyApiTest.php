<?php

use App\Models\Dependency;
use App\Models\Task;
use App\Models\User;
use App\Services\TaskStateMachine;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-07: subtasks and dependencies (FR-11).
// Contract: /tasks/{taskId}/dependencies, /dependencies/{dependencyId}, and
// TaskDetail in docs/tcb_openapi.yaml. Criterion 1 (parent_task_id) is tested
// in TaskApiTest.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function waitsForTask(Task $task, Task $on, string $status = 'open'): Dependency
{
    return Dependency::create([
        'owner_id' => $task->owner_id,
        'task_id' => $task->id,
        'depends_on_task_id' => $on->id,
        'status' => $status,
        'resolved_at' => $status === 'resolved' ? now() : null,
    ]);
}

function waitsForExternal(Task $task, string $description, string $status = 'open'): Dependency
{
    return Dependency::create([
        'owner_id' => $task->owner_id,
        'task_id' => $task->id,
        'external_description' => $description,
        'status' => $status,
        'resolved_at' => $status === 'resolved' ? now() : null,
    ]);
}

/*
| Criterion: GET/POST /tasks/{id}/dependencies, PATCH /dependencies/{id}
| (status resolved), DELETE /dependencies/{id}.
*/

it('adds a dependency on another task, by task key or id', function (callable $ref) {
    $task = makeTask($this->user, ['title' => 'Rekap nilai']);
    $other = makeTask($this->user, ['title' => 'Koreksi UTS']);

    $response = $this->postJson("/api/v1/tasks/{$task->task_key}/dependencies", [
        'depends_on_task_id' => $ref($other),
    ])->assertCreated();

    $dependency = Dependency::where('task_id', $task->id)->sole();
    $response->assertExactJson([
        'id' => $dependency->id,
        'task_id' => $task->id,
        'depends_on_task_id' => $other->id,
        'depends_on_task_key' => $other->task_key,
        'external_description' => null,
        'status' => 'open',
        'resolved_at' => null,
    ]);
    expect($dependency->owner_id)->toBe($this->user->id);
})->with([
    'task key' => [fn (Task $t) => $t->task_key],
    'lowercase task key' => [fn (Task $t) => strtolower($t->task_key)],
    'id as string' => [fn (Task $t) => (string) $t->id],
    'id as integer' => [fn (Task $t) => $t->id],
]);

it('adds an external dependency', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", [
        'external_description' => '  Nilai praktikum dari asisten  ',
    ])->assertCreated()
        ->assertJsonPath('task_id', $task->id)
        ->assertJsonPath('depends_on_task_id', null)
        ->assertJsonPath('depends_on_task_key', null)
        ->assertJsonPath('external_description', 'Nilai praktikum dari asisten')
        ->assertJsonPath('status', 'open')
        ->assertJsonPath('resolved_at', null);
});

it('lists a task\'s open and resolved dependencies, oldest first', function () {
    $task = makeTask($this->user);
    $other = makeTask($this->user);
    $first = waitsForExternal($task, 'Data dari prodi', 'resolved');
    $second = waitsForTask($task, $other);
    waitsForExternal(makeTask($this->user), 'Milik task lain');

    $response = $this->getJson("/api/v1/tasks/{$task->task_key}/dependencies")->assertOk();

    expect(array_column($response->json('data'), 'id'))->toBe([$first->id, $second->id]);
    $response->assertJsonPath('data.0.status', 'resolved')
        ->assertJsonPath('data.1.depends_on_task_key', $other->task_key)
        ->assertJsonPath('data.1.status', 'open');
    expect($response->json('data.0.resolved_at'))->not->toBeNull();
});

it('returns 404 for the dependencies of a missing, deleted, or another owner\'s task', function () {
    $foreign = makeTask(User::factory()->create());
    $deleted = makeTask($this->user);
    $deleted->delete();

    foreach ([999999, $foreign->id, $deleted->task_key] as $ref) {
        $this->getJson("/api/v1/tasks/{$ref}/dependencies")->assertNotFound();
        $this->postJson("/api/v1/tasks/{$ref}/dependencies", ['external_description' => 'X'])
            ->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => 'Task tidak ditemukan.']);
    }
    expect(Dependency::count())->toBe(0);
});

it('marks an external blocker resolved', function () {
    $dependency = waitsForExternal(makeTask($this->user), 'Nilai praktikum dari asisten');

    $response = $this->patchJson("/api/v1/dependencies/{$dependency->id}", ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('id', $dependency->id)
        ->assertJsonPath('status', 'resolved');

    $resolvedAt = $response->json('resolved_at');
    expect($resolvedAt)->not->toBeNull();
    expect($dependency->fresh())->status->toBe('resolved')->resolved_at->not->toBeNull();

    // Repeating the request keeps the first resolution time.
    $this->travel(5)->minutes();
    $this->patchJson("/api/v1/dependencies/{$dependency->id}", ['status' => 'resolved'])
        ->assertOk()
        ->assertJsonPath('resolved_at', $resolvedAt);
});

it('only accepts status resolved in PATCH', function (array $body) {
    $dependency = waitsForExternal(makeTask($this->user), 'Data dari prodi');

    $this->patchJson("/api/v1/dependencies/{$dependency->id}", $body)
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['status']);

    expect($dependency->fresh()->status)->toBe('open');
})->with([
    'missing' => [[]],
    'open' => [['status' => 'open']],
    'unknown' => [['status' => 'done']],
]);

it('does not resolve a task dependency by hand', function () {
    $dependency = waitsForTask(makeTask($this->user), makeTask($this->user));

    $this->patchJson("/api/v1/dependencies/{$dependency->id}", ['status' => 'resolved'])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['status']);

    expect($dependency->fresh())->status->toBe('open')->resolved_at->toBeNull();
});

it('deletes a dependency and leaves both tasks alone', function () {
    $task = makeTask($this->user);
    $other = makeTask($this->user);
    $dependency = waitsForTask($task, $other);

    $this->deleteJson("/api/v1/dependencies/{$dependency->id}")->assertNoContent();

    expect(Dependency::find($dependency->id))->toBeNull();
    expect(Task::whereKey([$task->id, $other->id])->count())->toBe(2);
});

it('returns 404 for a missing or another owner\'s dependency, or one on a deleted task', function () {
    $foreign = waitsForExternal(makeTask(User::factory()->create()), 'Milik orang lain');
    $deletedTask = makeTask($this->user);
    $onDeleted = waitsForExternal($deletedTask, 'Task sudah dihapus');
    $deletedTask->delete();

    foreach ([999999, 'abc', $foreign->id, $onDeleted->id] as $ref) {
        $this->patchJson("/api/v1/dependencies/{$ref}", ['status' => 'resolved'])
            ->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => 'Dependensi tidak ditemukan.']);
        $this->deleteJson("/api/v1/dependencies/{$ref}")->assertNotFound();
    }

    expect(DB::table('dependencies')->where('status', 'open')->count())->toBe(2);
});

/*
| Criterion: a dependency is another task OR an external description, not
| both (422).
*/

it('rejects a dependency with both a task and an external description', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", [
        'depends_on_task_id' => makeTask($this->user)->task_key,
        'external_description' => 'Data dari prodi',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', 'Isi salah satu: task yang ditunggu atau hambatan eksternal, tidak keduanya.');

    expect(Dependency::count())->toBe(0);
});

it('rejects a dependency with neither a task nor an external description', function (array $body) {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", $body)
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', 'Isi task yang ditunggu atau hambatan eksternal.');

    expect(Dependency::count())->toBe(0);
})->with([
    'empty body' => [[]],
    'nulls' => [['depends_on_task_id' => null, 'external_description' => null]],
    'blank strings' => [['depends_on_task_id' => '', 'external_description' => '   ']],
]);

it('rejects a missing, deleted, or another owner\'s task as the dependency', function (callable $target) {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $target($this->user)])
        ->assertUnprocessable()
        ->assertOnlyJsonValidationErrors(['depends_on_task_id'])
        ->assertJsonPath('errors.depends_on_task_id.0', 'Task yang ditunggu tidak ditemukan.');

    expect(Dependency::count())->toBe(0);
})->with([
    'missing id' => [fn () => 999999],
    'missing key' => [fn () => 'TCB-999'],
    'not a reference' => [fn () => 'abc'],
    'not a scalar' => [fn () => ['TCB-1']],
    'deleted' => [function (User $owner) {
        $t = makeTask($owner);
        $t->delete();

        return $t->task_key;
    }],
    'another owner' => [fn () => makeTask(User::factory()->create())->id],
]);

it('rejects a task waiting for itself', function () {
    $task = makeTask($this->user);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $task->task_key])
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', 'Task tidak bisa menunggu dirinya sendiri.');
});

it('rejects waiting for the same task twice', function () {
    $task = makeTask($this->user);
    $other = makeTask($this->user);
    waitsForTask($task, $other);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $other->task_key])
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', "Task ini sudah menunggu {$other->task_key}.");

    expect(Dependency::count())->toBe(1);
});

it('rejects waiting for a task that is already done or cancelled', function (array $path, string $label) {
    $task = makeTask($this->user);
    $other = walkTo(makeTask($this->user), ...$path);

    $this->postJson("/api/v1/tasks/{$task->id}/dependencies", ['depends_on_task_id' => $other->task_key])
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', "{$other->task_key} sudah {$label}; tidak ada yang perlu ditunggu.");

    expect(Dependency::count())->toBe(0);
})->with([
    'done' => [['ready', 'running', 'done'], 'Done'],
    'cancelled' => [['cancelled'], 'Cancelled'],
]);

/*
| Criterion: a dependency that forms a cycle (A waits for B, B waits for A)
| is rejected (422).
*/

it('rejects a direct dependency cycle', function () {
    $a = makeTask($this->user);
    $b = makeTask($this->user);
    waitsForTask($a, $b);

    $this->postJson("/api/v1/tasks/{$b->id}/dependencies", ['depends_on_task_id' => $a->task_key])
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', "Dependensi ini membentuk siklus: {$a->task_key} sudah menunggu {$b->task_key}, langsung atau lewat task lain.");

    expect(Dependency::where('task_id', $b->id)->count())->toBe(0);
});

it('rejects an indirect dependency cycle', function () {
    $a = makeTask($this->user);
    $b = makeTask($this->user);
    $c = makeTask($this->user);
    waitsForTask($a, $b);
    waitsForTask($b, $c);

    $this->postJson("/api/v1/tasks/{$c->id}/dependencies", ['depends_on_task_id' => $a->id])
        ->assertUnprocessable()
        ->assertJsonPath('errors.depends_on_task_id.0', "Dependensi ini membentuk siklus: {$a->task_key} sudah menunggu {$c->task_key}, langsung atau lewat task lain.");

    // A chain without a loop back is fine.
    $d = makeTask($this->user);
    $this->postJson("/api/v1/tasks/{$c->id}/dependencies", ['depends_on_task_id' => $d->id])->assertCreated();
    $this->postJson("/api/v1/tasks/{$a->id}/dependencies", ['depends_on_task_id' => $c->id])->assertCreated();
});

it('ignores resolved dependencies when looking for a cycle', function () {
    $a = makeTask($this->user);
    $b = makeTask($this->user);
    waitsForTask($a, $b, 'resolved');

    $this->postJson("/api/v1/tasks/{$b->id}/dependencies", ['depends_on_task_id' => $a->id])->assertCreated();
});

/*
| Criterion: GET /tasks/{id} includes subtasks and open_dependencies.
*/

it('shows subtasks and open dependencies in the task detail', function () {
    $task = makeTask($this->user, ['title' => 'Koreksi UAS']);
    $sub1 = makeTask($this->user, ['title' => 'Kelas A', 'parent_task_id' => $task->id]);
    $sub2 = makeTask($this->user, ['title' => 'Kelas B', 'parent_task_id' => $task->id]);
    makeTask($this->user, ['title' => 'Cucu', 'parent_task_id' => $sub1->id]);
    $deletedSub = makeTask($this->user, ['parent_task_id' => $task->id]);
    $deletedSub->delete();

    $other = makeTask($this->user);
    $open = waitsForTask($task, $other);
    waitsForExternal($task, 'Sudah teratasi', 'resolved');
    $external = waitsForExternal($task, 'Nilai praktikum dari asisten');

    $response = $this->getJson("/api/v1/tasks/{$task->task_key}")
        ->assertOk()
        ->assertJsonPath('id', $task->id)
        ->assertJsonPath('title', 'Koreksi UAS');

    expect(array_column($response->json('subtasks'), 'task_key'))->toBe([$sub1->task_key, $sub2->task_key]);
    $response->assertJsonPath('subtasks.0.title', 'Kelas A')
        ->assertJsonPath('subtasks.0.parent_task_id', $task->id)
        ->assertJsonPath('subtasks.0.state', 'new');

    expect(array_column($response->json('open_dependencies'), 'id'))->toBe([$open->id, $external->id]);
    $response->assertJsonPath('open_dependencies.0.depends_on_task_key', $other->task_key)
        ->assertJsonPath('open_dependencies.1.external_description', 'Nilai praktikum dari asisten')
        ->assertJsonPath('open_dependencies.1.status', 'open');
});

it('shows empty subtasks and open dependencies when there are none', function () {
    $task = makeTask($this->user);

    $this->getJson("/api/v1/tasks/{$task->id}")
        ->assertOk()
        ->assertJsonPath('subtasks', [])
        ->assertJsonPath('open_dependencies', []);
});

it('returns the task detail from PATCH as well', function () {
    $task = makeTask($this->user);
    makeTask($this->user, ['parent_task_id' => $task->id]);
    waitsForExternal($task, 'Data dari prodi');

    $this->patchJson("/api/v1/tasks/{$task->id}", ['title' => 'Baru'])
        ->assertOk()
        ->assertJsonPath('title', 'Baru')
        ->assertJsonCount(1, 'subtasks')
        ->assertJsonCount(1, 'open_dependencies');
});

it('does not run more queries per subtask or dependency in the detail', function () {
    $task = makeTask($this->user);
    $countQueries = function () use ($task) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson("/api/v1/tasks/{$task->id}")->assertOk();
        DB::disableQueryLog();
        freshRequest();
        Sanctum::actingAs($this->user);

        return count(DB::getQueryLog());
    };

    // TaskDetail.allowed_transitions (R1-19) reads the table once per request;
    // the test app keeps that instance, so read it before counting.
    app(TaskStateMachine::class)->targetsFrom('new');

    makeTask($this->user, ['parent_task_id' => $task->id]);
    waitsForTask($task, makeTask($this->user));
    $few = $countQueries();

    for ($n = 0; $n < 3; $n++) {
        makeTask($this->user, ['parent_task_id' => $task->id]);
        waitsForTask($task, makeTask($this->user));
    }

    expect($countQueries())->toBe($few);
});
