<?php

use Database\Seeders\AllowedTransitionsSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/tcb_schema.sql (rev. 2): allowed_transitions, tasks (Task Control Block),
 * context_snapshots, state_transitions, and the triggers that guard them:
 * task_key, valid transitions, WIP limit, and the append-only transition log.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- Valid transitions (SRS section 4). Single source of truth; rows come
            -- from AllowedTransitionsSeeder.
            CREATE TABLE allowed_transitions (
              from_state  text NOT NULL,
              to_state    text NOT NULL,
              PRIMARY KEY (from_state, to_state)
            );

            CREATE TABLE tasks (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id          bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_key          text        NOT NULL,                     -- set by trigger: TCB-n
              title             text        NOT NULL CHECK (length(btrim(title)) > 0),
              description       text,
              key_result_id     bigint      REFERENCES key_results(id) ON DELETE SET NULL,  -- NULL = orphan
              parent_task_id    bigint      REFERENCES tasks(id) ON DELETE SET NULL,
              label             text,

              state             text        NOT NULL DEFAULT 'new'
                                  CHECK (state IN ('new','ready','running','waiting','suspended','done','cancelled')),
              state_reason      text,
              state_changed_at  timestamptz NOT NULL DEFAULT now(),
              ready_since       timestamptz,

              priority          smallint    NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 4),
              deadline_at       timestamptz,
              deadline_source   text        NOT NULL DEFAULT 'manual'
                                  CHECK (deadline_source IN ('manual', 'calendar_event')),
              estimate_minutes  integer     CHECK (estimate_minutes > 0),
              energy            text        NOT NULL DEFAULT 'focus'
                                  CHECK (energy IN ('focus', 'light')),

              next_step         text,

              unit_done         numeric     CHECK (unit_done >= 0),
              unit_total        numeric     CHECK (unit_total > 0),
              unit_name         text,

              time_spent_minutes integer    NOT NULL DEFAULT 0 CHECK (time_spent_minutes >= 0),
              switch_count       integer    NOT NULL DEFAULT 0 CHECK (switch_count >= 0),
              last_activity_at   timestamptz,

              deleted_at        timestamptz,
              created_at        timestamptz NOT NULL DEFAULT now(),
              updated_at        timestamptz NOT NULL DEFAULT now(),

              CONSTRAINT uq_tasks_owner_key UNIQUE (owner_id, task_key),
              CONSTRAINT chk_tasks_not_own_parent CHECK (parent_task_id IS NULL OR parent_task_id <> id),
              CONSTRAINT chk_tasks_reason_required
                CHECK (state NOT IN ('waiting', 'cancelled') OR length(btrim(coalesce(state_reason, ''))) > 0),
              CONSTRAINT chk_tasks_unit_needs_total CHECK (unit_done IS NULL OR unit_total IS NOT NULL),
              CONSTRAINT chk_tasks_unit_le_total   CHECK (unit_done IS NULL OR unit_done <= unit_total)
            );
            CREATE INDEX idx_tasks_owner_state ON tasks (owner_id, state) WHERE deleted_at IS NULL;
            CREATE INDEX idx_tasks_key_result  ON tasks (key_result_id)   WHERE deleted_at IS NULL;
            CREATE INDEX idx_tasks_parent      ON tasks (parent_task_id);
            CREATE INDEX idx_tasks_deadline    ON tasks (owner_id, deadline_at)
              WHERE deleted_at IS NULL AND state IN ('new','ready','running','waiting');

            CREATE TRIGGER trg_tasks_updated BEFORE UPDATE ON tasks
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            -- task_key: TCB-1, TCB-2, ... per user
            CREATE OR REPLACE FUNCTION tasks_assign_key() RETURNS trigger AS $$
            DECLARE
              seq integer;
            BEGIN
              UPDATE users SET next_task_seq = next_task_seq + 1
               WHERE id = NEW.owner_id
               RETURNING next_task_seq - 1 INTO seq;
              NEW.task_key := 'TCB-' || seq;
              IF NEW.state = 'ready' THEN
                NEW.ready_since := now();
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_assign_key BEFORE INSERT ON tasks
              FOR EACH ROW EXECUTE FUNCTION tasks_assign_key();

            -- Only valid transitions; maintain state_changed_at and ready_since
            CREATE OR REPLACE FUNCTION tasks_check_transition() RETURNS trigger AS $$
            BEGIN
              IF NEW.state IS DISTINCT FROM OLD.state THEN
                IF NOT EXISTS (SELECT 1 FROM allowed_transitions
                                WHERE from_state = OLD.state AND to_state = NEW.state) THEN
                  RAISE EXCEPTION 'Transisi tidak sah: % -> %', OLD.state, NEW.state
                    USING ERRCODE = 'check_violation', HINT = 'invalid_transition';
                END IF;
                NEW.state_changed_at := now();
                NEW.ready_since := CASE WHEN NEW.state = 'ready' THEN now() ELSE NULL END;
                -- the reason does not carry over to a state that does not need one
                IF NEW.state NOT IN ('waiting', 'cancelled', 'suspended')
                   AND NEW.state_reason IS NOT DISTINCT FROM OLD.state_reason THEN
                  NEW.state_reason := NULL;
                END IF;
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_check_transition BEFORE UPDATE OF state ON tasks
              FOR EACH ROW EXECUTE FUNCTION tasks_check_transition();

            -- WIP limit per user; the user row is locked so concurrent requests cannot both pass.
            CREATE OR REPLACE FUNCTION tasks_enforce_wip() RETURNS trigger AS $$
            DECLARE
              lim     smallint;
              running integer;
            BEGIN
              IF NEW.state = 'running'
                 AND (TG_OP = 'INSERT' OR OLD.state IS DISTINCT FROM 'running') THEN
                SELECT wip_limit INTO lim FROM users WHERE id = NEW.owner_id FOR UPDATE;
                SELECT count(*) INTO running FROM tasks
                 WHERE owner_id = NEW.owner_id AND state = 'running'
                   AND deleted_at IS NULL AND id <> NEW.id;
                IF running >= lim THEN
                  RAISE EXCEPTION 'WIP limit penuh (% dari %)', running, lim
                    USING ERRCODE = 'check_violation', HINT = 'wip_full';
                END IF;
              END IF;
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_enforce_wip BEFORE INSERT OR UPDATE OF state ON tasks
              FOR EACH ROW EXECUTE FUNCTION tasks_enforce_wip();

            -- context_snapshots: the "registers" saved when a task is left
            CREATE TABLE context_snapshots (
              id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id    bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id     bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              last_note   text,
              next_step   text,
              blocker     text,
              unit_done   numeric,
              links       jsonb       NOT NULL DEFAULT '[]',   -- [{"title": "...", "url": "..."}]
              created_at  timestamptz NOT NULL DEFAULT now(),
              CHECK (jsonb_typeof(links) = 'array')
            );
            CREATE INDEX idx_context_task_time ON context_snapshots (task_id, created_at DESC);

            -- state_transitions: append-only log
            CREATE TABLE state_transitions (
              id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id     bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id      bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              from_state   text,                               -- NULL when the task is born
              to_state     text        NOT NULL,
              reason       text,
              source       text        NOT NULL DEFAULT 'user'
                             CHECK (source IN ('user', 'calendar', 'system')),
              occurred_at  timestamptz NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_transitions_task_time ON state_transitions (task_id, occurred_at DESC);
            CREATE INDEX idx_transitions_owner_time ON state_transitions (owner_id, occurred_at DESC);

            CREATE OR REPLACE FUNCTION state_transitions_no_change() RETURNS trigger AS $$
            BEGIN
              RAISE EXCEPTION 'Log transisi bersifat append-only'
                USING ERRCODE = 'insufficient_privilege';
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_state_transitions_no_change BEFORE UPDATE OR DELETE ON state_transitions
              FOR EACH ROW EXECUTE FUNCTION state_transitions_no_change();

            -- Every state change is logged; source from the 'tcb.source' setting (default 'user').
            CREATE OR REPLACE FUNCTION tasks_log_transition() RETURNS trigger AS $$
            BEGIN
              IF TG_OP = 'INSERT' OR NEW.state IS DISTINCT FROM OLD.state THEN
                INSERT INTO state_transitions (owner_id, task_id, from_state, to_state, reason, source)
                VALUES (NEW.owner_id, NEW.id,
                        CASE WHEN TG_OP = 'INSERT' THEN NULL ELSE OLD.state END,
                        NEW.state, NEW.state_reason,
                        coalesce(nullif(current_setting('tcb.source', true), ''), 'user'));
              END IF;
              RETURN NULL;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER trg_tasks_log_transition AFTER INSERT OR UPDATE OF state ON tasks
              FOR EACH ROW EXECUTE FUNCTION tasks_log_transition();
            SQL);

        // Reference data: without these rows no task could ever change state, so
        // they are written on every migrate (also `migrate --force` on app start).
        (new AllowedTransitionsSeeder)->run();
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS state_transitions;
            DROP TABLE IF EXISTS context_snapshots;
            DROP TABLE IF EXISTS tasks;
            DROP TABLE IF EXISTS allowed_transitions;
            DROP FUNCTION IF EXISTS tasks_log_transition();
            DROP FUNCTION IF EXISTS state_transitions_no_change();
            DROP FUNCTION IF EXISTS tasks_enforce_wip();
            DROP FUNCTION IF EXISTS tasks_check_transition();
            DROP FUNCTION IF EXISTS tasks_assign_key();
            SQL);
    }
};
