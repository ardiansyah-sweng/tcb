<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * docs/tcb_schema.sql (rev. 2): work_sessions, dependencies, calendar_links,
 * proposals, recommendations, integrations.
 *
 * Workflow on these tables (opening/closing sessions, releasing dependencies,
 * calendar sync) lives in application services, not triggers (SRS section 12).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE work_sessions (
              id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id          bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id           bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              started_at        timestamptz NOT NULL DEFAULT now(),
              ended_at          timestamptz,
              duration_minutes  integer GENERATED ALWAYS AS (
                                  CASE WHEN ended_at IS NULL THEN NULL
                                       ELSE (extract(epoch FROM (ended_at - started_at)) / 60)::integer END
                                ) STORED,
              CHECK (ended_at IS NULL OR ended_at >= started_at)
            );
            -- at most one open session per task
            CREATE UNIQUE INDEX uq_work_sessions_open ON work_sessions (task_id) WHERE ended_at IS NULL;

            CREATE TABLE dependencies (
              id                    bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id              bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id               bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              depends_on_task_id    bigint      REFERENCES tasks(id) ON DELETE CASCADE,
              external_description  text,
              status                text        NOT NULL DEFAULT 'open'
                                      CHECK (status IN ('open', 'resolved')),
              created_at            timestamptz NOT NULL DEFAULT now(),
              resolved_at           timestamptz,
              -- exactly one: another task OR an external description
              CHECK ((depends_on_task_id IS NULL) <> (external_description IS NULL)),
              CHECK (depends_on_task_id IS NULL OR depends_on_task_id <> task_id),
              UNIQUE (task_id, depends_on_task_id)
            );
            CREATE INDEX idx_dependencies_on ON dependencies (depends_on_task_id) WHERE status = 'open';

            CREATE TABLE calendar_links (
              id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id        bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id         bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              provider        text        NOT NULL DEFAULT 'google' CHECK (provider IN ('google')),
              calendar_id     text        NOT NULL,
              event_id        text        NOT NULL,
              kind            text        NOT NULL
                                CHECK (kind IN ('focus_block', 'deadline', 'deadline_anchor')),
              starts_at       timestamptz,
              ends_at         timestamptz,
              offset_minutes  integer     CHECK (offset_minutes >= 0),   -- deadline_anchor only
              event_deleted   boolean     NOT NULL DEFAULT false,
              created_at      timestamptz NOT NULL DEFAULT now(),
              updated_at      timestamptz NOT NULL DEFAULT now(),
              UNIQUE (provider, event_id, task_id, kind),
              CHECK (kind <> 'deadline_anchor' OR offset_minutes IS NOT NULL)
            );
            -- at most one deadline anchor event per task
            CREATE UNIQUE INDEX uq_calendar_anchor ON calendar_links (task_id) WHERE kind = 'deadline_anchor';
            CREATE INDEX idx_calendar_event ON calendar_links (provider, event_id);
            CREATE TRIGGER trg_calendar_links_updated BEFORE UPDATE ON calendar_links
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();

            CREATE TABLE proposals (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id      bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id       bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              source        text        NOT NULL DEFAULT 'calendar' CHECK (source IN ('calendar', 'system')),
              kind          text        NOT NULL
                              CHECK (kind IN ('start_task', 'save_context', 'deadline_moved', 'anchor_deleted', 'stale_running')),
              proposed_state text       CHECK (proposed_state IN ('ready','running','waiting','suspended','done')),
              message       text        NOT NULL,
              status        text        NOT NULL DEFAULT 'pending'
                              CHECK (status IN ('pending', 'accepted', 'dismissed', 'expired')),
              created_at    timestamptz NOT NULL DEFAULT now(),
              decided_at    timestamptz
            );
            CREATE INDEX idx_proposals_pending ON proposals (owner_id, created_at DESC) WHERE status = 'pending';

            CREATE TABLE recommendations (
              id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id      bigint        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              task_id       bigint        NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
              batch_id      uuid          NOT NULL,
              rank          smallint      NOT NULL CHECK (rank BETWEEN 1 AND 10),
              score         numeric(5,4)  NOT NULL CHECK (score >= 0 AND score <= 1),
              breakdown     jsonb         NOT NULL,
              reasons       text[]        NOT NULL DEFAULT '{}',
              decision      text          CHECK (decision IN ('picked', 'skipped', 'scheduled')),
              generated_at  timestamptz   NOT NULL DEFAULT now(),
              decided_at    timestamptz,
              UNIQUE (batch_id, rank)
            );
            CREATE INDEX idx_recommendations_owner_time ON recommendations (owner_id, generated_at DESC);

            -- OAuth tokens are encrypted by the application before storage
            CREATE TABLE integrations (
              id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
              owner_id           bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
              provider           text        NOT NULL CHECK (provider IN ('google_calendar')),
              access_token_enc   text        NOT NULL,
              refresh_token_enc  text,
              scopes             text[]      NOT NULL DEFAULT '{}',
              primary_calendar_id text,
              tcb_calendar_id    text,
              status             text        NOT NULL DEFAULT 'active'
                                   CHECK (status IN ('active', 'expired', 'revoked', 'error')),
              last_error         text,
              last_synced_at     timestamptz,
              created_at         timestamptz NOT NULL DEFAULT now(),
              updated_at         timestamptz NOT NULL DEFAULT now(),
              UNIQUE (owner_id, provider)
            );
            CREATE TRIGGER trg_integrations_updated BEFORE UPDATE ON integrations
              FOR EACH ROW EXECUTE FUNCTION set_updated_at();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS integrations;
            DROP TABLE IF EXISTS recommendations;
            DROP TABLE IF EXISTS proposals;
            DROP TABLE IF EXISTS calendar_links;
            DROP TABLE IF EXISTS dependencies;
            DROP TABLE IF EXISTS work_sessions;
            SQL);
    }
};
