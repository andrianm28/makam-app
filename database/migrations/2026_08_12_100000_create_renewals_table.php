<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `renewals` — `.kiro/specs/renewal-and-grave-registry/design.md`'s Data
 * section, and Sprint 4's own deferral note
 * (`2026_08_08_100000_create_grave_records_table.php`) naming this as one of
 * the seven sibling tables NOT created in that batch. L8 Task 1
 * (`docs/superpowers/plans/2026-08-12-platform-renewal-completion.md`)
 * builds it now, ahead of the write paths (`OpenRenewal`,
 * `MarkExternalRenewal`) that will populate it, because the AC11
 * duplicate-period guard is a schema decision and this task's whole point
 * is proving that guard under a mutation check before anything is built on
 * top of it.
 *
 * Migration timestamp slot: `2026_08_12_100000`-`2026_08_12_100029`,
 * assigned to this task's three migrations by its plan before the batch
 * fanned out — see `2026_08_08_100000_create_grave_records_table.php`'s own
 * doc block for why recording the slot matters (colliding timestamps have
 * happened in this directory before).
 *
 * ---------------------------------------------------------------------------
 * Column shape and the judgement calls behind it
 * ---------------------------------------------------------------------------
 * - `id` is a UUID, matching `grave_records` and every other domain-facing
 *   resource `docs/contracts/openapi.yaml` names — including `renewalId` on
 *   `/renewals/{renewalId}/payment-session`, this table's own contract
 *   entry.
 *
 * - `grave_record_id` is `restrictOnDelete`, the same choice
 *   `grave_records.cemetery_id` made and for the same reason: this table
 *   holds a real settlement record, and a grave record being deleted must
 *   not silently take that history with it. Whoever needs to delete a grave
 *   record that has renewals must deal with them explicitly.
 *
 * - `target_due_period` is a `date`, not a string, and it holds THE DUE
 *   DATE THIS RENEWAL SETTLES — the grave record's `due_date` at the moment
 *   the renewal is opened, not the date the renewal itself was created. A
 *   date is comparable, indexable, and unambiguous; a free-text "period"
 *   column would let `"2027"` and `"2027-01"` denote the same period in two
 *   different spellings and defeat the uniqueness check below outright.
 *
 * - `reference` (the "PPJ-000N" family the fixtures use) is independently
 *   unique — a human-facing lookup key, distinct from the AC11 business
 *   key below and enforced separately so a reference collision and a
 *   period collision fail for two different, individually diagnosable
 *   reasons.
 *
 * - `status` / `source` are plain `string` columns with application-layer
 *   validation (`App\Domain\Renewal\RenewalStatus::assertKnown()` /
 *   `RenewalSource::assertKnown()` in `Renewal::booted()`), not a Postgres
 *   `CHECK` — the same convention `GraveRecordAccessMode`'s doc block
 *   documents and cites; extending either list must never require a
 *   migration.
 *
 * - `settled_at` is nullable: a `MENUNGGU_PEMBAYARAN` row has not settled
 *   yet, so forcing a value here at insert time would mean fabricating a
 *   timestamp for money that has not moved.
 *
 * ---------------------------------------------------------------------------
 * AC11 — the duplicate-period guard, and why it lives HERE
 * ---------------------------------------------------------------------------
 * `design.md`: "External marking and online renewal share the same
 * uniqueness domain." Both a family completing the public journey
 * (`source = RenewalSource::ONLINE`) and an admin recording a payment taken
 * outside the platform (`source = RenewalSource::EXTERNAL`, AC10) insert a
 * row into THIS one table — `source` only records which path it came
 * through, it plays no part in the uniqueness check. That is deliberate: a
 * second table for external markings' own uniqueness would need to be kept
 * in sync with this one by application code, and application code checking
 * "does a row already exist" before inserting is exactly the race a
 * concurrent-request scenario can defeat (two requests both check, both see
 * nothing, both insert). A single database unique index across both write
 * paths closes that race unconditionally — the database itself is the
 * single source of truth for "has this grave period already been settled",
 * not a check any caller could get out of sync with.
 *
 * `renewals_grave_period_unique` is on `(grave_record_id,
 * target_due_period)`. It is deliberately NOT on `grave_record_id` alone —
 * a grave legitimately accrues a new renewal every period, so only the
 * PAIR must be unique.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('renewals', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('grave_record_id')->constrained('grave_records')->restrictOnDelete();
            $table->date('target_due_period');
            $table->string('reference')->unique();
            $table->string('status');
            $table->string('source');
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            // AC11. The business key, enforced by the database so two
            // concurrent requests cannot both succeed. Application-level
            // checking would be a race, not a guard. See this migration's
            // own doc block for why ONE index across both `source` values
            // is correct rather than a second uniqueness mechanism.
            $table->unique(['grave_record_id', 'target_due_period'], 'renewals_grave_period_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('renewals');
    }
};
