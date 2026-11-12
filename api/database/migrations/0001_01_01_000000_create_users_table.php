<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * users follows docs/tcb_schema.sql (rev. 2) exactly; set_updated_at() is
     * defined here because users is the first table that uses it.
     * password_reset_tokens and sessions are Laravel infrastructure tables.
     */
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION set_updated_at() RETURNS trigger AS $$
            BEGIN
              NEW.updated_at := now();
              RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

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
            SQL);

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        DB::unprepared('DROP FUNCTION IF EXISTS set_updated_at();');
    }
};
