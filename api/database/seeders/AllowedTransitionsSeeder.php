<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Valid task state transitions (SRS section 4).
 *
 * The allowed_transitions table is the single source of transition rules:
 * the database trigger and TaskStateMachine both read it. Do not copy this
 * list anywhere else (CLAUDE.md rule 2). Idempotent; also run by the
 * create_tasks_tables migration so `migrate --force` alone yields a working
 * state machine.
 */
class AllowedTransitionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('allowed_transitions')->insertOrIgnore(array_map(
            fn (array $t) => ['from_state' => $t[0], 'to_state' => $t[1]],
            [
                ['new', 'ready'],
                ['ready', 'running'],
                ['running', 'ready'],
                ['running', 'waiting'],
                ['running', 'done'],
                ['waiting', 'ready'],
                ['ready', 'suspended'],
                ['waiting', 'suspended'],
                ['suspended', 'ready'],
                ['done', 'ready'],      // reopen
                ['cancelled', 'ready'], // reopen
                ['new', 'cancelled'],
                ['ready', 'cancelled'],
                ['running', 'cancelled'],
                ['waiting', 'cancelled'],
                ['suspended', 'cancelled'],
            ],
        ));
    }
}
