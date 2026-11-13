<?php

use App\Http\Controllers\Api\TaskController;
use App\Models\Dependency;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

// R1-18 (papan status): GET /tasks?sort=score orders Ready tasks by their
// current recommendation score (decision 13 Nov 2026): the score is computed
// on request with RecommendationScorer for every Ready task of the owner,
// no batch is stored, and Task.score carries it (null elsewhere).

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-11-13 02:00:00', 'UTC'));
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function scoreSortReady(User $owner, array $attributes = []): Task
{
    return walkTo(makeTask($owner, $attributes), 'ready');
}

/** @return list<string> */
function scoreSortKeys(TestResponse $response): array
{
    return array_column($response->json('data'), 'task_key');
}

it('orders Ready tasks by score, highest first, with the score on each task', function () {
    $low = scoreSortReady($this->user, ['priority' => 1]);
    $high = scoreSortReady($this->user, ['priority' => 4]);
    $mid = scoreSortReady($this->user, ['priority' => 2]);

    $response = $this->getJson('/api/v1/tasks?state=ready&sort=score')->assertOk();

    expect(scoreSortKeys($response))->toBe([$high->task_key, $mid->task_key, $low->task_key]);
    $scores = array_column($response->json('data'), 'score');
    // JSON writes 0.0 as 0, so check for a number, not a float.
    expect($scores)->each->toBeNumeric();
    expect($scores[0])->toBeGreaterThan($scores[1]);
    expect($scores[1])->toBeGreaterThan($scores[2]);
});

it('gives the same score as GET /recommendations', function () {
    $tasks = [
        scoreSortReady($this->user, ['priority' => 4, 'deadline_at' => '2026-11-14 10:00:00+07']),
        scoreSortReady($this->user, ['priority' => 3, 'estimate_minutes' => 45]),
        scoreSortReady($this->user, ['priority' => 2]),
    ];

    $board = collect($this->getJson('/api/v1/tasks?state=ready&sort=score')->json('data'))->pluck('score', 'id');
    $items = collect($this->getJson('/api/v1/recommendations')->json('items'))->mapWithKeys(fn ($i) => [$i['task']['id'] => $i['score']]);

    foreach ($tasks as $task) {
        expect($board[$task->id])->toEqualWithDelta($items[$task->id], 0.00001);
    }
});

it('does not store a recommendation batch', function () {
    scoreSortReady($this->user, ['priority' => 4]);

    $this->getJson('/api/v1/tasks?state=ready&sort=score')->assertOk();

    expect(Recommendation::count())->toBe(0);
});

it('puts Ready tasks that cannot be recommended after the scored ones, with a null score', function () {
    $blocked = scoreSortReady($this->user, ['priority' => 4]);
    Dependency::create(['owner_id' => $this->user->id, 'task_id' => $blocked->id, 'external_description' => 'Data dari asisten']);
    $free = scoreSortReady($this->user, ['priority' => 1]);

    $response = $this->getJson('/api/v1/tasks?state=ready&sort=score')->assertOk();

    expect(scoreSortKeys($response))->toBe([$free->task_key, $blocked->task_key]);
    expect($response->json('data.1.score'))->toBeNull();
});

it('lists other states after Ready, newest update first, with a null score', function () {
    $new = makeTask($this->user);
    $ready = scoreSortReady($this->user);
    $this->travel(1)->minutes();
    $suspended = walkTo(makeTask($this->user), 'ready', 'suspended');

    $response = $this->getJson('/api/v1/tasks?sort=score')->assertOk();

    expect(scoreSortKeys($response))->toBe([$ready->task_key, $suspended->task_key, $new->task_key]);
    expect($response->json('data.1.score'))->toBeNull();
    expect($response->json('data.2.score'))->toBeNull();
});

it('leaves score null without sort=score', function () {
    scoreSortReady($this->user, ['priority' => 4]);

    $this->getJson('/api/v1/tasks?state=ready')->assertOk()->assertJsonPath('data.0.score', null);
    $this->getJson('/api/v1/tasks?state=ready&sort=updated')->assertOk()->assertJsonPath('data.0.score', null);
});

it('keeps the filters with sort=score', function () {
    $teaching = scoreSortReady($this->user, ['label' => 'Mengajar', 'priority' => 1]);
    scoreSortReady($this->user, ['label' => 'Menulis paper', 'priority' => 4]);

    $response = $this->getJson('/api/v1/tasks?state=ready&label=Mengajar&sort=score')->assertOk();

    expect(scoreSortKeys($response))->toBe([$teaching->task_key]);
    expect($response->json('data.0.score'))->toBeNumeric();
});

it('does not score or list the Ready tasks of another owner', function () {
    $other = User::factory()->create();
    scoreSortReady($other, ['priority' => 4]);
    $mine = scoreSortReady($this->user, ['priority' => 1]);

    $response = $this->getJson('/api/v1/tasks?sort=score')->assertOk();

    expect(scoreSortKeys($response))->toBe([$mine->task_key]);
});

it('pages through the score order with a cursor', function () {
    $count = TaskController::PER_PAGE + 5;
    for ($i = 0; $i < $count; $i++) {
        scoreSortReady($this->user, ['priority' => ($i % 4) + 1, 'estimate_minutes' => 15 + $i]);
    }

    $first = $this->getJson('/api/v1/tasks?state=ready&sort=score')->assertOk();
    $cursor = $first->json('next_cursor');
    expect($cursor)->not->toBeNull();
    $second = $this->getJson('/api/v1/tasks?state=ready&sort=score&cursor='.urlencode($cursor))->assertOk();

    expect($first->json('data'))->toHaveCount(TaskController::PER_PAGE);
    expect($second->json('data'))->toHaveCount(5);
    expect($second->json('next_cursor'))->toBeNull();

    $scores = [...array_column($first->json('data'), 'score'), ...array_column($second->json('data'), 'score')];
    $sorted = $scores;
    rsort($sorted);
    expect($scores)->toBe($sorted);
    $keys = [...scoreSortKeys($first), ...scoreSortKeys($second)];
    expect(array_unique($keys))->toHaveCount($count);
});
