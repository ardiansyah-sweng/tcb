<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

pest()->extend(TestCase::class)->in('Feature');
pest()->use(RefreshDatabase::class)->in('Feature/Database', 'Feature/Auth', 'Feature/Goal');

/**
 * Forget resolved guards so the next request authenticates from scratch,
 * as a new HTTP request would (the test app instance caches the user).
 */
function freshRequest(): void
{
    app('auth')->forgetGuards();
}

/*
|--------------------------------------------------------------------------
| Database rule helpers
|--------------------------------------------------------------------------
|
| Each test runs inside RefreshDatabase's outer transaction. A statement that
| PostgreSQL rejects aborts the transaction, so expected failures run inside
| DB::transaction() (a savepoint) and are rolled back before the test goes on.
|
*/

/**
 * Run $fn and return the QueryException PostgreSQL raised; fail if none.
 */
function dbError(callable $fn): QueryException
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        // Count the rejection as an assertion so the test is not reported as risky.
        expect($e)->toBeInstanceOf(QueryException::class);

        return $e;
    }

    test()->fail('Expected the database to reject the statement, but it succeeded.');
}

/**
 * Assert that PostgreSQL rejected $fn with the given HINT (e.g. invalid_transition).
 */
function expectDbHint(callable $fn, string $hint): QueryException
{
    $e = dbError($fn);
    expect($e->getMessage())->toMatch('/HINT:\s+'.preg_quote($hint, '/').'\b/');

    return $e;
}

/**
 * Assert that PostgreSQL rejected $fn because of the named constraint.
 */
function expectDbConstraint(callable $fn, string $constraint): QueryException
{
    $e = dbError($fn);
    expect($e->getMessage())->toContain('"'.$constraint.'"');

    return $e;
}

/**
 * Create a task and reload it so trigger-assigned columns (task_key, ...) are present.
 */
function makeTask(User $owner, array $attributes = []): Task
{
    return Task::factory()->for($owner, 'owner')->create($attributes)->fresh();
}

/**
 * Change a task's state directly in SQL. Only schema tests may do this;
 * application code must go through TransitionService (CLAUDE.md rule 1).
 */
function setState(Task $task, string $to, ?string $reason = null): void
{
    $values = ['state' => $to];
    if ($reason !== null) {
        $values['state_reason'] = $reason;
    }
    DB::table('tasks')->where('id', $task->id)->update($values);
}

/**
 * Move a task along allowed transitions to reach $to from its current state.
 */
function walkTo(Task $task, string ...$states): Task
{
    foreach ($states as $state) {
        $reason = in_array($state, ['waiting', 'cancelled'], true) ? 'alasan uji' : null;
        setState($task, $state, $reason);
    }

    return $task->fresh();
}
