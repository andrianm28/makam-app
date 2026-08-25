<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add evidence-preserving versions to reconciliation exceptions and make all
 * stored minor-unit amounts explicitly non-negative on PostgreSQL.
 *
 * An open finding may be updated when a later statement changes its evidence.
 * Once a finding has a decision, the next changed observation becomes a new
 * version linked through `supersedes_id`; the decided row remains immutable
 * evidence of the earlier decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reconciliation_exceptions', function (Blueprint $table): void {
            $table->string('statement_reference')->nullable()->after('subject_ref');
            $table->unsignedInteger('version')->default(1)->after('statement_reference');
            $table->foreignUuid('supersedes_id')
                ->nullable()
                ->after('version')
                ->constrained('reconciliation_exceptions');
        });

        Schema::table('reconciliation_exceptions', function (Blueprint $table): void {
            $table->unique(
                ['entity_ref', 'period', 'type', 'subject_ref', 'version'],
                'reconciliation_exceptions_natural_version_unique',
            );
        });

        Schema::table('reconciliation_exceptions', function (Blueprint $table): void {
            $table->dropUnique('reconciliation_exceptions_entity_ref_period_type_subject_ref_unique');
        });

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_version_positive_check '.
            'CHECK (version > 0)'
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_statement_reference_present_check '.
            'CHECK (statement_reference IS NULL OR length(btrim(statement_reference)) > 0)'
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_journal_amount_non_negative_check '.
            'CHECK (journal_amount_minor IS NULL OR journal_amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE reconciliation_exceptions ADD CONSTRAINT reconciliation_exceptions_statement_amount_non_negative_check '.
            'CHECK (statement_amount_minor IS NULL OR statement_amount_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_journal_total_non_negative_check '.
            'CHECK (journal_total_minor >= 0)'
        );
        DB::statement(
            'ALTER TABLE reconciliations ADD CONSTRAINT reconciliations_statement_total_non_negative_check '.
            'CHECK (statement_total_minor IS NULL OR statement_total_minor >= 0)'
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE reconciliation_exceptions DROP CONSTRAINT IF EXISTS reconciliation_exceptions_version_positive_check'
            );
            DB::statement(
                'ALTER TABLE reconciliation_exceptions DROP CONSTRAINT IF EXISTS reconciliation_exceptions_statement_reference_present_check'
            );
            DB::statement(
                'ALTER TABLE reconciliation_exceptions DROP CONSTRAINT IF EXISTS reconciliation_exceptions_journal_amount_non_negative_check'
            );
            DB::statement(
                'ALTER TABLE reconciliation_exceptions DROP CONSTRAINT IF EXISTS reconciliation_exceptions_statement_amount_non_negative_check'
            );
            DB::statement(
                'ALTER TABLE reconciliations DROP CONSTRAINT IF EXISTS reconciliations_journal_total_non_negative_check'
            );
            DB::statement(
                'ALTER TABLE reconciliations DROP CONSTRAINT IF EXISTS reconciliations_statement_total_non_negative_check'
            );
        }

        // DESTRUCTIVE, and not merely by dropping columns: re-adding the
        // pre-version natural-key UNIQUE index below FAILS with a duplicate-key
        // error the moment any subject has more than one evidence version —
        // which is exactly what the `version`/`supersedes_id` columns this
        // migration adds exist to produce. `AGENTS.md` §Database already
        // forbids relying on a destructive `down()` for a production rollback;
        // stating it here so nobody discovers it by running one. This path is
        // for local and CI teardown of a database that never versioned a
        // finding. Rolling back a deployment that did is a data decision (which
        // version survives), not a schema operation.
        Schema::table('reconciliation_exceptions', function (Blueprint $table): void {
            $table->dropUnique('reconciliation_exceptions_natural_version_unique');
            $table->dropForeign(['supersedes_id']);
            $table->dropColumn(['statement_reference', 'version', 'supersedes_id']);
            $table->unique(['entity_ref', 'period', 'type', 'subject_ref']);
        });
    }
};
