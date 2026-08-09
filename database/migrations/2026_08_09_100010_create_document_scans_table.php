<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_scans` — append-only scan-attempt evidence for Platform Document
 * Vault documents. Every attempt records its scanner identity, engine
 * version, verdict, evidence, and ordinal attempt number; a document may
 * therefore retain the history needed to diagnose scanner failures.
 *
 * `document_id` uses RESTRICT on delete so scan evidence cannot become an
 * orphan. `verdict` is a closed list backed by the `ScanVerdict` enum and a
 * PostgreSQL CHECK constraint. The constraint is driver-guarded because
 * SQLite cannot add a constraint with `ALTER TABLE` and remains the local
 * PHPUnit driver.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_scans', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->string('scanner_name');
            $table->string('scanner_engine_version');
            $table->string('verdict');
            $table->jsonb('evidence');
            $table->unsignedInteger('attempt');
            $table->timestamp('scanned_at');

            $table->index('document_id', 'document_scans_document_id_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE document_scans ADD CONSTRAINT document_scans_verdict_check '.
                "CHECK (verdict IN ('CLEAN', 'INFECTED', 'SUSPICIOUS', 'ERROR'))"
            );
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_scans');
    }
};
