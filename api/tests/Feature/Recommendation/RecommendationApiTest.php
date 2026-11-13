<?php

use App\Models\Dependency;
use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

// R1-14: recommendation endpoints (FR-12, FR-13, SRS bagian 6).
// Contract: GET /recommendations, POST /recommendations/{id}/decision,
// schemas RecommendationBatch and Recommendation in docs/tcb_openapi.yaml.
//
// Fixed clock: Friday 13 Nov 2026, 09:00 Asia/Jakarta (02:00 UTC). The API
// reads "now" through Psr\Clock\ClockInterface, which follows Carbon's test
// time, so travelTo() moves the scorer's clock as well.

const REC_NOW_UTC = '2026-11-13 02:00:00';

beforeEach(function () {
    $this->travelTo(Carbon::parse(REC_NOW_UTC, 'UTC'));
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

/** A Ready task that entered Ready $daysAgo days before the fixed clock. */
function recReady(User $owner, array $attributes = [], float $daysAgo = 0): Task
{
    $task = walkTo(makeTask($owner, $attributes), 'ready');
    DB::table('tasks')->where('id', $task->id)->update([
        'ready_since' => Carbon::now()->subSeconds((int) round($daysAgo * 86400)),
    ]);

    return $task->fresh();
}

function recKeyResult(User $owner, float $weight): KeyResult
{
    $objective = Objective::factory()->for($owner, 'owner')->create();

    return KeyResult::factory()->for($objective)->create(['weight' => $weight]);
}

function recGet(string $query = ''): TestResponse
{
    return test()->getJson('/api/v1/recommendations'.$query);
}

/** @return list<string> task keys of the batch, in rank order */
function recKeys(TestResponse $response): array
{
    return array_map(fn (array $item) => $item['task']['task_key'], $response->json('items'));
}

function recDecide(int $id, string $decision): TestResponse
{
    return test()->postJson("/api/v1/recommendations/{$id}/decision", ['decision' => $decision]);
}

/*
| Criterion: GET /recommendations?limit=3 stores one batch (batch_id, rank,
| score, breakdown, reasons) and returns it.
*/

it('stores one batch of the top three and returns it', function () {
    $critical = recReady($this->user, ['title' => 'Koreksi UTS', 'priority' => 4]);
    $high = recReady($this->user, ['title' => 'Menulis paper', 'priority' => 3]);
    $medium = recReady($this->user, ['title' => 'Siapkan kuliah', 'priority' => 2]);
    recReady($this->user, ['title' => 'Arsip surel', 'priority' => 1]);

    $response = recGet('?limit=3')->assertOk();

    expect(array_keys($response->json()))->toBe(['batch_id', 'generated_at', 'running_full', 'items'])
        ->and(Str::isUuid($response->json('batch_id')))->toBeTrue()
        ->and(Carbon::parse($response->json('generated_at'))->eq(Carbon::now()))->toBeTrue()
        ->and($response->json('running_full'))->toBeFalse()
        ->and(recKeys($response))->toBe([$critical->task_key, $high->task_key, $medium->task_key])
        ->and(array_column($response->json('items'), 'rank'))->toBe([1, 2, 3]);

    $rows = Recommendation::orderBy('rank')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('batch_id')->unique()->all())->toBe([$response->json('batch_id')])
        ->and($rows->pluck('task_id')->all())->toBe([$critical->id, $high->id, $medium->id])
        ->and($rows->pluck('decision')->unique()->all())->toBe([null])
        ->and($rows->every(fn (Recommendation $r) => $r->generated_at->eq(Carbon::now())))->toBeTrue();

    foreach ($response->json('items') as $i => $item) {
        expect($item['id'])->toBe($rows[$i]->id)
            ->and($item['score'])->toEqual($rows[$i]->score)
            ->and($item['breakdown'])->toEqual($rows[$i]->breakdown)
            ->and($item['reasons'])->toBe($rows[$i]->reasons);
    }
});

it('returns every field of the Recommendation schema', function () {
    recReady($this->user, ['title' => 'Koreksi UTS', 'priority' => 3]);

    $item = recGet()->assertOk()->json('items.0');

    expect(array_keys($item))->toBe(['id', 'rank', 'score', 'breakdown', 'reasons', 'stale', 'suggested_slot', 'task'])
        ->and($item['suggested_slot'])->toBeNull()
        ->and(array_keys($item['breakdown']))->toBe(['goal', 'urgency', 'priority', 'aging'])
        ->and($item['task'])->toHaveKeys(['id', 'task_key', 'title', 'state', 'priority', 'ready_since'])
        ->and($item['task']['state'])->toBe('ready');
});

it('scores with the R1-13 scorer, the key result progress and the user weights', function () {
    $kr = recKeyResult($this->user, 0.8);
    $goal = recReady($this->user, ['title' => 'Bab 3 paper', 'key_result_id' => $kr->id, 'priority' => 1]);
    $deadline = recReady($this->user, [
        'title' => 'Nilai UTS', 'priority' => 1,
        'deadline_at' => Carbon::parse('2026-11-14 10:00', 'Asia/Jakarta'), // Saturday
    ], daysAgo: 9);

    $items = recGet()->assertOk()->json('items');

    // G = 0.8 × (1 − 0) = 0.8 → 0.30/0.90 × 0.8 = 0.2667.
    $goalItem = collect($items)->firstWhere('task.task_key', $goal->task_key);
    expect($goalItem['breakdown'])->toEqual(['goal' => 0.8, 'urgency' => 0.0, 'priority' => 0.0, 'aging' => 0.0])
        ->and($goalItem['score'])->toEqual(0.2667)
        ->and($goalItem['reasons'])->toBe(['Key Result bobot tinggi, progres masih 0%']);

    $deadlineItem = collect($items)->firstWhere('task.task_key', $deadline->task_key);
    expect($deadlineItem['reasons'])->toBe(['Deadline Sabtu, 14 Nov', 'Sudah 9 hari di Ready']);

    // Doubling the goal weight changes the score of the goal task only through the profile.
    $this->user->forceFill(['score_weights' => ['goal' => 0.9, 'urgency' => 0, 'priority' => 0, 'aging' => 0, 'fit' => 0]])->save();
    $items = recGet()->assertOk()->json('items');
    expect(collect($items)->firstWhere('task.task_key', $goal->task_key)['score'])->toEqual(0.8);
});

it('recommends only eligible tasks of the owner', function () {
    $eligible = recReady($this->user, ['title' => 'Layak']);
    makeTask($this->user, ['title' => 'Masih New']);
    walkTo(makeTask($this->user, ['title' => 'Sedang Waiting']), 'ready', 'running', 'waiting');
    walkTo(makeTask($this->user, ['title' => 'Suspended']), 'ready', 'suspended');

    $deleted = recReady($this->user, ['title' => 'Dihapus']);
    $deleted->delete();

    $blocked = recReady($this->user, ['title' => 'Terhambat']);
    Dependency::create(['owner_id' => $this->user->id, 'task_id' => $blocked->id, 'external_description' => 'Data dari asisten']);

    $parent = recReady($this->user, ['title' => 'Induk dengan subtask terbuka']);
    makeTask($this->user, ['title' => 'Subtask terbuka', 'parent_task_id' => $parent->id]);

    $finishedParent = recReady($this->user, ['title' => 'Induk yang subtasknya selesai']);
    walkTo(makeTask($this->user, ['parent_task_id' => $finishedParent->id]), 'ready', 'running', 'done');
    walkTo(makeTask($this->user, ['parent_task_id' => $finishedParent->id]), 'cancelled');

    recReady(User::factory()->create(), ['title' => 'Milik orang lain', 'priority' => 4]);

    $response = recGet('?limit=10')->assertOk();

    expect(recKeys($response))->toEqualCanonicalizing([$eligible->task_key, $finishedParent->task_key])
        ->and(Recommendation::withoutGlobalScope('owner')->where('owner_id', '!=', $this->user->id)->count())->toBe(0);
});

it('honours the limit, 3 by default', function () {
    foreach (range(1, 5) as $i) {
        recReady($this->user, ['title' => "Task {$i}"]);
    }

    expect(recGet()->json('items'))->toHaveCount(3)
        ->and(recGet('?limit=1')->json('items'))->toHaveCount(1)
        ->and(recGet('?limit=10')->json('items'))->toHaveCount(5);
});

it('rejects a limit outside 1 to 10', function (string $limit) {
    recGet("?limit={$limit}")
        ->assertStatus(422)
        ->assertJsonValidationErrors(['limit' => 'Batas rekomendasi harus bilangan bulat 1 sampai 10.']);

    expect(Recommendation::count())->toBe(0);
})->with(['0', '11', 'tiga', '-1']);

it('returns an empty batch and stores nothing when no task is eligible', function () {
    makeTask($this->user, ['title' => 'Masih New']);

    $response = recGet()->assertOk();

    expect(Str::isUuid($response->json('batch_id')))->toBeTrue()
        ->and($response->json('items'))->toBe([])
        ->and(Recommendation::count())->toBe(0);
});

it('stores a new batch on every request', function () {
    recReady($this->user);

    $first = recGet()->json('batch_id');
    $second = recGet()->json('batch_id');

    expect($first)->not->toBe($second)
        ->and(Recommendation::count())->toBe(2);
});

it('requires a token', function () {
    app('auth')->forgetGuards();
    $this->withHeader('Authorization', 'Bearer nope');

    recGet()->assertStatus(401);
    recDecide(1, 'picked')->assertStatus(401);
});

/*
| Criterion: running_full is true when the WIP limit is full, so the UI shows
| "Pindah ke task ini".
*/

it('reports running_full when the running tasks fill the WIP limit', function () {
    recReady($this->user, ['title' => 'Kandidat']);
    expect(recGet()->json('running_full'))->toBeFalse();

    walkTo(makeTask($this->user, ['title' => 'Sedang berjalan']), 'ready', 'running');
    expect(recGet()->json('running_full'))->toBeTrue();

    $this->user->forceFill(['wip_limit' => 2])->save();
    expect(recGet()->json('running_full'))->toBeFalse();
});

it('does not count running tasks of other users toward running_full', function () {
    recReady($this->user);
    walkTo(makeTask(User::factory()->create()), 'ready', 'running');

    expect(recGet()->json('running_full'))->toBeFalse();
});

/*
| Criterion: POST /recommendations/{id}/decision accepts picked, skipped,
| scheduled.
*/

it('records each decision with its time', function (string $decision) {
    $task = recReady($this->user);
    $id = recGet()->json('items.0.id');

    $this->travel(5)->minutes();
    recDecide($id, $decision)->assertNoContent();

    $row = Recommendation::find($id);
    expect($row->decision)->toBe($decision)
        ->and($row->decided_at->eq(Carbon::now()))->toBeTrue()
        ->and($task->fresh()->state)->toBe('ready'); // picked only records (contract)
})->with(['picked', 'skipped', 'scheduled']);

it('lets a later decision replace an earlier one', function () {
    recReady($this->user);
    $id = recGet()->json('items.0.id');

    recDecide($id, 'skipped')->assertNoContent();
    recDecide($id, 'picked')->assertNoContent();

    expect(Recommendation::find($id)->decision)->toBe('picked');
});

it('rejects a missing or unknown decision', function (array $body, string $message) {
    recReady($this->user);
    $id = recGet()->json('items.0.id');

    $this->postJson("/api/v1/recommendations/{$id}/decision", $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors(['decision' => $message]);

    expect(Recommendation::find($id)->decision)->toBeNull();
})->with([
    'missing' => [[], 'Keputusan wajib diisi.'],
    'unknown' => [['decision' => 'ignored'], 'Keputusan harus picked, skipped, atau scheduled.'],
]);

it('returns 404 for an unknown recommendation or one of another user', function () {
    $other = User::factory()->create();
    $theirs = Recommendation::create([
        'owner_id' => $other->id,
        'task_id' => recReady($other)->id,
        'batch_id' => (string) Str::uuid(), 'rank' => 1, 'score' => 0.5, 'breakdown' => ['goal' => 0.5],
    ]);

    recDecide($theirs->id, 'picked')
        ->assertNotFound()
        ->assertExactJson(['code' => 'not_found', 'message' => 'Rekomendasi tidak ditemukan.']);
    recDecide(999999, 'picked')->assertNotFound();
    $this->postJson('/api/v1/recommendations/abc/decision', ['decision' => 'picked'])->assertNotFound();

    expect($theirs->fresh()->decision)->toBeNull();
});

/*
| Criterion: a skipped task does not appear in the next batches of the same
| day, unless there is no other candidate. "Day" is the user's timezone
| (Asia/Jakarta); "no other candidate" means no eligible task that was not
| skipped today (decision 13 Nov 2026: remaining slots stay empty).
*/

it('leaves a task skipped today out of the next batches', function () {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 3]);
    $c = recReady($this->user, ['title' => 'C', 'priority' => 2]);

    $first = recGet('?limit=2');
    expect(recKeys($first))->toBe([$a->task_key, $b->task_key]);
    recDecide($first->json('items.0.id'), 'skipped')->assertNoContent();

    expect(recKeys(recGet('?limit=2')))->toBe([$b->task_key, $c->task_key]);
});

it('brings a skipped task back on the next day in the user timezone', function () {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 2]);

    // Skipped at 23:30 Jakarta on Friday (16:30 UTC).
    $this->travelTo(Carbon::parse('2026-11-13 23:30', 'Asia/Jakarta'));
    recDecide(recGet()->json('items.0.id'), 'skipped')->assertNoContent();

    $this->travelTo(Carbon::parse('2026-11-13 23:59', 'Asia/Jakarta'));
    expect(recKeys(recGet()))->toBe([$b->task_key]);

    // 00:05 Jakarta on Saturday is still Friday 17:05 in UTC.
    $this->travelTo(Carbon::parse('2026-11-14 00:05', 'Asia/Jakarta'));
    expect(recKeys(recGet()))->toBe([$a->task_key, $b->task_key]);
});

it('uses the timezone stored for the user', function () {
    $this->user->forceFill(['timezone' => 'UTC'])->save();
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 2]);

    // 23:30 Jakarta on Friday is 16:30 UTC; the UTC day ends 7.5 hours later.
    $this->travelTo(Carbon::parse('2026-11-13 16:30', 'UTC'));
    recDecide(recGet()->json('items.0.id'), 'skipped')->assertNoContent();

    $this->travelTo(Carbon::parse('2026-11-13 17:05', 'UTC'));
    expect(recKeys(recGet()))->toBe([$b->task_key]);

    $this->travelTo(Carbon::parse('2026-11-14 00:05', 'UTC'));
    expect(recKeys(recGet()))->toBe([$a->task_key, $b->task_key]);
});

it('shows skipped tasks again when there is no other candidate', function () {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 3]);

    $batch = recGet();
    recDecide($batch->json('items.0.id'), 'skipped');
    recDecide($batch->json('items.1.id'), 'skipped');

    expect(recKeys(recGet()))->toBe([$a->task_key, $b->task_key]);
});

it('does not fill the remaining slots with skipped tasks', function () {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 3]);
    $c = recReady($this->user, ['title' => 'C', 'priority' => 1]);

    $batch = recGet();
    recDecide($batch->json('items.0.id'), 'skipped');
    recDecide($batch->json('items.1.id'), 'skipped');

    expect(recKeys(recGet()))->toBe([$c->task_key]);
});

it('only lets the skipped decision hide a task', function (string $decision) {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 2]);

    recDecide(recGet()->json('items.0.id'), $decision);

    expect(recKeys(recGet()))->toBe([$a->task_key, $b->task_key]);
})->with(['picked', 'scheduled']);

it('ignores skips of other users', function () {
    $a = recReady($this->user, ['title' => 'A', 'priority' => 4]);
    $b = recReady($this->user, ['title' => 'B', 'priority' => 2]);
    $other = User::factory()->create();
    Recommendation::create([
        'owner_id' => $other->id, 'task_id' => $a->id,
        'batch_id' => (string) Str::uuid(), 'rank' => 1, 'score' => 0.5, 'breakdown' => ['goal' => 0.5],
        'decision' => 'skipped', 'decided_at' => Carbon::now(),
    ]);

    expect(recKeys(recGet()))->toBe([$a->task_key, $b->task_key]);
});

/*
| Criterion (decision 13 Nov 2026): each item carries stale (boolean, from
| RecommendationScorer), computed when the batch is made and not stored.
*/

it('marks tasks more than 14 days in Ready as stale', function () {
    $stale = recReady($this->user, ['title' => 'Lama', 'priority' => 1], daysAgo: 15);
    $exact = recReady($this->user, ['title' => 'Tepat 14 hari', 'priority' => 1], daysAgo: 14);
    $fresh = recReady($this->user, ['title' => 'Baru', 'priority' => 1]);

    $items = collect(recGet()->json('items'))->keyBy('task.task_key');

    expect($items[$stale->task_key]['stale'])->toBeTrue()
        ->and($items[$exact->task_key]['stale'])->toBeFalse()
        ->and($items[$fresh->task_key]['stale'])->toBeFalse()
        ->and($items[$stale->task_key]['reasons'])->toContain('Sudah 15 hari di Ready');

    expect(DB::getSchemaBuilder()->hasColumn('recommendations', 'stale'))->toBeFalse();
});
