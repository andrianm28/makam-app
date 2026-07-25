<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `outbox_events` — Sprint 3 Batch 3.4 (S3-T11), `platform-outbox` AC1, AC5,
 * AC8. Backs `App\Platform\Outbox\Outbox::record()` (the write path) and
 * `App\Platform\Outbox\OutboxPublisher` (the `SKIP LOCKED` claim/publish/
 * retry loop).
 *
 * ---------------------------------------------------------------------------
 * Column-name reconciliation — read this before touching this table
 * ---------------------------------------------------------------------------
 * Two documents in this spec's own authority chain disagree on column
 * vocabulary for the SAME table:
 *
 *   - `.kiro/specs/platform-outbox/design.md` (Kiro-generated, NOT listed on
 *     requirements.md's own "Authority" line): `event_type`, `correlation_id`,
 *     `claimed_at`, `claimed_by`, `published_at`, `attempts`.
 *   - `docs/architecture/queue-and-outbox.md` §5 "Minimum outbox schema" +
 *     `docs/contracts/outbox-event-contract.md` (both ARE named on
 *     requirements.md's "Authority" line, and `event-catalog.md`'s own
 *     header independently confirms `trace_id`, not `correlation_id`):
 *     `event_name`, `trace_id`, `attempt_count`, `locked_at`, `dispatched_at`.
 *
 * This migration follows the SECOND set — `event_name`, `trace_id`,
 * `attempt_count`, `locked_at`, `dispatched_at`, `available_at` — because
 * requirements.md's authority line does not name `design.md` at all, and two
 * independent cited documents agree with each other against it. `design.md`'s
 * paraphrase is superseded on column NAMES only; its operational behaviour
 * description ("a stale claim is reclaimed after a timeout") is NOT one of
 * the things in conflict and is followed as-is by `OutboxPublisher`.
 *
 * This reconciliation is recorded as finding **N-11** in
 * `docs/planning/sprint-plan.md`, alongside a correction addendum to finding
 * **N-10** (Batch 3.3), which told future readers to expect a column
 * literally named `correlation_id` — written from `design.md` before this
 * reconciliation happened. The actual column below is `trace_id`.
 *
 * ---------------------------------------------------------------------------
 * Two judgement calls this migration makes, both documented rather than
 * silently resolved
 * ---------------------------------------------------------------------------
 * 1. No `actor_type`/`actor_id` columns. `outbox-event-contract.md`'s
 *    envelope has a top-level `actor` object, but `queue-and-outbox.md` §5's
 *    "minimum outbox schema" list — the cited authority for this table's
 *    shape — does not list actor columns at all. Resolution: the published
 *    envelope's `actor` key is emitted with null type/id (shape-conformant,
 *    value-empty) rather than fabricating columns the cited schema doesn't
 *    have. See `App\Platform\Outbox\Jobs\PublishOutboxEventJob` for where
 *    the envelope is assembled.
 * 2. `available_at` IS included, even though the brief flagged it as
 *    optional. `queue-and-outbox.md` §5 lists it explicitly, and
 *    `OutboxPublisher`'s bounded-backoff retry (AC6) needs a real column to
 *    schedule "not eligible for reclaim until this time" — added rather than
 *    inventing a different backoff mechanism.
 *
 * ---------------------------------------------------------------------------
 * `classification` CHECK constraint — same pgsql-only guard pattern as
 * `audit_events.outcome` (see that migration's own doc block for the full
 * reasoning: this project has only one Postgres role per environment today,
 * so the REAL enforcement gap that finding N-1 tracks is unrelated to this —
 * a CHECK constraint needs no elevated role, so it is added unconditionally
 * for Postgres). Guarded to `pgsql` only because SQLite's `ALTER TABLE` has
 * no `ADD CONSTRAINT`, and this repo's default test environment
 * (`phpunit.xml`) is `DB_CONNECTION=sqlite` unless CI's own override
 * (`DB_CONNECTION=pgsql`, `.github/workflows/ci.yml`) is in effect.
 *
 * ---------------------------------------------------------------------------
 * Migration timestamp slot
 * ---------------------------------------------------------------------------
 * Assigned slot: `2026_07_26_140000`–`2026_07_26_149999` (the next free
 * range after Batch 3.2's `…_130000` scope-assignments migration). This is
 * the only migration this batch adds to `database/migrations/` — the
 * fixture aggregate table used to prove AC1's same-transaction guarantee
 * lives under `tests/Fixtures/` instead, created ad hoc inside the test
 * process, per the `ScopedTestModel`/`CreatesScopedTestModelTable`
 * precedent, so it never ships to a real environment.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            // UUIDv7 (time-ordered), generated application-side by
            // OutboxEvent::booted()'s creating hook — not a database
            // default, because neither SQLite (this repo's default local
            // test driver) nor a bare Postgres install without pgcrypto/
            // uuid-ossp can generate one at the database layer, and this
            // project has no precedent yet for a DB-generated UUID default.
            $table->uuid('id')->primary();

            // AC2/AC3: the versioned envelope's event_name + event_version.
            // Deliberately a plain string, not a PHP enum of catalogue
            // values — AC3: "SHALL NOT restate the catalogue." Values come
            // from event-catalog.md; this column does not validate against
            // it (nothing in this codebase owns that list well enough to
            // enforce it centrally yet).
            $table->string('event_name');
            $table->unsignedSmallInteger('event_version');

            // AC2: aggregate reference. Strings, not typed foreign keys —
            // same reasoning as audit_events.subject_type/subject_id: the
            // aggregate can be any domain record, with heterogeneous
            // primary-key types and no single owning table this migration
            // could reference.
            $table->string('aggregate_type');
            $table->string('aggregate_id');

            // AC7: "no restricted data ... references only." This column
            // holds the envelope's `data` field. App\Platform\Outbox\
            // PayloadClassification denylists a small set of known-
            // restricted key names at write time (Outbox::record()) — see
            // that class's own doc block for why a denylist, not an
            // allowlist, given this field's shape is domain-owned per event,
            // not owned by this module.
            $table->jsonb('payload');

            // outbox-event-contract.md: PUBLIC|INTERNAL|CONFIDENTIAL|
            // RESTRICTED. Backed by both the OutboxClassification PHP enum
            // (structural validation — an invalid string cannot even be
            // constructed into that type) and the CHECK constraint below
            // (real database-level enforcement, unconditional on Postgres).
            $table->string('classification');

            $table->timestamp('occurred_at');

            // Bounded-backoff scheduling (AC6): a claimed-but-failed row is
            // not eligible for reclaim until this time. Set to "now" at
            // insert (immediately eligible) and pushed forward on each
            // publish failure by OutboxPublisher::backoffSeconds().
            $table->timestamp('available_at');

            $table->unsignedInteger('attempt_count')->default(0);

            // Set when a publisher claims the row (SELECT ... FOR UPDATE
            // SKIP LOCKED, then UPDATE); cleared on both successful dispatch
            // and on failure (so a failed row is reclaimable, gated by
            // available_at rather than staying permanently locked). A claim
            // older than OutboxPublisher::STALE_CLAIM_SECONDS is treated as
            // a crashed publisher and becomes reclaimable regardless of
            // whether it was ever cleared — design.md's own words for this
            // behaviour ("a stale claim is reclaimed after a timeout") are
            // NOT part of the column-name conflict this migration's top
            // doc block resolves, so they are followed as-is.
            $table->timestamp('locked_at')->nullable();

            // AC5: null until a publisher successfully dispatches this row
            // to its routed queue; non-null afterward, and never cleared
            // again. This is the "was this event published yet" flag the
            // claim-scan predicate and finding N-11's index below are built
            // around.
            $table->timestamp('dispatched_at')->nullable();

            $table->text('last_error')->nullable();

            // See this migration's own doc block above for the
            // trace_id-not-correlation_id reconciliation (N-11). Sourced at
            // write time from
            // app(App\Platform\Correlation\CorrelationContext::class)
            //     ->current()?->value
            // — see Outbox::record().
            $table->string('trace_id')->nullable();

            // AC5/AC4 (at-least-once + idempotent consumers): domain-unique
            // key when the producer has one. Nullable — not every event
            // necessarily has a natural idempotency key — but UNIQUE when
            // present. Postgres (and SQLite) both allow multiple NULLs
            // under a UNIQUE constraint, so nullability and uniqueness
            // coexist without a partial-index workaround.
            $table->string('idempotency_key')->nullable()->unique();

            // AC5/AC6: the claim-scan index. design.md's own words: "Index
            // on (published_at IS NULL, occurred_at) for the publisher
            // scan" — translated to this migration's reconciled column
            // name, dispatched_at.
            $table->index(['dispatched_at', 'occurred_at']);

            // Beyond design.md's paraphrase: OutboxPublisher's actual claim
            // predicate also filters on locked_at (stale-claim reclaim) and
            // available_at (bounded-backoff scheduling), so both are added
            // to the same composite index that supports the real query
            // OutboxPublisher::CLAIM_QUERY runs, not just the simplified
            // two-column version design.md described.
            $table->index(['dispatched_at', 'locked_at', 'available_at']);

            // Supports "reconstruct a full flow from one identifier" —
            // CorrelationId's own doc block — the same reason
            // audit_events.correlation_id is indexed.
            $table->index('trace_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE outbox_events ADD CONSTRAINT outbox_events_classification_check '.
                "CHECK (classification IN ('PUBLIC', 'INTERNAL', 'CONFIDENTIAL', 'RESTRICTED'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
