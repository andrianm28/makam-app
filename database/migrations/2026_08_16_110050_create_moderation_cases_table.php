<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `moderation_cases` — report intake + moderator resolution (AC6).
 * `reported_content_type`/`reported_content_id` reference the reported
 * row polymorphically (`memorial_contents` or `memorial_media` — no
 * foreign key, the same shape `documents.owner_*` uses for polymorphic
 * ownership); the case carries the profile-level anchor for moderation
 * scoping. One case can carry multiple `abuse_reports` (one per
 * reporter).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('moderation_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            $table->string('reported_content_type', 32);
            $table->uuid('reported_content_id');

            // open / resolved / dismissed — `ModerationCase::STATUS_*`.
            $table->string('status', 16)->default('open');

            $table->timestamps();

            $table->index(['status', 'memorial_profile_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moderation_cases');
    }
};
