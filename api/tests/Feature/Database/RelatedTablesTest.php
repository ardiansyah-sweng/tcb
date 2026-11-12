<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

// R1-02: constraints on tables around tasks (sessions, dependencies, calendar
// links, OKR, contexts, recommendations).

describe('work_sessions', function () {
    it('allows only one open session per task', function () {
        $task = makeTask(User::factory()->create());
        $row = ['owner_id' => $task->owner_id, 'task_id' => $task->id];
        DB::table('work_sessions')->insert($row);

        expectDbConstraint(fn () => DB::table('work_sessions')->insert($row), 'uq_work_sessions_open');
    });

    it('allows many closed sessions and computes duration_minutes', function () {
        $task = makeTask(User::factory()->create());
        foreach ([25, 50] as $minutes) {
            DB::table('work_sessions')->insert([
                'owner_id' => $task->owner_id,
                'task_id' => $task->id,
                'started_at' => '2026-11-12 01:00:00+00',
                'ended_at' => sprintf('2026-11-12 01:%02d:00+00', $minutes),
            ]);
        }
        DB::table('work_sessions')->insert(['owner_id' => $task->owner_id, 'task_id' => $task->id]);

        expect(DB::table('work_sessions')->where('task_id', $task->id)->orderBy('id')->pluck('duration_minutes')->all())
            ->toBe([25, 50, null]);
    });

    it('rejects a session that ends before it starts', function () {
        $task = makeTask(User::factory()->create());

        dbError(fn () => DB::table('work_sessions')->insert([
            'owner_id' => $task->owner_id, 'task_id' => $task->id,
            'started_at' => '2026-11-12 02:00:00+00', 'ended_at' => '2026-11-12 01:00:00+00',
        ]));
    });
});

describe('calendar_links', function () {
    it('allows only one deadline anchor per task', function () {
        $task = makeTask(User::factory()->create());
        $anchor = fn (string $event) => [
            'owner_id' => $task->owner_id, 'task_id' => $task->id, 'calendar_id' => 'primary',
            'event_id' => $event, 'kind' => 'deadline_anchor', 'offset_minutes' => 1440,
        ];
        DB::table('calendar_links')->insert($anchor('ev-1'));

        expectDbConstraint(fn () => DB::table('calendar_links')->insert($anchor('ev-2')), 'uq_calendar_anchor');
    });

    it('requires offset_minutes for a deadline anchor', function () {
        $task = makeTask(User::factory()->create());

        dbError(fn () => DB::table('calendar_links')->insert([
            'owner_id' => $task->owner_id, 'task_id' => $task->id, 'calendar_id' => 'primary',
            'event_id' => 'ev-1', 'kind' => 'deadline_anchor',
        ]));
    });
});

describe('dependencies', function () {
    it('requires exactly one of another task or an external description', function () {
        $user = User::factory()->create();
        $a = makeTask($user);
        $b = makeTask($user);
        $base = ['owner_id' => $user->id, 'task_id' => $a->id];

        dbError(fn () => DB::table('dependencies')->insert($base));
        dbError(fn () => DB::table('dependencies')->insert($base + [
            'depends_on_task_id' => $b->id, 'external_description' => 'Tanda tangan Kaprodi',
        ]));

        DB::table('dependencies')->insert($base + ['depends_on_task_id' => $b->id]);
        DB::table('dependencies')->insert($base + ['external_description' => 'Tanda tangan Kaprodi']);
        expect(DB::table('dependencies')->where('task_id', $a->id)->count())->toBe(2);
    });

    it('rejects a task depending on itself', function () {
        $a = makeTask(User::factory()->create());

        dbError(fn () => DB::table('dependencies')->insert([
            'owner_id' => $a->owner_id, 'task_id' => $a->id, 'depends_on_task_id' => $a->id,
        ]));
    });
});

describe('OKR tables', function () {
    it('rejects an objective that ends before it starts', function () {
        $user = User::factory()->create();

        dbError(fn () => DB::table('objectives')->insert([
            'owner_id' => $user->id, 'title' => 'Semester ganjil',
            'period_start' => '2026-12-31', 'period_end' => '2026-08-01',
        ]));
    });

    it('requires target_value for a manual key result', function () {
        $user = User::factory()->create();
        $objectiveId = DB::table('objectives')->insertGetId([
            'owner_id' => $user->id, 'title' => 'Semester ganjil',
            'period_start' => '2026-08-01', 'period_end' => '2026-12-31',
        ]);
        $kr = ['owner_id' => $user->id, 'objective_id' => $objectiveId, 'title' => 'Publikasi'];

        dbError(fn () => DB::table('key_results')->insert($kr + ['progress_mode' => 'manual']));
        dbError(fn () => DB::table('key_results')->insert($kr + ['weight' => 0]));
        dbError(fn () => DB::table('key_results')->insert($kr + ['weight' => 1.5]));

        DB::table('key_results')->insert($kr + ['progress_mode' => 'manual', 'target_value' => 2]);
        DB::table('key_results')->insert($kr);
        expect(DB::table('key_results')->where('objective_id', $objectiveId)->count())->toBe(2);
    });

    it('makes tasks orphans when their key result is deleted', function () {
        $user = User::factory()->create();
        $objectiveId = DB::table('objectives')->insertGetId([
            'owner_id' => $user->id, 'title' => 'O', 'period_start' => '2026-08-01', 'period_end' => '2026-12-31',
        ]);
        $krId = DB::table('key_results')->insertGetId([
            'owner_id' => $user->id, 'objective_id' => $objectiveId, 'title' => 'KR',
        ]);
        $task = makeTask($user, ['key_result_id' => $krId]);

        DB::table('key_results')->where('id', $krId)->delete();

        expect($task->fresh())->not->toBeNull();
        expect($task->fresh()->key_result_id)->toBeNull();
    });
});

it('requires context links to be a JSON array', function () {
    $task = makeTask(User::factory()->create());

    dbError(fn () => DB::table('context_snapshots')->insert([
        'owner_id' => $task->owner_id, 'task_id' => $task->id, 'links' => '{"url":"x"}',
    ]));
});

it('keeps recommendation scores within 0..1 and ranks unique per batch', function () {
    $task = makeTask(User::factory()->create());
    $row = fn (array $extra) => $extra + [
        'owner_id' => $task->owner_id, 'task_id' => $task->id,
        'batch_id' => '7d1f6a4e-7c1a-4b8e-9a51-0c6f9d1b2a10', 'rank' => 1,
        'score' => 0.5, 'breakdown' => '{}',
    ];

    dbError(fn () => DB::table('recommendations')->insert($row(['score' => 1.2])));
    DB::table('recommendations')->insert($row([]));
    dbError(fn () => DB::table('recommendations')->insert($row([])));
});
