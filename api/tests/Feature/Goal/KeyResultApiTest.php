<?php

use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// R1-05: Key Result API and progress (FR-02).
// Contract: /objectives/{id}/key-results and /key-results/{id} in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $this->objective = Objective::factory()->for($this->user, 'owner')->create();
});

function krFor(Objective $objective, array $attributes = []): KeyResult
{
    return KeyResult::factory()->for($objective->owner, 'owner')->for($objective)->create($attributes);
}

function taskIn(KeyResult $kr, array $attributes = [], string ...$states): Task
{
    $task = makeTask($kr->owner, ['key_result_id' => $kr->id] + $attributes);

    return $states === [] ? $task : walkTo($task, ...$states);
}

/** progress of the first key result, as shown by the objective detail. */
function detailKrProgress($test, Objective $objective): float
{
    return (float) $test->getJson("/api/v1/objectives/{$objective->id}")
        ->assertOk()
        ->json('key_results.0.progress');
}

const ZERO_COUNTS = [
    'new' => 0, 'ready' => 0, 'running' => 0, 'waiting' => 0,
    'suspended' => 0, 'done' => 0, 'cancelled' => 0,
];

/*
| Criterion: POST /objectives/{id}/key-results, PATCH/DELETE /key-results/{id}.
*/

it('creates a key result with only a title', function () {
    $response = $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", [
        'title' => '2 paper submit',
    ]);

    $kr = KeyResult::where('title', '2 paper submit')->firstOrFail();

    $response->assertCreated()->assertExactJson([
        'id' => $kr->id,
        'objective_id' => $this->objective->id,
        'title' => '2 paper submit',
        'weight' => 1,
        'progress_mode' => 'auto',
        'start_value' => null,
        'target_value' => null,
        'current_value' => null,
        'unit' => null,
        'progress' => 0,
        'task_counts' => ZERO_COUNTS,
    ]);
    expect($kr->owner_id)->toBe($this->user->id);
});

it('stores all optional fields of a manual key result', function () {
    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", [
        'title' => 'Sitasi',
        'weight' => 0.5,
        'progress_mode' => 'manual',
        'start_value' => 10,
        'target_value' => 30,
        'current_value' => 15,
        'unit' => 'sitasi',
    ])->assertCreated()
        ->assertJsonPath('weight', 0.5)
        ->assertJsonPath('progress_mode', 'manual')
        ->assertJsonPath('start_value', 10)
        ->assertJsonPath('target_value', 30)
        ->assertJsonPath('current_value', 15)
        ->assertJsonPath('unit', 'sitasi')
        ->assertJsonPath('progress', 0.25); // (15 - 10) / (30 - 10)
});

it('ignores objective_id and owner_id in the body', function () {
    $otherObjective = Objective::factory()->for($this->user, 'owner')->create();
    $other = User::factory()->create();

    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", [
        'title' => 'KR',
        'objective_id' => $otherObjective->id,
        'owner_id' => $other->id,
    ])->assertCreated()->assertJsonPath('objective_id', $this->objective->id);

    expect(KeyResult::withoutGlobalScopes()->where('owner_id', $other->id)->count())->toBe(0);
});

it('requires a title', function (array $body) {
    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", $body)
        ->assertUnprocessable()
        ->assertJsonPath('errors.title.0', 'Judul wajib diisi.');
})->with([
    'missing' => [[]],
    'empty' => [['title' => '']],
    'null' => [['title' => null]],
]);

it('rejects a weight outside (0, 1]', function (mixed $weight) {
    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", ['title' => 'KR', 'weight' => $weight])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['weight']);
})->with([0, -0.5, 1.5, 'berat']);

it('rejects an unknown progress mode', function () {
    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", ['title' => 'KR', 'progress_mode' => 'otomatis'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.progress_mode.0', 'Mode progres harus auto atau manual.');
});

it('returns 404 when adding a key result to a missing or another owner\'s objective', function () {
    $foreign = Objective::factory()->for(User::factory(), 'owner')->create();

    foreach ([$foreign->id, 999999] as $id) {
        $this->postJson("/api/v1/objectives/{$id}/key-results", ['title' => 'KR'])
            ->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => 'Objective tidak ditemukan.']);
    }

    expect(KeyResult::withoutGlobalScopes()->count())->toBe(0);
});

it('updates a key result partially', function () {
    $kr = krFor($this->objective, ['title' => 'Lama', 'weight' => 0.4, 'unit' => 'paper']);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['title' => 'Baru'])
        ->assertOk()
        ->assertJsonPath('id', $kr->id)
        ->assertJsonPath('title', 'Baru')
        ->assertJsonPath('weight', 0.4)
        ->assertJsonPath('unit', 'paper')
        ->assertJsonPath('task_counts', ZERO_COUNTS);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['weight' => 0.75, 'unit' => null])
        ->assertOk()
        ->assertJsonPath('weight', 0.75)
        ->assertJsonPath('unit', null);
});

it('does not let PATCH empty the title or weight', function (array $body, string $field) {
    $kr = krFor($this->objective);

    $this->patchJson("/api/v1/key-results/{$kr->id}", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);
})->with([
    'empty title' => [['title' => ''], 'title'],
    'null weight' => [['weight' => null], 'weight'],
    'zero weight' => [['weight' => 0], 'weight'],
]);

it('updates manual values and recalculates progress', function () {
    $kr = krFor($this->objective, ['progress_mode' => 'manual', 'target_value' => 4]);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['current_value' => 3])
        ->assertOk()
        ->assertJsonPath('progress', 0.75);
});

it('returns 404 for a missing or another owner\'s key result', function () {
    $foreignObjective = Objective::factory()->for(User::factory(), 'owner')->create();
    $foreign = krFor($foreignObjective, ['title' => 'Asing']);

    foreach ([$foreign->id, 999999] as $id) {
        $this->patchJson("/api/v1/key-results/{$id}", ['title' => 'x'])
            ->assertNotFound()
            ->assertExactJson(['code' => 'not_found', 'message' => 'Key Result tidak ditemukan.']);
        $this->deleteJson("/api/v1/key-results/{$id}")
            ->assertNotFound()
            ->assertJsonPath('code', 'not_found');
    }

    $this->patchJson('/api/v1/key-results/abc', ['title' => 'x'])->assertNotFound();

    $foreign = KeyResult::withoutGlobalScopes()->find($foreign->id);
    expect($foreign)->not->toBeNull()->and($foreign->title)->toBe('Asing');
});

/*
| Criterion: default weight 1, progress mode auto; manual requires target_value.
*/

it('requires target_value for a manual key result', function (array $extra) {
    $this->postJson("/api/v1/objectives/{$this->objective->id}/key-results", ['title' => 'KR', 'progress_mode' => 'manual'] + $extra)
        ->assertUnprocessable()
        ->assertJsonPath('errors.target_value.0', 'Target wajib diisi bila mode progres manual.');
})->with([
    'missing' => [[]],
    'null' => [['target_value' => null]],
]);

it('requires target_value when PATCH switches to manual', function () {
    $kr = krFor($this->objective);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['progress_mode' => 'manual'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.target_value.0', 'Target wajib diisi bila mode progres manual.');

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['progress_mode' => 'manual', 'target_value' => 5])
        ->assertOk()
        ->assertJsonPath('progress_mode', 'manual')
        ->assertJsonPath('target_value', 5);
});

it('does not let PATCH clear target_value of a manual key result', function () {
    $kr = krFor($this->objective, ['progress_mode' => 'manual', 'target_value' => 5]);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['target_value' => null])
        ->assertUnprocessable()
        ->assertJsonPath('errors.target_value.0', 'Target wajib diisi bila mode progres manual.');

    // Back to auto, the target may be cleared.
    $this->patchJson("/api/v1/key-results/{$kr->id}", ['progress_mode' => 'auto', 'target_value' => null])
        ->assertOk()
        ->assertJsonPath('progress_mode', 'auto')
        ->assertJsonPath('target_value', null);
});

it('allows switching to manual when a target is already stored', function () {
    $kr = krFor($this->objective, ['target_value' => 8]);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['progress_mode' => 'manual'])
        ->assertOk()
        ->assertJsonPath('progress_mode', 'manual');
});

/*
| Criterion: deleting a KR orphans its tasks (key_result_id = null) instead of deleting them.
*/

it('deletes a key result and turns its tasks into orphans', function () {
    $kr = krFor($this->objective);
    $other = krFor($this->objective);
    $open = taskIn($kr);
    $done = taskIn($kr, [], 'ready', 'running', 'done');
    $trashed = taskIn($kr);
    $trashed->delete();
    $untouched = taskIn($other);

    $this->deleteJson("/api/v1/key-results/{$kr->id}")->assertNoContent();

    expect(KeyResult::find($kr->id))->toBeNull()
        ->and(Task::find($open->id)->key_result_id)->toBeNull()
        ->and(Task::find($done->id)->key_result_id)->toBeNull()
        ->and(Task::find($done->id)->state)->toBe('done')
        ->and(Task::withTrashed()->find($trashed->id)->key_result_id)->toBeNull()
        ->and(Task::find($untouched->id)->key_result_id)->toBe($other->id);

    $this->getJson("/api/v1/objectives/{$this->objective->id}")
        ->assertJsonCount(1, 'key_results')
        ->assertJsonPath('key_results.0.id', $other->id);
});

/*
| Criterion: responses include progress from key_result_progress and task_counts per state.
*/

it('counts tasks per state, without soft-deleted tasks', function () {
    $kr = krFor($this->objective);
    taskIn($kr);
    taskIn($kr, [], 'ready');
    taskIn($kr, [], 'ready');
    taskIn($kr, [], 'ready', 'running', 'waiting'); // frees the WIP slot again
    taskIn($kr, [], 'ready', 'running');
    taskIn($kr, [], 'ready', 'suspended');
    taskIn($kr, [], 'ready', 'cancelled');
    taskIn($kr, [], 'ready', 'cancelled');
    taskIn($kr)->delete();
    taskIn(krFor($this->objective)); // another KR

    $expected = [
        'new' => 1, 'ready' => 2, 'running' => 1, 'waiting' => 1,
        'suspended' => 1, 'done' => 0, 'cancelled' => 2,
    ];

    $this->patchJson("/api/v1/key-results/{$kr->id}", [])
        ->assertOk()
        ->assertJsonPath('task_counts', $expected);

    $this->getJson("/api/v1/objectives/{$this->objective->id}")
        ->assertOk()
        ->assertJsonPath('key_results.0.id', $kr->id)
        ->assertJsonPath('key_results.0.task_counts', $expected)
        ->assertJsonPath('key_results.1.task_counts.new', 1);
});

it('includes progress from key_result_progress in the PATCH response', function () {
    $kr = krFor($this->objective);
    taskIn($kr, ['estimate_minutes' => 60], 'ready', 'running', 'done');
    taskIn($kr, ['estimate_minutes' => 60]);

    $this->patchJson("/api/v1/key-results/{$kr->id}", ['title' => 'Diubah'])
        ->assertOk()
        ->assertJsonPath('progress', 0.5);
});

/*
| Criterion (tests): automatic progress uses estimates; a task without an
| estimate counts as 60 minutes; Cancelled tasks are not counted.
*/

it('weights automatic progress by estimate', function () {
    $kr = krFor($this->objective);
    taskIn($kr, ['estimate_minutes' => 120], 'ready', 'running', 'done');
    taskIn($kr, ['estimate_minutes' => 240], 'ready');

    expect(detailKrProgress($this, $this->objective))->toBe(0.3333); // 120 / 360
});

it('counts a task without an estimate as 60 minutes', function () {
    $kr = krFor($this->objective);
    taskIn($kr, [], 'ready', 'running', 'done');        // 60 done
    taskIn($kr, ['estimate_minutes' => 180], 'ready'); // 180 open

    expect(detailKrProgress($this, $this->objective))->toBe(0.25); // 60 / 240
});

it('does not count cancelled tasks', function () {
    $kr = krFor($this->objective);
    taskIn($kr, ['estimate_minutes' => 30], 'ready', 'running', 'done');
    taskIn($kr, ['estimate_minutes' => 600], 'ready', 'cancelled');

    expect(detailKrProgress($this, $this->objective))->toBe(1.0);
});

it('counts partial unit progress of an open task', function () {
    $kr = krFor($this->objective);
    taskIn($kr, ['estimate_minutes' => 240, 'unit_total' => 40, 'unit_done' => 22]);

    expect(detailKrProgress($this, $this->objective))->toBe(0.55);
});

it('ignores tasks for progress in manual mode', function () {
    $kr = krFor($this->objective, ['progress_mode' => 'manual', 'start_value' => 0, 'target_value' => 10, 'current_value' => 2]);
    taskIn($kr, [], 'ready', 'running', 'done');

    expect(detailKrProgress($this, $this->objective))->toBe(0.2);
});
