<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `audit_events` — `platform-audit` design.md's `AuditAdapter` schema
 * (design.md's Data section: "id, occurred_at, actor_ref, actor_role,
 * action, source, subject_type, subject_id, subject_version, reason,
 * correlation_id, outcome, metadata").
 *
 * Scope for THIS batch (Batch 3.2 Agent A, S3-T8 + S3-T9,
 * requirements.md AC1, AC2, AC4, AC5 — see `app/Platform/Audit/Audit.php`
 * for the write API this table backs).
 *
 * ---------------------------------------------------------------------------
 * AC1 (append-only) — what this migration does and does NOT enforce
 * ---------------------------------------------------------------------------
 * AC1: "THE SYSTEM SHALL NOT update, delete, soft-delete, or
 * retroactively edit an audit record once it is written." The real
 * enforcement design.md names is a database-level REVOKE of UPDATE/
 * DELETE from the application's Postgres role: "no UPDATE/DELETE grant
 * on audit_events for the application role. Migration role only for
 * schema."
 *
 * This project cannot do that for real today. Finding N-1
 * (`docs/planning/sprint-plan.md`): there is currently only ONE
 * Postgres role per environment (`makam_dev_user` / `makam_stg_user` —
 * see `docs/operations/examples/postgres-init/01-create-databases.sh`),
 * which both OWNS the database (needed to run migrations, including
 * this one) and runs the application at request time. Revoking
 * UPDATE/DELETE from that single role would also remove its own
 * ability to run a future migration that alters this table — there is
 * no distinct, lower-privileged "application role" to revoke from yet.
 * The role split needs two newly provisioned secrets per environment,
 * which `sprint-plan.md` already classifies as a credential change
 * requiring human approval — not something this migration can do on
 * its own authority.
 *
 * So: this migration only CREATEs the table, its columns, and its
 * indexes. The exact REVOKE/GRANT statements to run once N-1's role
 * split lands live in a separate file that nothing executes
 * automatically: `app/Platform/Audit/sql/revoke-audit-mutations.sql`.
 * Until that role split happens, AC1 is enforced only at the
 * application level — see `app/Platform/Audit/Models/AuditEvent.php`,
 * which overrides `update()`/`performUpdate()`/`delete()` to always
 * throw. That model-level guard is explicitly NOT the real
 * enforcement (raw SQL, `AuditEvent::query()->update()`, or a
 * different ORM instance all bypass it) — see that model's own
 * class-level doc block for the full list of what it does and does
 * not stop.
 *
 * One piece of database-level enforcement here does NOT wait on N-1,
 * and is real today: the `outcome` CHECK constraint added below.
 * Postgres enforces a CHECK constraint for any role that can INSERT
 * into the table, including a future non-privileged application role,
 * so it is added unconditionally rather than deferred alongside the
 * REVOKE.
 *
 * ---------------------------------------------------------------------------
 * Migration timestamp slot
 * ---------------------------------------------------------------------------
 * This batch's assigned slot is `2026_07_26_110000` through
 * `2026_07_26_119999`. Note this differs from
 * `docs/planning/agent-execution-plan.md` §5's Batch 3.2 table, which
 * lists Agent A (this batch) as `…_100000`–`…_109999` — that table
 * predates Batch 3.1's `actor_sessions` migration, which already
 * claimed `2026_07_26_100000` first (Batch 3.1 runs serially before
 * Batch 3.2). This file uses the slot as given directly for this
 * batch's execution (110000-119999), not the plan document's
 * pre-Batch-3.1 numbering. Flagged explicitly rather than silently
 * picking one.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('audit_events', function (Blueprint $table) {
            $table->id();

            // When the event occurred — deliberately NOT Eloquent's
            // created_at/updated_at pair. There is no updated_at
            // column anywhere on this table: its mere existence would
            // suggest a row can be revised after it is written, which
            // is exactly what AC1 forbids. See AuditEvent::$timestamps.
            $table->timestamp('occurred_at');

            // AC2: "actor reference". Nullable: a small number of
            // legitimate audit events have no actor identity to
            // reference at all (e.g. a genuinely unattended scheduled
            // job) — see Audit::record()'s doc block for the exact
            // rule; $actor_role is still required even then (e.g.
            // 'guest', 'system'). String, not a foreign key:
            // App\Platform\IdentityAccess\ActorContext::$identityReference
            // is itself typed int|string|null (a future K1/K2-backed
            // adapter may reference identity by an external string
            // id), and this table must not assume the local `users`
            // table is the only identity source forever.
            $table->string('actor_ref')->nullable();

            // AC2: "actor role". Always required, even alongside a
            // null actor_ref.
            $table->string('actor_role');

            // AC2: "action". SensitiveActions::ACTIONS is checked
            // against this value at write time (AC3), not enforced by
            // any constraint here.
            $table->string('action');

            // AC2: "source (panel/API/job)" — see AuditSource.
            $table->string('source');

            // AC5: "reference the subject record rather than copy its
            // sensitive contents" — these three columns identify the
            // record; the record's own field values never land here.
            // Stored as strings rather than typed foreign keys because
            // the subject can be any domain record in this platform,
            // with heterogeneous primary key types (bigint today,
            // potentially uuid elsewhere) and no single owning table
            // this migration could reference.
            $table->string('subject_type');
            $table->string('subject_id');
            $table->string('subject_version')->nullable();

            // AC3: mandatory only for the declared sensitive-action
            // list (SensitiveActions::ACTIONS) — enforced by
            // Audit::record() at write time, not by a NOT NULL here,
            // because it is conditionally required, not universally
            // required.
            $table->text('reason')->nullable();

            // Schema column only. AC10 (correlation-id propagation
            // across request -> outbox -> queue -> provider ->
            // notification) is explicitly out of scope for this batch
            // — it is S3-T10, a separate later batch that depends on
            // this table existing first. The column exists now so
            // nothing that writes an audit_events row today has to
            // change shape once propagation lands.
            $table->string('correlation_id')->nullable();

            // AC2: "outcome" — allowed | denied | failed. Backed by a
            // real Postgres CHECK constraint below in addition to the
            // App\Platform\Audit\AuditOutcome PHP enum, so an invalid
            // value is rejected at the database, not only by
            // application code that might be bypassed.
            $table->string('outcome');

            // AC5: allowlisted keys only — enforced by
            // MetadataAllowlist::assertAllowed() at write time, not by
            // this column, which is intentionally schemaless JSON:
            // Postgres has no column-level mechanism to validate an
            // object's key names.
            $table->json('metadata')->nullable();

            $table->index('actor_ref');
            $table->index('action');
            $table->index(['subject_type', 'subject_id']);
            $table->index('occurred_at');
            $table->index('correlation_id');
        });

        // Real database-level enforcement that does NOT wait on
        // finding N-1's role split: outcome can only ever be one of
        // design.md's three values, for any role, including a future
        // non-privileged application role.
        //
        // Guarded to the pgsql driver only: SQLite's ALTER TABLE does
        // not support ADD CONSTRAINT at all (only ADD COLUMN and a
        // handful of others), and phpunit.xml's own default test
        // environment is DB_CONNECTION=sqlite (:memory:) — CI's actual
        // test job overrides this to pgsql
        // (.github/workflows/ci.yml), matching production and staging
        // (both Postgres per ADR-0021), but an unconditional
        // DB::statement() here would break RefreshDatabase for every
        // test in this suite for anyone running `php artisan test`
        // locally without that override. This guard keeps the CHECK
        // constraint real where it matters (Postgres, every
        // environment that actually runs this application) without
        // making the migration itself environment-fragile.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE audit_events ADD CONSTRAINT audit_events_outcome_check '.
                "CHECK (outcome IN ('allowed', 'denied', 'failed'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_events');
    }
};
