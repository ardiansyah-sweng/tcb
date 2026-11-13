<?php

use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

// R1-16 (decision 13 Nov 2026): Task.key_result_title, so the Beranda can show
// the KR of the running task without extra requests. Read-only; null for orphans.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
    $objective = Objective::factory()->for($this->user, 'owner')->create();
    $this->kr = KeyResult::factory()->for($this->user, 'owner')->for($objective)
        ->create(['title' => 'Nilai ujian diunggah sebelum batas']);
});

it('returns the key result title when creating a linked task', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Koreksi UTS', 'key_result_id' => $this->kr->id])
        ->assertCreated()
        ->assertJsonPath('key_result_title', 'Nilai ujian diunggah sebelum batas');
});

it('returns null for an orphan task', function () {
    $this->postJson('/api/v1/tasks', ['title' => 'Susun soal UAS'])
        ->assertCreated()
        ->assertJsonPath('is_orphan', true)
        ->assertJsonPath('key_result_title', null);
});

it('includes the title in the task list without a query per task', function () {
    makeTask($this->user, ['key_result_id' => $this->kr->id]);
    makeTask($this->user, ['key_result_id' => $this->kr->id]);
    makeTask($this->user);

    DB::enableQueryLog();
    $response = $this->getJson('/api/v1/tasks')->assertOk();
    $krQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'key_results'));
    DB::disableQueryLog();

    expect(array_column($response->json('data'), 'key_result_title'))
        ->toEqualCanonicalizing(['Nilai ujian diunggah sebelum batas', 'Nilai ujian diunggah sebelum batas', null]);
    expect($krQueries)->toHaveCount(1);
});

it('includes the title in the task detail and follows PATCH', function () {
    $task = makeTask($this->user);
    $this->getJson("/api/v1/tasks/{$task->task_key}")->assertJsonPath('key_result_title', null);

    $this->patchJson("/api/v1/tasks/{$task->id}", ['key_result_id' => $this->kr->id])
        ->assertOk()
        ->assertJsonPath('key_result_title', 'Nilai ujian diunggah sebelum batas');
});

it('includes the title in recommended tasks', function () {
    walkTo(makeTask($this->user, ['key_result_id' => $this->kr->id]), 'ready');

    $this->getJson('/api/v1/recommendations')
        ->assertOk()
        ->assertJsonPath('items.0.task.key_result_title', 'Nilai ujian diunggah sebelum batas');
});

it('includes the title in a transition response', function () {
    $task = walkTo(makeTask($this->user, ['key_result_id' => $this->kr->id]), 'ready');

    $this->postJson("/api/v1/tasks/{$task->id}/transitions", ['to' => 'running'])
        ->assertOk()
        ->assertJsonPath('key_result_title', 'Nilai ujian diunggah sebelum batas');
});
