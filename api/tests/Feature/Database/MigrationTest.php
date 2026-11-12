<?php

use App\Models\User;
use Database\Seeders\AllowedTransitionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// R1-02: migrations create every table, constraint, index, trigger, and view
// from docs/tcb_schema.sql (revision 2); the seeder fills allowed_transitions.

it('creates every table from the reference schema', function (string $table) {
    expect(Schema::hasTable($table))->toBeTrue();
})->with([
    'users', 'objectives', 'key_results', 'allowed_transitions', 'tasks',
    'context_snapshots', 'state_transitions', 'work_sessions', 'dependencies',
    'calendar_links', 'proposals', 'recommendations', 'integrations',
]);

it('creates users exactly as in the reference schema', function () {
    $columns = collect(DB::select(
        "select column_name, data_type, is_nullable, is_identity, identity_generation, column_default
           from information_schema.columns
          where table_schema = current_schema() and table_name = 'users'
          order by ordinal_position",
    ))->keyBy('column_name');

    expect($columns->keys()->all())->toBe([
        'id', 'name', 'email', 'password', 'timezone', 'wip_limit', 'score_weights',
        'work_hours', 'default_deadline_offset_minutes', 'next_task_seq', 'created_at', 'updated_at',
    ]);

    expect($columns['id']->data_type)->toBe('bigint');
    expect($columns['id']->is_identity)->toBe('YES');
    expect($columns['id']->identity_generation)->toBe('ALWAYS');

    foreach (['name', 'email', 'password', 'timezone'] as $text) {
        expect($columns[$text]->data_type)->toBe('text');
    }
    expect($columns['password']->is_nullable)->toBe('YES');
    expect($columns['name']->is_nullable)->toBe('NO');
    expect($columns['email']->is_nullable)->toBe('NO');

    foreach (['created_at', 'updated_at'] as $ts) {
        expect($columns[$ts]->data_type)->toBe('timestamp with time zone');
        expect($columns[$ts]->is_nullable)->toBe('NO');
        expect($columns[$ts]->column_default)->toBe('now()');
    }
});

it('keeps user emails unique', function () {
    User::factory()->create(['email' => 'dosen@example.com']);

    dbError(fn () => User::factory()->create(['email' => 'dosen@example.com']));
});

it('creates the progress views', function (string $view) {
    $exists = DB::selectOne(
        'select count(*) as n from information_schema.views where table_schema = current_schema() and table_name = ?',
        [$view],
    )->n;

    expect($exists)->toBe(1);
})->with(['key_result_progress', 'objective_progress']);

it('creates every trigger', function (string $table, string $trigger) {
    $exists = DB::selectOne(
        'select count(*) as n from pg_trigger t join pg_class c on c.oid = t.tgrelid
          where c.relname = ? and t.tgname = ? and not t.tgisinternal',
        [$table, $trigger],
    )->n;

    expect($exists)->toBe(1);
})->with([
    ['users', 'trg_users_updated'],
    ['objectives', 'trg_objectives_updated'],
    ['key_results', 'trg_key_results_updated'],
    ['tasks', 'trg_tasks_updated'],
    ['tasks', 'trg_tasks_assign_key'],
    ['tasks', 'trg_tasks_check_transition'],
    ['tasks', 'trg_tasks_enforce_wip'],
    ['tasks', 'trg_tasks_log_transition'],
    ['state_transitions', 'trg_state_transitions_no_change'],
    ['calendar_links', 'trg_calendar_links_updated'],
    ['integrations', 'trg_integrations_updated'],
]);

it('creates the named indexes', function (string $index) {
    $exists = DB::selectOne(
        'select count(*) as n from pg_indexes where schemaname = current_schema() and indexname = ?',
        [$index],
    )->n;

    expect($exists)->toBe(1);
})->with([
    'idx_objectives_owner', 'idx_key_results_objective',
    'idx_tasks_owner_state', 'idx_tasks_key_result', 'idx_tasks_parent', 'idx_tasks_deadline',
    'uq_tasks_owner_key', 'idx_context_task_time',
    'idx_transitions_task_time', 'idx_transitions_owner_time',
    'uq_work_sessions_open', 'idx_dependencies_on',
    'uq_calendar_anchor', 'idx_calendar_event',
    'idx_proposals_pending', 'idx_recommendations_owner_time',
]);

it('seeds exactly the 16 allowed transitions', function () {
    $rows = DB::table('allowed_transitions')
        ->orderBy('from_state')->orderBy('to_state')
        ->get()
        ->map(fn ($r) => "{$r->from_state}>{$r->to_state}")
        ->all();

    // Verifies the seeded data against SRS section 4; the application itself
    // never keeps a copy of this list (CLAUDE.md rule 2).
    expect($rows)->toBe([
        'cancelled>ready',
        'done>ready',
        'new>cancelled', 'new>ready',
        'ready>cancelled', 'ready>running', 'ready>suspended',
        'running>cancelled', 'running>done', 'running>ready', 'running>waiting',
        'suspended>cancelled', 'suspended>ready',
        'waiting>cancelled', 'waiting>ready', 'waiting>suspended',
    ]);
});

it('keeps the seeder idempotent', function () {
    $this->seed(AllowedTransitionsSeeder::class);
    $this->seed(AllowedTransitionsSeeder::class);

    expect(DB::table('allowed_transitions')->count())->toBe(16);
});

it('runs migrate:fresh --seed on an empty database', function () {
    // RefreshDatabase already ran migrate:fresh; this exercises the full
    // command (with seeding) the way developers run it.
    $this->artisan('migrate:fresh', ['--seed' => true])->assertSuccessful();

    expect(DB::table('allowed_transitions')->count())->toBe(16);
});
