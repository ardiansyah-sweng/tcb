<?php

use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

// R1-04: Objective API (FR-01). Contract: /objectives in docs/tcb_openapi.yaml.

beforeEach(function () {
    $this->user = User::factory()->create();
    Sanctum::actingAs($this->user);
});

function objectiveFor(User $user, array $attributes = []): Objective
{
    return Objective::factory()->for($user, 'owner')->create($attributes);
}

/*
| Criterion: GET/POST /objectives, GET/PATCH /objectives/{id} according to OpenAPI.
*/

it('creates an objective and returns it with status active and progress 0', function () {
    $response = $this->postJson('/api/v1/objectives', [
        'title' => 'Publikasi Q4',
        'period_start' => '2026-10-01',
        'period_end' => '2026-12-31',
    ]);

    $id = Objective::where('title', 'Publikasi Q4')->value('id');

    $response->assertCreated()->assertExactJson([
        'id' => $id,
        'title' => 'Publikasi Q4',
        'description' => null,
        'period_start' => '2026-10-01',
        'period_end' => '2026-12-31',
        'status' => 'active',
        'progress' => 0,
    ]);
    expect(Objective::find($id)->owner_id)->toBe($this->user->id);
});

it('stores the optional description', function () {
    $this->postJson('/api/v1/objectives', [
        'title' => 'Mengajar baik',
        'description' => 'Semester ganjil',
        'period_start' => '2026-09-01',
        'period_end' => '2027-01-31',
    ])->assertCreated()->assertJsonPath('description', 'Semester ganjil');
});

it('ignores status and owner_id when creating', function () {
    $other = User::factory()->create();

    $this->postJson('/api/v1/objectives', [
        'title' => 'Publikasi',
        'period_start' => '2026-10-01',
        'period_end' => '2026-12-31',
        'status' => 'archived',
        'owner_id' => $other->id,
    ])->assertCreated()->assertJsonPath('status', 'active');

    expect(Objective::withoutGlobalScopes()->where('owner_id', $other->id)->count())->toBe(0);
});

it('lists objectives wrapped in data', function () {
    $a = objectiveFor($this->user, ['title' => 'A', 'period_start' => '2026-07-01', 'period_end' => '2026-09-30']);
    $b = objectiveFor($this->user, ['title' => 'B', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31']);

    $this->getJson('/api/v1/objectives')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $b->id) // newest period first
        ->assertJsonPath('data.1.id', $a->id)
        ->assertJsonStructure(['data' => [['id', 'title', 'description', 'period_start', 'period_end', 'status', 'progress']]]);
});

it('shows an objective with its key results', function () {
    $objective = objectiveFor($this->user, ['title' => 'Publikasi', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31']);
    $kr = KeyResult::factory()->for($this->user, 'owner')->for($objective)->create(['title' => '2 paper submit', 'weight' => 0.5]);

    $this->getJson("/api/v1/objectives/{$objective->id}")
        ->assertOk()
        ->assertJsonPath('id', $objective->id)
        ->assertJsonPath('title', 'Publikasi')
        ->assertJsonPath('period_start', '2026-10-01')
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('progress', 0)
        ->assertJsonCount(1, 'key_results')
        ->assertJsonPath('key_results.0', [
            'id' => $kr->id,
            'objective_id' => $objective->id,
            'title' => '2 paper submit',
            'weight' => 0.5,
            'progress_mode' => 'auto',
            'start_value' => null,
            'target_value' => null,
            'current_value' => null,
            'unit' => null,
            'progress' => 0,
        ]);
});

it('updates title and description partially', function () {
    $objective = objectiveFor($this->user, ['title' => 'Lama', 'description' => 'catatan']);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['title' => 'Baru'])
        ->assertOk()
        ->assertJsonPath('title', 'Baru')
        ->assertJsonPath('description', 'catatan')
        ->assertJsonStructure(['id', 'title', 'description', 'period_start', 'period_end', 'status', 'progress']);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['description' => null])
        ->assertOk()
        ->assertJsonPath('description', null);
});

it('returns 404 not_found for a missing objective or another owner\'s objective', function () {
    $foreign = objectiveFor(User::factory()->create());

    foreach (["/api/v1/objectives/{$foreign->id}", '/api/v1/objectives/999999'] as $uri) {
        $this->getJson($uri)->assertNotFound()->assertExactJson([
            'code' => 'not_found',
            'message' => 'Objective tidak ditemukan.',
        ]);
        $this->patchJson($uri, ['title' => 'x'])->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    // A non-numeric id does not match the route (and never reaches PostgreSQL as bigint).
    $this->getJson('/api/v1/objectives/abc')->assertNotFound()->assertJsonPath('code', 'not_found');

    expect(Objective::withoutGlobalScopes()->find($foreign->id)->title)->not->toBe('x');
});

it('does not list another owner\'s objectives', function () {
    objectiveFor(User::factory()->create());
    $mine = objectiveFor($this->user);

    $this->getJson('/api/v1/objectives')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $mine->id);
});

/*
| Criterion: title, period start, and period end are required; end >= start (422 otherwise).
*/

it('requires title, period_start, and period_end', function () {
    $this->postJson('/api/v1/objectives', [])
        ->assertUnprocessable()
        ->assertJsonPath('errors.title.0', 'Judul wajib diisi.')
        ->assertJsonPath('errors.period_start.0', 'Periode mulai wajib diisi.')
        ->assertJsonPath('errors.period_end.0', 'Periode akhir wajib diisi.');

    $this->postJson('/api/v1/objectives', ['title' => '   ', 'period_start' => '2026-10-01', 'period_end' => '2026-12-31'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title']);

    expect(Objective::count())->toBe(0);
});

it('rejects a period end before the period start', function () {
    $this->postJson('/api/v1/objectives', [
        'title' => 'Publikasi',
        'period_start' => '2026-12-31',
        'period_end' => '2026-10-01',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.period_end.0', 'Periode akhir tidak boleh sebelum periode mulai.');
});

it('accepts a period that starts and ends on the same day', function () {
    $this->postJson('/api/v1/objectives', [
        'title' => 'Satu hari',
        'period_start' => '2026-11-12',
        'period_end' => '2026-11-12',
    ])->assertCreated();
});

it('rejects dates that are not YYYY-MM-DD', function () {
    $this->postJson('/api/v1/objectives', [
        'title' => 'Publikasi',
        'period_start' => '12/10/2026',
        'period_end' => '2026-02-30',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('errors.period_start.0', 'Periode mulai harus berupa tanggal dengan format YYYY-MM-DD.')
        ->assertJsonPath('errors.period_end.0', 'Periode akhir harus berupa tanggal dengan format YYYY-MM-DD.');
});

it('checks a partial period update against the stored dates', function () {
    $objective = objectiveFor($this->user, ['period_start' => '2026-10-01', 'period_end' => '2026-12-31']);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['period_end' => '2026-09-30'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.period_end.0', 'Periode akhir tidak boleh sebelum periode mulai.');

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['period_start' => '2027-01-01'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.period_end.0', 'Periode akhir tidak boleh sebelum periode mulai.');

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['period_start' => '2027-01-01', 'period_end' => '2027-03-31'])
        ->assertOk()
        ->assertJsonPath('period_start', '2027-01-01')
        ->assertJsonPath('period_end', '2027-03-31');
});

it('does not let PATCH empty a required field', function () {
    $objective = objectiveFor($this->user, ['title' => 'Publikasi']);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['title' => '', 'period_start' => null])
        ->assertUnprocessable()
        ->assertJsonPath('errors.title.0', 'Judul wajib diisi.')
        ->assertJsonPath('errors.period_start.0', 'Periode mulai wajib diisi.');

    expect($objective->fresh()->title)->toBe('Publikasi');
});

/*
| Criterion: status can change to closed or archived; archived objectives are
| hidden from the default list.
*/

it('closes and archives an objective, and can reactivate it', function (string $status) {
    $objective = objectiveFor($this->user);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['status' => $status])
        ->assertOk()
        ->assertJsonPath('status', $status);

    expect($objective->fresh()->status)->toBe($status);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['status' => 'active'])
        ->assertOk()
        ->assertJsonPath('status', 'active');
})->with(['closed', 'archived']);

it('rejects an unknown status', function () {
    $objective = objectiveFor($this->user);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['status' => 'deleted'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', 'Status harus salah satu dari: active, closed, archived.');
});

it('hides archived objectives from the default list but keeps closed ones', function () {
    $active = objectiveFor($this->user, ['period_start' => '2026-10-01']);
    $closed = objectiveFor($this->user, ['period_start' => '2026-07-01', 'status' => 'closed']);
    objectiveFor($this->user, ['status' => 'archived']);

    $ids = collect($this->getJson('/api/v1/objectives')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$active->id, $closed->id]);
});

it('filters the list by status', function (string $status) {
    $byStatus = [
        'active' => objectiveFor($this->user),
        'closed' => objectiveFor($this->user, ['status' => 'closed']),
        'archived' => objectiveFor($this->user, ['status' => 'archived']),
    ];

    $this->getJson("/api/v1/objectives?status={$status}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $byStatus[$status]->id);
})->with(['active', 'closed', 'archived']);

it('rejects an unknown status filter', function () {
    $this->getJson('/api/v1/objectives?status=semua')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('still shows an archived objective by id', function () {
    $archived = objectiveFor($this->user, ['status' => 'archived']);

    $this->getJson("/api/v1/objectives/{$archived->id}")
        ->assertOk()
        ->assertJsonPath('status', 'archived');
});

/*
| Criterion: the response carries progress from the objective_progress view.
*/

it('reports progress from objective_progress in list, detail, and update', function () {
    $objective = objectiveFor($this->user);
    // KR 1 (weight 1): 22/40 of a 240-minute task = 55%.
    $kr1 = KeyResult::factory()->for($this->user, 'owner')->for($objective)->create();
    makeTask($this->user, [
        'key_result_id' => $kr1->id, 'estimate_minutes' => 240, 'unit_total' => 40, 'unit_done' => 22,
    ]);
    // KR 2 (weight 0.5): manual, 4 of 4 = 100%.
    KeyResult::factory()->for($this->user, 'owner')->for($objective)->create([
        'weight' => 0.5, 'progress_mode' => 'manual', 'start_value' => 0, 'target_value' => 4, 'current_value' => 4,
    ]);
    // (0.55 × 1 + 1 × 0.5) / 1.5 = 0.7
    $this->getJson('/api/v1/objectives')->assertJsonPath('data.0.progress', 0.7);

    $this->getJson("/api/v1/objectives/{$objective->id}")
        ->assertJsonPath('progress', 0.7)
        ->assertJsonPath('key_results.0.progress', 0.55)
        ->assertJsonPath('key_results.1.progress', 1);

    $this->patchJson("/api/v1/objectives/{$objective->id}", ['status' => 'closed'])
        ->assertJsonPath('progress', 0.7);
});
