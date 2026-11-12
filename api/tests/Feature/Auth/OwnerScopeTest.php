<?php

use App\Models\ContextSnapshot;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\StateTransition;
use App\Models\Task;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// R1-03: every query is limited to the signed-in user's owner_id (global
// scope), ready for multi-user v2 (CLAUDE.md rule 7).

beforeEach(function () {
    $this->me = User::factory()->create();
    $this->other = User::factory()->create();

    foreach ([$this->me, $this->other] as $user) {
        $objective = Objective::factory()->for($user, 'owner')->create();
        $kr = KeyResult::factory()->for($user, 'owner')->for($objective)->create();
        $task = makeTask($user, ['key_result_id' => $kr->id]);
        ContextSnapshot::create(['owner_id' => $user->id, 'task_id' => $task->id]);
    }
    $this->otherTask = Task::withoutGlobalScopes()->where('owner_id', $this->other->id)->first();
});

it('limits every owned model to the signed-in user', function (string $model) {
    Sanctum::actingAs($this->me);

    $rows = $model::all();

    expect($rows)->not->toBeEmpty();
    expect($rows->pluck('owner_id')->unique()->all())->toBe([$this->me->id]);
})->with([Objective::class, KeyResult::class, Task::class, ContextSnapshot::class, StateTransition::class]);

it('does not find another user\'s record by id or task_key', function () {
    Sanctum::actingAs($this->me);

    expect(Task::find($this->otherTask->id))->toBeNull();
    expect(Task::where('task_key', 'TCB-1')->pluck('owner_id')->all())->toBe([$this->me->id]);
});

it('fills owner_id from the signed-in user when creating', function () {
    Sanctum::actingAs($this->me);

    $task = Task::create(['title' => 'Koreksi UTS']);

    expect($task->owner_id)->toBe($this->me->id);
});

it('does not scope queries without a signed-in user (console, queue)', function () {
    expect(Task::count())->toBe(2);
});
