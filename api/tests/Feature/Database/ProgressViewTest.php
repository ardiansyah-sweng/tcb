<?php

use App\Models\KeyResult;
use App\Models\Objective;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// R1-02: key_result_progress and objective_progress views.

function krProgress(int $keyResultId): float
{
    return (float) DB::table('key_result_progress')->where('key_result_id', $keyResultId)->value('progress');
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->objective = Objective::factory()->for($this->user, 'owner')->create();
    $this->kr = KeyResult::factory()->for($this->user, 'owner')->for($this->objective)->create();
});

it('counts partial unit progress (22/40 of a 240-minute task = 55%)', function () {
    makeTask($this->user, [
        'key_result_id' => $this->kr->id, 'estimate_minutes' => 240,
        'unit_total' => 40, 'unit_done' => 22, 'unit_name' => 'lembar',
    ]);

    expect(krProgress($this->kr->id))->toBe(0.55);
});

it('weights tasks by estimate, counting a missing estimate as 60 minutes', function () {
    walkTo(makeTask($this->user, ['key_result_id' => $this->kr->id]), 'ready', 'running', 'done'); // 60, done
    makeTask($this->user, ['key_result_id' => $this->kr->id, 'estimate_minutes' => 180]);       // 180, 0

    expect(krProgress($this->kr->id))->toBe(0.25);
});

it('ignores cancelled and soft-deleted tasks', function () {
    walkTo(makeTask($this->user, ['key_result_id' => $this->kr->id]), 'ready', 'running', 'done');
    walkTo(makeTask($this->user, ['key_result_id' => $this->kr->id, 'estimate_minutes' => 600]), 'cancelled');
    makeTask($this->user, ['key_result_id' => $this->kr->id, 'estimate_minutes' => 600])->delete();

    expect(krProgress($this->kr->id))->toBe(1.0);
});

it('reports 0 for a key result without tasks', function () {
    expect(krProgress($this->kr->id))->toBe(0.0);
});

it('uses start, current, and target values for a manual key result, clamped to 0..1', function () {
    $manual = KeyResult::factory()->for($this->user, 'owner')->for($this->objective)->create([
        'progress_mode' => 'manual', 'start_value' => 0, 'target_value' => 4, 'current_value' => 1,
    ]);
    expect(krProgress($manual->id))->toBe(0.25);

    DB::table('key_results')->where('id', $manual->id)->update(['current_value' => 9]);
    expect(krProgress($manual->id))->toBe(1.0);
});

it('averages key results by weight for objective progress', function () {
    // kr (weight 1): 55%; second KR (weight 0.5): 100%  → (0.55 + 0.5) / 1.5 = 0.7
    makeTask($this->user, [
        'key_result_id' => $this->kr->id, 'estimate_minutes' => 240, 'unit_total' => 40, 'unit_done' => 22,
    ]);
    $second = KeyResult::factory()->for($this->user, 'owner')->for($this->objective)->create(['weight' => 0.5]);
    walkTo(makeTask($this->user, ['key_result_id' => $second->id]), 'ready', 'running', 'done');

    $progress = (float) DB::table('objective_progress')->where('objective_id', $this->objective->id)->value('progress');

    expect($progress)->toBe(0.7);
});
