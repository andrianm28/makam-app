<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The reversal -> original link, as a real foreign key.
 *
 * Wave 1b ruling (user-approved, 10 Aug 2026): a batch is reversed if and
 * only if a batch exists whose `reverses_batch_id` points at it. Nothing is
 * ever written back onto the original batch, so `journal_batches` stays
 * literally append-only (AC2/AC14) and Task 6's blanket UPDATE/DELETE revoke
 * needs no column-level carve-out. That derivation is only trustworthy if the
 * link is a constrained, indexed foreign key — which is why it is not the
 * original's `business_key` written into `journal_entries.reference`, a
 * nullable un-indexed note column that stays exactly what Task 2 designed it
 * to be.
 *
 * Additive only: one nullable column, one index, one foreign key, one CHECK.
 * No backfill, no column drop, no data rewrite. The plan's Current-state
 * finding records that no money is stored in any deployed database today, so
 * the table holds zero production rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('journal_batches', function (Blueprint $table): void {
            $table->uuid('reverses_batch_id')->nullable();

            // UNIQUE rather than a plain index, deliberately: it is what makes
            // "a batch is reversed at most once" a database invariant instead
            // of an application convention. Both PostgreSQL and SQLite treat
            // NULLs as distinct in a unique index, so the many never-reversed
            // batches are unaffected. See `Journal::postReversal()`'s doc block
            // for why reversing twice is refused rather than allowed.
            $table->unique('reverses_batch_id');

            $table->foreign('reverses_batch_id')->references('id')->on('journal_batches');
        });

        // SQLite cannot add a table CHECK constraint with ALTER TABLE — the
        // same limitation the two Task 2 migrations document. PostgreSQL 18 is
        // the authoritative production/CI enforcement path; the focused tests
        // skip this assertion explicitly on SQLite rather than pretending to
        // cover it.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE journal_batches ADD CONSTRAINT journal_batches_no_self_reversal_check '.
                'CHECK (reverses_batch_id IS NULL OR reverses_batch_id <> id)'
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE journal_batches DROP CONSTRAINT IF EXISTS journal_batches_no_self_reversal_check'
            );
        }

        // Drop the foreign key before the column it constrains, then the
        // unique index, then the column itself.
        Schema::table('journal_batches', function (Blueprint $table): void {
            $table->dropForeign(['reverses_batch_id']);
            $table->dropUnique(['reverses_batch_id']);
            $table->dropColumn('reverses_batch_id');
        });
    }
};
