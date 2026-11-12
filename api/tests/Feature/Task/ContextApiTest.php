<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-10: context history, notes without a state change, and the form draft
// (FR-07, FR-08). Contract: /tasks/{taskId}/contexts,
// /tasks/{taskId}/contexts/draft, ContextInput, ContextSnapshot.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/*
| Criterion: GET /tasks/{id}/contexts (history), POST /tasks/{id}/contexts
| (a note without a state change).
*/

it('saves a note without changing the state', function () {
    $task = walkTo(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 5, 'next_step' => 'Soal 1']), 'ready', 'running');

    $response = $this->postJson("/api/v1/tasks/{$task->task_key}/contexts", [
        'last_note' => 'Soal 1 dan 2 sudah dinilai',
        'next_step' => 'Soal 3',
        'unit_done' => 12,
        'links' => [['url' => 'https://contoh.id/rubrik']],
    ])->assertCreated()
        ->assertJsonPath('task_id', $task->id)
        ->assertJsonPath('last_note', 'Soal 1 dan 2 sudah dinilai')
        ->assertJsonPath('next_step', 'Soal 3')
        ->assertJsonPath('blocker', null)
        ->assertJsonPath('unit_done', 12)
        ->assertJsonPath('links', [['url' => 'https://contoh.id/rubrik']]);

    expect(array_keys($response->json()))
        ->toBe(['id', 'task_id', 'last_note', 'next_step', 'blocker', 'unit_done', 'links', 'created_at']);

    $task->refresh();
    expect($task->state)->toBe('running')
        ->and($task->next_step)->toBe('Soal 3')
        ->and($task->unit_done)->toBe(12.0)
        ->and($task->switch_count)->toBe(0)
        ->and(DB::table('state_transitions')->where('task_id', $task->id)->count())->toBe(3);
});

it('saves a note on a task in any state', function (array $path) {
    $task = walkTo(makeTask($this->user), ...$path);

    $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['last_note' => 'Ide: mulai dari rubrik'])
        ->assertCreated();
})->with([
    'new' => [[]],
    'waiting' => [['ready', 'running', 'waiting']],
    'done' => [['ready', 'running', 'done']],
]);

it('accepts an empty note body', function () {
    $task = makeTask($this->user, ['next_step' => 'Soal 1']);

    $this->postJson("/api/v1/tasks/{$task->id}/contexts", [])
        ->assertCreated()
        ->assertJsonPath('last_note', null)
        ->assertJsonPath('next_step', 'Soal 1')
        ->assertJsonPath('links', []);
});

it('shows the newest note as latest_context of the task', function () {
    $task = makeTask($this->user);
    $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['last_note' => 'Pertama'])->assertCreated();
    $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['last_note' => 'Kedua'])->assertCreated();

    $this->getJson("/api/v1/tasks/{$task->id}")->assertJsonPath('latest_context.last_note', 'Kedua');
});

it('validates a note', function (array $body, string $field) {
    $task = makeTask($this->user, ['unit_total' => 40]);

    $this->postJson("/api/v1/tasks/{$task->id}/contexts", $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field]);

    expect(DB::table('context_snapshots')->where('task_id', $task->id)->count())->toBe(0);
})->with([
    'next_step not text' => [['next_step' => 5], 'next_step'],
    'blocker not text' => [['blocker' => ['x']], 'blocker'],
    'unit_done not a number' => [['unit_done' => 'banyak'], 'unit_done'],
    'unit_done above total' => [['unit_done' => 41], 'unit_done'],
    'link not an object' => [['links' => ['https://contoh.id']], 'links.0'],
    'link title not text' => [['links' => [['url' => 'https://contoh.id', 'title' => 5]]], 'links.0.title'],
]);

it('lists the context history newest first', function () {
    $task = makeTask($this->user);
    foreach (['Pertama', 'Kedua', 'Ketiga'] as $note) {
        $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['last_note' => $note])->assertCreated();
    }
    makeTask($this->user)->contextSnapshots()->create(['owner_id' => $this->user->id, 'last_note' => 'Task lain']);

    $response = $this->getJson("/api/v1/tasks/{$task->task_key}/contexts")->assertOk();

    expect(collect($response->json('data'))->pluck('last_note')->all())->toBe(['Ketiga', 'Kedua', 'Pertama']);
});

it('returns an empty history for a task without context', function () {
    $task = makeTask($this->user);

    $this->getJson("/api/v1/tasks/{$task->id}/contexts")->assertOk()->assertExactJson(['data' => []]);
});

/*
| Criterion: GET /tasks/{id}/contexts/draft returns the last context plus the
| current unit_done (input-minimal principle).
*/

it('drafts the form from the last context and the current unit_done', function () {
    $task = walkTo(makeTask($this->user, ['unit_total' => 40, 'unit_done' => 22]), 'ready', 'running');
    $this->postJson("/api/v1/tasks/{$task->id}/contexts", [
        'last_note' => 'Soal 1 dan 2 sudah dinilai',
        'next_step' => 'Soal 3',
        'blocker' => 'Kunci jawaban soal 4 belum ada',
        'unit_done' => 22,
        'links' => [['title' => 'Rubrik', 'url' => 'https://contoh.id/rubrik']],
    ])->assertCreated();
    // Progress noted on the task afterwards wins over the snapshot.
    $this->patchJson("/api/v1/tasks/{$task->id}", ['unit_done' => 25])->assertOk();

    $this->getJson("/api/v1/tasks/{$task->task_key}/contexts/draft")
        ->assertOk()
        ->assertExactJson([
            'last_note' => 'Soal 1 dan 2 sudah dinilai',
            'next_step' => 'Soal 3',
            'blocker' => 'Kunci jawaban soal 4 belum ada',
            'unit_done' => 25,
            'links' => [['title' => 'Rubrik', 'url' => 'https://contoh.id/rubrik']],
        ]);
});

it('drafts the next_step from the task when it was edited after the last context', function () {
    $task = makeTask($this->user);
    $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['next_step' => 'Soal 3'])->assertCreated();
    $this->patchJson("/api/v1/tasks/{$task->id}", ['next_step' => 'Soal 4'])->assertOk();

    $this->getJson("/api/v1/tasks/{$task->id}/contexts/draft")->assertJsonPath('next_step', 'Soal 4');
});

it('drafts an empty form for a task without context', function () {
    $task = makeTask($this->user, ['next_step' => 'Baca ulang rubrik']);

    $this->getJson("/api/v1/tasks/{$task->id}/contexts/draft")
        ->assertOk()
        ->assertExactJson([
            'last_note' => null,
            'next_step' => 'Baca ulang rubrik',
            'blocker' => null,
            'unit_done' => null,
            'links' => [],
        ]);
});

it('drafts unit_done 0 for a task with a total but nothing done yet', function () {
    $task = makeTask($this->user, ['unit_total' => 40]);

    $this->getJson("/api/v1/tasks/{$task->id}/contexts/draft")->assertJsonPath('unit_done', 0);
});

it('returns 404 for a task of another owner or a deleted task', function () {
    $other = makeTask(User::factory()->create());
    $deleted = makeTask($this->user);
    $deleted->delete();

    foreach ([$other, $deleted] as $task) {
        $this->getJson("/api/v1/tasks/{$task->id}/contexts")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->postJson("/api/v1/tasks/{$task->id}/contexts", ['last_note' => 'x'])->assertNotFound();
        $this->getJson("/api/v1/tasks/{$task->id}/contexts/draft")->assertNotFound();
    }
});
