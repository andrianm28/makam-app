<?php

declare(strict_types=1);

use App\Domain\Memorial\MemorialModerationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_visit_checkins` — the self-service "Catat kunjungan" visit
 * affirmation (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.1). No IP address, device fingerprint, or geolocation column exists
 * here, and none is ever added — a hard constraint of the feature, not a
 * default that happens to go unused.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_visit_checkins', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            $table->timestamp('checked_in_at');
            $table->string('visitor_label', 120)->nullable();
            $table->string('note', 500)->nullable();
            $table->string('moderation_state', 16)->default(MemorialModerationState::DEFAULT);

            $table->timestamps();

            $table->index(['memorial_profile_id', 'checked_in_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorial_visit_checkins');
    }
};
