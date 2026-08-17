<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `work_evidence` — photographic/documentary evidence uploaded against a
 * work order. References a `documents` row in the vault (never stores
 * file content directly). Evidence is private to authorized parties (AC4)
 * and cannot be previewed before the vault scan acceptance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('work_order_id');
            $table->foreignUuid('document_id');
            $table->string('evidence_type', 32);
            $table->foreignUuid('uploaded_by');

            $table->timestamps();

            $table->index('work_order_id', 'work_evidence_wo_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE work_evidence ADD CONSTRAINT work_evidence_type_check '.
                "CHECK (evidence_type IN ('before', 'after'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('work_evidence');
    }
};
