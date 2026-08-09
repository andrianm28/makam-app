<?php

declare(strict_types=1);

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `grave_records` — `.kiro/specs/renewal-and-grave-registry/design.md`'s
 * Data section names this table and its columns. Backs requirements.md AC3
 * (fuzzy search by deceased name, block, death date), AC12 (the record's
 * own fields) and AC14 (open/limited/closed access modes).
 *
 * Sprint 4 task S4-T7 — the public renewal skeleton (AC1-AC5, AC14). The
 * seven sibling tables `design.md` also lists — `grave_import_batches`,
 * `grave_import_rows`, `grave_import_errors`, `renewals`,
 * `renewal_quotes`, `renewal_external_markings`, `reminder_deliveries` —
 * are deliberately NOT created here. `docs/planning/sprint-plan.md` §9 puts
 * the 10k import, tariff, payment, duplicate-period guard and reminder
 * scheduler in Sprint 13, and creating their tables now would ship an empty
 * schema that the batch actually implementing them would have to reconcile
 * with rather than design.
 *
 * Migration timestamp slot: `2026_08_08_100000`-`2026_08_08_100099`,
 * assigned to this task by its brief before the batch fanned out.
 * `makam-migration` records why that matters: three pairs of colliding
 * timestamps already exist in this directory from a batch that skipped the
 * step. Recording the slot in the migration itself follows
 * `2026_07_26_140000_create_outbox_events_table.php`'s precedent.
 *
 * ---------------------------------------------------------------------------
 * Column shape and the judgement calls behind it
 * ---------------------------------------------------------------------------
 * - `id` is a UUID. See `App\Domain\GraveRegistry\Models\GraveRecord`'s own
 *   doc block for the two reasons, the first of which is privacy: a
 *   sequential key on a registry of deceased persons discloses the
 *   registry's size and makes neighbouring rows enumerable, which
 *   undercuts AC14 directly.
 *
 * - `cemetery_id` is `restrictOnDelete`, NOT `cascadeOnDelete` — the one
 *   deliberate departure from `cemetery_capability_profiles` /
 *   `cemetery_packages`, which both cascade from the same parent. Those
 *   hold derived state about a cemetery; this holds the registry of people
 *   buried in it. A cemetery row being deleted must not silently take a
 *   burial registry with it — that is a data-loss shape no `down()` can
 *   reverse. Whoever needs to delete a cemetery that has records must deal
 *   with the records explicitly.
 *
 * - `deceased_name_normalized` is a derived column, written only by
 *   `GraveRecord::booted()` from `App\Domain\GraveRegistry\
 *   GraveNameNormalizer`. It exists because `design.md`'s Search section
 *   proposes "`pg_trgm` ... for normalized deceased name" — a trigram
 *   index must sit on a stable, case-folded, punctuation-free form, and
 *   normalizing at query time only would mean the index could never be
 *   used.
 *
 * - `block` is required; `death_date` and `due_date` are NULLABLE even
 *   though AC12 lists all three as fields a grave record includes. This is
 *   a deliberate reading, not a relaxation. The registry data this table
 *   will hold comes from operator sources of varying completeness — that
 *   incompleteness is the literal premise of AC5's honest empty-state copy
 *   ("the registry may be incomplete"). A NOT NULL constraint would force
 *   whoever runs the import to invent a date to get a row in, which is
 *   worse than a null: a fabricated death date on a real person's record.
 *   AC12 is satisfied by the columns EXISTING and being populated wherever
 *   the source has them.
 *
 * - `heir_contact_reference` is a nullable opaque reference and NEVER a
 *   phone number or an address. `design.md` writes this field as
 *   "heir_contact_encrypted/reference" — two options — and this migration
 *   picks the reference form on purpose: `app/Platform/DocumentVault` (the
 *   Tier 2 foundation that would own encrypted-at-rest storage) is a
 *   Sprint 6 deliverable per `app/Platform/README.md`, and `AGENTS.md`
 *   §Authorization and files puts heir contact in the same protected class
 *   as KTP/KK/death documents. Building a local encryption scheme here
 *   would be exactly the "feature module redefines a platform foundation"
 *   that README forbids. So: the column exists, nothing in this batch
 *   writes it, no seeded row populates it, and
 *   `App\Domain\GraveRegistry\GraveRecordProjection` has no property for
 *   it under ANY access mode.
 *
 * - `access_mode` carries a column default of `closed`, the most
 *   restrictive of AC14's three. A row whose mode was never explicitly
 *   decided must not become publicly readable by omission. Plain
 *   `string(16)` with validation in `GraveRecord::booted()`, no Postgres
 *   `CHECK` — see `makam-migration` for why the three existing CHECKs are
 *   `app/Platform` protocol values and this is not one of them.
 *
 *   Corrected 09 Aug 2026 — this bullet used to read as though every write
 *   missing `access_mode` quietly landed on `closed`. It does not, and the
 *   difference is worth knowing before anyone relies on it:
 *
 *     - Through Eloquent, omitting `access_mode` THROWS. `booted()`'s
 *       `saving` hook runs `GraveRecordAccessMode::assertKnown()` against
 *       the unset (stringified-null) attribute before any statement is
 *       sent, so the column default is never reached. That is fail-safe and
 *       deliberate: a loud failure on a privacy field beats a silent
 *       default that hides a caller which forgot to decide. The behaviour
 *       stays as-is; only this description changes.
 *     - The column default is still load-bearing, but only for a raw
 *       `DB::table('grave_records')->insert()` that omits the column —
 *       which fires no model events and would otherwise have no safe value
 *       at all. Seed migrations write exactly that way, so this is a real
 *       path, not a theoretical one.
 *
 * - `source` / `source_updated_at` are `design.md`'s own column names and
 *   carry the record's PROVENANCE. Not to be confused with the TARIFF
 *   source AC6 requires on the fee step — that belongs to the renewal
 *   quote shape, which Sprint 13 owns.
 *
 * ---------------------------------------------------------------------------
 * `pg_trgm` and the GIN index — what happens on a host that cannot create
 * the extension
 * ---------------------------------------------------------------------------
 * `CREATE EXTENSION IF NOT EXISTS pg_trgm` runs on PostgreSQL only, guarded
 * the same way every other driver-specific statement in this directory is
 * (`phpunit.xml` defaults to SQLite locally; CI and production run
 * PostgreSQL).
 *
 * It is deliberately NOT wrapped in a try/catch. On a managed PostgreSQL
 * where the migration role lacks `CREATE EXTENSION` privilege, this
 * migration FAILS LOUDLY, and that is the intended behaviour: swallowing
 * the error would produce a host whose search silently runs an unindexed
 * sequential scan over the whole registry, which is both an AC4 failure
 * nobody would notice and a denial-of-service surface on a public form.
 * `AGENTS.md` §Database points production migrations at the direct (not
 * pooled) connection for exactly this class of maintenance statement. If a
 * provider requires a DBA to pre-create the extension, `CREATE EXTENSION IF
 * NOT EXISTS` is then a no-op and this migration proceeds.
 *
 * NOT TESTED on this host: `vendor/` is empty by policy
 * (`docs/operations/ci-cd-and-release.md` §10), so no migration in this
 * repository can be run here. Both statements below are unexecuted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grave_records', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // See this migration's doc block for why this restricts rather
            // than cascades, unlike the two other children of `cemeteries`.
            $table->foreignUuid('cemetery_id')
                ->constrained('cemeteries')
                ->restrictOnDelete();

            $table->string('deceased_name');

            // Derived — written only by GraveRecord::booted(). Never set by
            // a caller, never edited by hand.
            $table->string('deceased_name_normalized');

            $table->string('block', 64);

            $table->date('death_date')->nullable();
            $table->date('due_date')->nullable();

            $table->string('heir_contact_reference')->nullable();

            $table->string('access_mode', 16)->default(GraveRecordAccessMode::DEFAULT);

            $table->string('source', 32);
            $table->timestamp('source_updated_at')->nullable();

            $table->timestamps();

            // AC3's composite filter, in the order the renewal journey
            // narrows it: the cemetery is already fixed by AC1's step 2
            // before the search form is ever shown, then block, then death
            // date — see GraveRecord::scopeInCemetery()/::scopeInBlock()/
            // ::scopeDiedOn() and GraveRegistryPublicQuery::buildQuery().
            $table->index(
                ['cemetery_id', 'block', 'death_date'],
                'grave_records_cemetery_block_death_idx'
            );

            // Supports the due-date reminder window scan (AC15). That
            // scheduler is Sprint 13's, not this batch's — the index is
            // here because it is free at table-creation time and adding it
            // later to a populated 100k-row table is not.
            $table->index(['cemetery_id', 'due_date'], 'grave_records_cemetery_due_idx');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // design.md's Search section: "PostgreSQL pg_trgm proposed for
        // normalized deceased name."
        //
        // What this index can and cannot do for the query
        // GraveRegistryPublicQuery actually builds — corrected 09 Aug 2026,
        // because the earlier note here claimed it accelerated both halves
        // and that is false:
        //
        //   - gin_trgm_ops indexes the operators %, <%, %>, LIKE, ILIKE, ~
        //     and ~*. So the unanchored LIKE '%...%' half IS an indexable
        //     predicate in principle.
        //   - similarity(a, b) >= threshold is a bare FUNCTION CALL, not one
        //     of those operators, so that half is NOT indexable under this
        //     operator class. (The operator form `a % b` would be; the query
        //     is written with an explicit threshold instead — see that
        //     class's own note on why the threshold is pinned to pg_trgm's
        //     default rather than tuned.)
        //   - The query ORs the two halves together inside one WHERE group.
        //     A disjunction is only index-usable if EVERY branch is, so the
        //     non-indexable similarity() branch nullifies the LIKE branch's
        //     indexability too: the planner evaluates the whole predicate as
        //     a filter over the rows the other clauses (cemetery, block,
        //     death date) narrow it to.
        //   - ORDER BY similarity(...) DESC cannot use this index either.
        //     KNN ordering by distance needs a GiST index (gist_trgm_ops);
        //     GIN has no ordering support at all.
        //
        // This says NOTHING about whether AC4 (< 500 ms at 100,000 records)
        // passes — that is separately and already ledgered as NOT TESTED,
        // with no benchmark run at any scale and no load-testing harness in
        // this repository. Whether the index shape or the query shape should
        // change belongs to the batch that has a measurement. This comment
        // is corrected only so nobody reads it as evidence AC4 needs no
        // work, which is precisely what a reader would have concluded from
        // the sentence it replaces.
        //
        // Raw statement, not $table->index(): Laravel's schema builder has
        // no expression for a GIN index with an operator class.
        DB::statement(
            'CREATE INDEX grave_records_name_trgm_idx ON grave_records USING gin (deceased_name_normalized gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        // Reverses exactly what up() created, in reverse order. The index
        // is dropped explicitly ahead of the table for hosts where the two
        // statements are not implicitly ordered; DROP TABLE would remove it
        // anyway, so this is belt and braces, not a required step.
        //
        // The pg_trgm EXTENSION is deliberately NOT dropped. It is
        // database-scoped, not table-scoped: another table (or another
        // application sharing the database) may depend on it, and dropping
        // a shared extension to reverse one table's creation is exactly the
        // destructive down() AGENTS.md §Database warns against.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS grave_records_name_trgm_idx');
        }

        Schema::dropIfExists('grave_records');
    }
};
