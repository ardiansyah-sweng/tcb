<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

// R1-11 criterion: two concurrent requests to start different tasks -> exactly
// one succeeds (FR-06). The WIP trigger locks the user row (tasks_enforce_wip),
// so the second request sees the first one's running task.
//
// Real concurrency needs committed rows and separate connections, so this
// folder does not use RefreshDatabase: each request runs in its own PHP
// process (tests/Support/concurrent-request.php) and the test cleans up with
// TRUNCATE users CASCADE (the append-only log refuses DELETE). It only ever
// touches tcb_test (forced in phpunit.xml and checked below).

beforeEach(function () {
    expect(DB::connection()->getDatabaseName())->toBe('tcb_test');
    Artisan::call('migrate', ['--force' => true]);
});

afterEach(function () {
    if (DB::connection()->getDatabaseName() === 'tcb_test') {
        DB::statement('truncate table users, personal_access_tokens restart identity cascade');
    }
});

/** Start a child process that sends one request; returns the running process. */
function startRequest(string $method, string $uri, string $token, array $body = [])
{
    return Process::path(base_path())
        ->env([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'pgsql',
            'DB_DATABASE' => 'tcb_test',
            'DB_URL' => '',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
        ])
        ->timeout(60)
        ->start([PHP_BINARY, 'tests/Support/concurrent-request.php', $method, $uri, $token, json_encode((object) $body)]);
}

/** Wait until $count backends of tcb_test are blocked on a lock. */
function waitForLockWaiters(int $count, float $timeoutSeconds = 30): void
{
    $deadline = microtime(true) + $timeoutSeconds;
    $probe = DB::connection('pgsql_probe');

    do {
        $waiting = (int) $probe->selectOne(
            "select count(*) as n from pg_stat_activity where datname = current_database() and wait_event_type = 'Lock'"
        )->n;
        if ($waiting >= $count) {
            return;
        }
        usleep(50_000);
    } while (microtime(true) < $deadline);

    test()->fail("Expected {$count} requests waiting on a lock, saw {$waiting}.");
}

it('lets exactly one of two concurrent start requests through', function () {
    config(['database.connections.pgsql_probe' => config('database.connections.pgsql')]);

    $user = User::factory()->create(['wip_limit' => 1]);
    $token = $user->createToken('race')->plainTextToken;
    $a = Task::factory()->for($user, 'owner')->create(['title' => 'Koreksi UTS'])->fresh();
    $b = Task::factory()->for($user, 'owner')->create(['title' => 'Menulis paper'])->fresh();
    DB::table('tasks')->whereIn('id', [$a->id, $b->id])->update(['state' => 'ready']);

    // Hold the user row so both requests reach the WIP check and wait there
    // together; releasing it lets them race for the last slot.
    DB::beginTransaction();
    DB::select('select id from users where id = ? for update', [$user->id]);

    $first = startRequest('POST', "/api/v1/tasks/{$a->id}/transitions", $token, ['to' => 'running']);
    $second = startRequest('POST', "/api/v1/tasks/{$b->id}/transitions", $token, ['to' => 'running']);

    try {
        waitForLockWaiters(2);
    } finally {
        DB::commit();
    }

    $results = collect([$first->wait(), $second->wait()])->map(function ($result) {
        expect($result->successful())->toBeTrue($result->errorOutput());

        return json_decode($result->output(), true);
    });

    $statuses = $results->pluck('status')->sort()->values()->all();
    expect($statuses)->toBe([200, 409]);

    $winner = $results->firstWhere('status', 200)['body'];
    $loser = $results->firstWhere('status', 409)['body'];

    expect($winner['state'])->toBe('running')
        ->and($loser['code'])->toBe('wip_full')
        ->and($loser['wip_limit'])->toBe(1)
        ->and(array_column($loser['running'], 'task_key'))->toBe([$winner['task_key']]);

    $running = DB::table('tasks')->where('owner_id', $user->id)->where('state', 'running')->pluck('id')->all();
    $openSessions = DB::table('work_sessions')->where('owner_id', $user->id)->whereNull('ended_at')->pluck('task_id')->all();

    expect($running)->toBe([$winner['id']])
        ->and($openSessions)->toBe([$winner['id']]);
});
