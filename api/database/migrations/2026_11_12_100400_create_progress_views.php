<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/tcb_schema.sql (rev. 2): Key Result and Objective progress views.
 *
 * progress = Σ(estimate × fraction done) / Σ estimate over tasks that are not
 * cancelled or deleted; a task without an estimate weighs 60 minutes, and unit
 * progress (FR-23) counts partially.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE VIEW key_result_progress AS
            SELECT kr.id                AS key_result_id,
                   kr.objective_id,
                   kr.weight,
                   CASE
                     WHEN kr.progress_mode = 'manual' THEN
                       CASE WHEN kr.target_value = coalesce(kr.start_value, 0) THEN 0
                            ELSE greatest(0, least(1,
                                   (coalesce(kr.current_value, 0) - coalesce(kr.start_value, 0))
                                   / (kr.target_value - coalesce(kr.start_value, 0)))) END
                     ELSE coalesce(
                       sum(coalesce(t.estimate_minutes, 60) *
                           CASE WHEN t.state = 'done' THEN 1
                                WHEN t.unit_total IS NOT NULL THEN coalesce(t.unit_done, 0) / t.unit_total
                                ELSE 0 END)
                       / nullif(sum(coalesce(t.estimate_minutes, 60)), 0), 0)
                   END::numeric(5,4)    AS progress
              FROM key_results kr
              LEFT JOIN tasks t
                ON t.key_result_id = kr.id AND t.deleted_at IS NULL AND t.state <> 'cancelled'
             GROUP BY kr.id;

            CREATE VIEW objective_progress AS
            SELECT o.id AS objective_id,
                   coalesce(sum(p.weight * p.progress) / nullif(sum(p.weight), 0), 0)::numeric(5,4) AS progress
              FROM objectives o
              LEFT JOIN key_result_progress p ON p.objective_id = o.id
             GROUP BY o.id;
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP VIEW IF EXISTS objective_progress; DROP VIEW IF EXISTS key_result_progress;');
    }
};
