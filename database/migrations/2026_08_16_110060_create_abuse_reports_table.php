<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `abuse_reports` — one row per reporter per case. `reason` is REQUIRED
 * (`ReportMemorialContent` refuses a blank reason, and the model guard
 * backstops it): a report without a stated reason is not a report a
 * moderator can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('abuse_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('moderation_case_id')
                ->constrained('moderation_cases')
                ->restrictOnDelete();

            // Identity reference of the reporting visitor.
            $table->string('reporter_ref');
            $table->text('reason');

            $table->timestamps();

            $table->index('moderation_case_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('abuse_reports');
    }
};
