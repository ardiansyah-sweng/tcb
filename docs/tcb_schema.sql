-- =====================================================================
-- TCB (Task Control Block) v1 — Skema database PostgreSQL
-- Sumber: SRS TCB v1 (bagian 3, 4, 5, 7, 8, 12)
-- Revisi 2 (12 Nov 2026): pembagian logika database vs aplikasi (SRS bagian 12).
--
-- Prinsip: invariant yang harus selalu benar ada di database; alur kerja dan
-- efek samping bisnis ada di aplikasi (service Laravel).
--
--   Di database (pagar yang tidak bisa dilewati):
--       - constraint deklaratif (alasan wajib, progres <= total, dll.)
--       - transisi status yang sah            (allowed_transitions + trg_tasks_check_transition)
--       - WIP limit per pengguna              (trg_tasks_enforce_wip)
--       - log transisi otomatis & append-only (trg_tasks_log_transition, trg_state_transitions_no_change)
--       - task_key otomatis TCB-n, updated_at (trg_tasks_assign_key, set_updated_at)
--       - view progres KR dan Objective
--   Di aplikasi (lihat SRS bagian 12):
--       - TransitionService : sesi kerja, waktu terpakai, jumlah pindah konteks
--       - DependencyService : Waiting -> Ready saat dependensi selesai
--       - CalendarSyncJob   : deadline mengikuti event acuan
--
--   * allowed_transitions adalah SATU-SATUNYA sumber aturan transisi; kelas
--     TaskStateMachine di aplikasi membaca tabel ini (tidak menyalin daftar).
--   * Sumber transisi untuk log diisi aplikasi per transaksi:
--       SET LOCAL tcb.source = 'calendar';   -- 'user' (default) | 'calendar' | 'system'
--   * Semua tabel memakai owner_id agar v2 multi-user tidak perlu migrasi besar.
--   * Nilai enum disimpan sebagai text + CHECK (mudah diubah lewat migrasi Laravel).
-- =====================================================================

BEGIN;

-- ---------------------------------------------------------------------
-- Fungsi umum: updated_at otomatis
-- ---------------------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
BEGIN
  NEW.updated_at := now();
  RETURN NEW;
END;
$$ LANGUAGE plpgsql;

-- ---------------------------------------------------------------------
-- users: pengguna dan pengaturannya
-- ---------------------------------------------------------------------
CREATE TABLE users (
  id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  name            text        NOT NULL,
  email           text        NOT NULL UNIQUE,
  password        text,
  timezone        text        NOT NULL DEFAULT 'Asia/Jakarta',
  wip_limit       smallint    NOT NULL DEFAULT 1 CHECK (wip_limit BETWEEN 1 AND 3),
  score_weights   jsonb       NOT NULL DEFAULT
                    '{"goal":0.30,"urgency":0.25,"priority":0.20,"aging":0.15,"fit":0.10}',
  work_hours      jsonb       NOT NULL DEFAULT
                    '{"mon":["08:00","17:00"],"tue":["08:00","17:00"],"wed":["08:00","17:00"],"thu":["08:00","17:00"],"fri":["08:00","17:00"]}',
  default_deadline_offset_minutes integer NOT NULL DEFAULT 1440
                    CHECK (default_deadline_offset_minutes >= 0),
  next_task_seq   integer     NOT NULL DEFAULT 1,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now()
);
CREATE TRIGGER trg_users_updated BEFORE UPDATE ON users
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- ---------------------------------------------------------------------
-- objectives & key_results: OKR ringan
-- ---------------------------------------------------------------------
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
  -- otomatis (default): dihitung dari task; manual: pengecualian, memakai nilai di bawah
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

-- ---------------------------------------------------------------------
-- allowed_transitions: tabel transisi sah (SRS bagian 4)
-- ---------------------------------------------------------------------
CREATE TABLE allowed_transitions (
  from_state  text NOT NULL,
  to_state    text NOT NULL,
  PRIMARY KEY (from_state, to_state)
);
INSERT INTO allowed_transitions (from_state, to_state) VALUES
  ('new',       'ready'),
  ('ready',     'running'),
  ('running',   'ready'),
  ('running',   'waiting'),
  ('running',   'done'),
  ('waiting',   'ready'),
  ('ready',     'suspended'),
  ('waiting',   'suspended'),
  ('suspended', 'ready'),
  ('done',      'ready'),      -- reopen
  ('cancelled', 'ready'),      -- reopen
  ('new',       'cancelled'),
  ('ready',     'cancelled'),
  ('running',   'cancelled'),
  ('waiting',   'cancelled'),
  ('suspended', 'cancelled');

-- ---------------------------------------------------------------------
-- tasks: Task Control Block
-- ---------------------------------------------------------------------
CREATE TABLE tasks (
  id                bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  owner_id          bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  task_key          text        NOT NULL,                     -- diisi trigger: TCB-n
  title             text        NOT NULL CHECK (length(btrim(title)) > 0),
  description       text,
  key_result_id     bigint      REFERENCES key_results(id) ON DELETE SET NULL,  -- NULL = orphan
  parent_task_id    bigint      REFERENCES tasks(id) ON DELETE SET NULL,
  label             text,                                     -- jenis pekerjaan / proyek (bebas)

  -- status
  state             text        NOT NULL DEFAULT 'new'
                      CHECK (state IN ('new','ready','running','waiting','suspended','done','cancelled')),
  state_reason      text,
  state_changed_at  timestamptz NOT NULL DEFAULT now(),
  ready_since       timestamptz,                              -- dasar komponen aging

  -- penjadwalan (semua opsional, kecuali prioritas yang berdefault)
  priority          smallint    NOT NULL DEFAULT 2 CHECK (priority BETWEEN 1 AND 4),
  deadline_at       timestamptz,
  deadline_source   text        NOT NULL DEFAULT 'manual'
                      CHECK (deadline_source IN ('manual', 'calendar_event')),
  estimate_minutes  integer     CHECK (estimate_minutes > 0),
  energy            text        NOT NULL DEFAULT 'focus'
                      CHECK (energy IN ('focus', 'light')),

  -- konteks ringkas (salinan snapshot terakhir untuk tampilan cepat)
  next_step         text,

  -- progres satuan (FR-23, opsional): mis. 22 dari 40 lembar
  unit_done         numeric     CHECK (unit_done >= 0),
  unit_total        numeric     CHECK (unit_total > 0),
  unit_name         text,

  -- akuntansi
  time_spent_minutes integer    NOT NULL DEFAULT 0 CHECK (time_spent_minutes >= 0),
  switch_count       integer    NOT NULL DEFAULT 0 CHECK (switch_count >= 0),
  last_activity_at   timestamptz,

  deleted_at        timestamptz,                              -- soft delete
  created_at        timestamptz NOT NULL DEFAULT now(),
  updated_at        timestamptz NOT NULL DEFAULT now(),

  CONSTRAINT uq_tasks_owner_key UNIQUE (owner_id, task_key),
  CONSTRAINT chk_tasks_not_own_parent CHECK (parent_task_id IS NULL OR parent_task_id <> id),
  -- alasan wajib untuk Waiting dan Cancelled (SRS bagian 4)
  CONSTRAINT chk_tasks_reason_required
    CHECK (state NOT IN ('waiting', 'cancelled') OR length(btrim(coalesce(state_reason, ''))) > 0),
  -- progres satuan: 'selesai' hanya bermakna jika 'total' ada, dan tidak melebihi total
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

-- task_key otomatis: TCB-1, TCB-2, ... per pengguna
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

-- Transisi status hanya yang sah; perbarui waktu status dan ready_since
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
    -- alasan tidak terbawa ke status yang tidak memerlukannya
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

-- WIP limit: jumlah Running per pengguna tidak boleh melebihi users.wip_limit.
-- Baris pengguna dikunci (FOR UPDATE) agar dua permintaan bersamaan tidak lolos.
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

-- ---------------------------------------------------------------------
-- context_snapshots: "register" saat task ditinggalkan
-- ---------------------------------------------------------------------
CREATE TABLE context_snapshots (
  id          bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  owner_id    bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  task_id     bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  last_note   text,                                -- "sampai mana?"
  next_step   text,
  blocker     text,
  unit_done   numeric,                             -- progres satuan saat disimpan (opsional)
  links       jsonb       NOT NULL DEFAULT '[]',   -- [{"title": "...", "url": "..."}]
  created_at  timestamptz NOT NULL DEFAULT now(),
  CHECK (jsonb_typeof(links) = 'array')
);
CREATE INDEX idx_context_task_time ON context_snapshots (task_id, created_at DESC);

-- ---------------------------------------------------------------------
-- state_transitions: log append-only
-- ---------------------------------------------------------------------
CREATE TABLE state_transitions (
  id           bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  owner_id     bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  task_id      bigint      NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  from_state   text,                               -- NULL untuk kelahiran task
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

-- Setiap perubahan status otomatis tercatat di log (sumber dari setting sesi
-- 'tcb.source', default 'user'; alasan dari kolom state_reason).
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

-- ---------------------------------------------------------------------
-- work_sessions: dasar waktu terpakai (dibuka saat Running, ditutup saat keluar)
-- ---------------------------------------------------------------------
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
-- paling banyak satu sesi terbuka per task
CREATE UNIQUE INDEX uq_work_sessions_open ON work_sessions (task_id) WHERE ended_at IS NULL;

-- Sesi dibuka/ditutup, waktu terpakai, dan jumlah pindah konteks dicatat oleh
-- TransitionService di aplikasi, dalam transaksi yang sama dengan transisi (SRS bagian 12).

-- ---------------------------------------------------------------------
-- dependencies: task lain atau hambatan eksternal
-- ---------------------------------------------------------------------
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
  -- tepat salah satu: task lain ATAU deskripsi eksternal
  CHECK ((depends_on_task_id IS NULL) <> (external_description IS NULL)),
  CHECK (depends_on_task_id IS NULL OR depends_on_task_id <> task_id),
  UNIQUE (task_id, depends_on_task_id)
);
CREATE INDEX idx_dependencies_on ON dependencies (depends_on_task_id) WHERE status = 'open';

-- Pelepasan dependensi (Waiting -> Ready saat dependensi selesai) dijalankan
-- oleh DependencyService di aplikasi dengan tcb.source = 'system' (SRS bagian 12).

-- ---------------------------------------------------------------------
-- calendar_links: blok fokus, event deadline, dan event acuan deadline (FR-24)
-- ---------------------------------------------------------------------
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
  offset_minutes  integer     CHECK (offset_minutes >= 0),   -- hanya untuk deadline_anchor
  event_deleted   boolean     NOT NULL DEFAULT false,
  created_at      timestamptz NOT NULL DEFAULT now(),
  updated_at      timestamptz NOT NULL DEFAULT now(),
  UNIQUE (provider, event_id, task_id, kind),
  CHECK (kind <> 'deadline_anchor' OR offset_minutes IS NOT NULL)
);
-- paling banyak satu event acuan deadline per task
CREATE UNIQUE INDEX uq_calendar_anchor ON calendar_links (task_id) WHERE kind = 'deadline_anchor';
CREATE INDEX idx_calendar_event ON calendar_links (provider, event_id);
CREATE TRIGGER trg_calendar_links_updated BEFORE UPDATE ON calendar_links
  FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- Deadline yang mengikuti event acuan (FR-24) dihitung oleh CalendarSyncJob
-- di aplikasi: deadline_at = starts_at - offset_minutes (SRS bagian 12).

-- ---------------------------------------------------------------------
-- proposals: usulan transisi dari kalender, menunggu konfirmasi pengguna
-- ---------------------------------------------------------------------
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

-- ---------------------------------------------------------------------
-- recommendations: riwayat rekomendasi dan keputusan pengguna
-- ---------------------------------------------------------------------
CREATE TABLE recommendations (
  id            bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  owner_id      bigint        NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  task_id       bigint        NOT NULL REFERENCES tasks(id) ON DELETE CASCADE,
  batch_id      uuid          NOT NULL,                 -- satu tampilan = satu batch (3 task)
  rank          smallint      NOT NULL CHECK (rank BETWEEN 1 AND 10),
  score         numeric(5,4)  NOT NULL CHECK (score >= 0 AND score <= 1),
  breakdown     jsonb         NOT NULL,                 -- {"goal":..,"urgency":..,"priority":..,"aging":..,"fit":..}
  reasons       text[]        NOT NULL DEFAULT '{}',
  decision      text          CHECK (decision IN ('picked', 'skipped', 'scheduled')),
  generated_at  timestamptz   NOT NULL DEFAULT now(),
  decided_at    timestamptz,
  UNIQUE (batch_id, rank)
);
CREATE INDEX idx_recommendations_owner_time ON recommendations (owner_id, generated_at DESC);

-- ---------------------------------------------------------------------
-- integrations: token OAuth (dienkripsi di aplikasi sebelum disimpan)
-- ---------------------------------------------------------------------
CREATE TABLE integrations (
  id                 bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  owner_id           bigint      NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  provider           text        NOT NULL CHECK (provider IN ('google_calendar')),
  access_token_enc   text        NOT NULL,
  refresh_token_enc  text,
  scopes             text[]      NOT NULL DEFAULT '{}',
  primary_calendar_id text,                               -- dibaca free/busy
  tcb_calendar_id    text,                                -- kalender khusus TCB (ditulis)
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

-- ---------------------------------------------------------------------
-- View: progres Key Result (otomatis dari task; progres satuan dihitung sebagian)
-- progres = Σ(estimasi × bagian selesai) / Σ estimasi, untuk task tidak Cancelled.
-- Task tanpa estimasi dihitung berbobot 60 menit agar tetap ikut terhitung.
-- ---------------------------------------------------------------------
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

COMMIT;
