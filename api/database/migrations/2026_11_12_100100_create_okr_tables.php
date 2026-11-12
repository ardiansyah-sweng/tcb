<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/tcb_schema.sql (rev. 2): objectives and key_results (light OKR).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE objectives (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id      bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              title         text        NOT NULL,
              description   text,
              period_start  date        NOT NULL,
              period_end    date        NOT NULL,
              status        text        NOT NULL DEFAULT 'active'
                              CHECK (status IN ('active', 'closed', 'archived')),
              created_at    timestamptz NOT NULL DEFAULT now(),
              updated_at    timestamptz NOT NULL DEFAULT now(),
              CHECK (period_end >= period_start)
            );
            CREATE INDEX idx_objectives_owner ON objectives (owner_id, status);
            CREATE TRIGGER trg_objectives_updated BEFORE UPDATE ON objectives
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE TABLE key_results (
              id             bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id       bigint        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              objective_id   bigint        NOT NULL REFERENCES objectives(id) ON DELETE CASCADE,
              title          text          NOT NULL,
              weight         numeric(4,3)  NOT NULL DEFAULT 1 CHECK (weight > 0 AND weight <= 1),
              -- auto (default): computed from tasks; manual: exception, uses the values below
              progress_mode  text          NOT NULL DEFAULT 'auto'
                               CHECK (progress_mode IN ('auto', 'manual')),
              start_value    numeric,
              target_value   numeric,
              current_value  numeric,
              unit           text,
              created_at     timestamptz   NOT NULL DEFAULT now(),
              updated_at     timestamptz   NOT NULL DEFAULT now(),
              CHECK (progress_mode = 'auto' OR target_value IS NOT NULL)
            );
            CREATE INDEX idx_key_results_objective ON key_results (objective_id);
            CREATE TRIGGER trg_key_results_updated BEFORE UPDATE ON key_results
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS key_results; DROP TABLE IF EXISTS objectives;');
    }
};
