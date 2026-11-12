<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/tcb_schema.sql (rev. 3, R1-09): review date for Suspended tasks
 * (SRS section 4: "Ready atau Waiting -> Suspended: isi tanggal tinjau ulang
 * (opsional)"). Only a suspended task may carry one; TransitionService sets it
 * when suspending and clears it when the task leaves Suspended.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tasks
              ADD COLUMN review_on date,
              ADD CONSTRAINT chk_tasks_review_on_suspended
                CHECK (review_on IS NULL OR state = 'suspended');
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE tasks
              DROP CONSTRAINT IF EXISTS chk_tasks_review_on_suspended,
              DROP COLUMN IF EXISTS review_on;
            SQL);
    }
};
