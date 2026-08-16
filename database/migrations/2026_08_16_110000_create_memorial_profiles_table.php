<?php

declare(strict_types=1);

use App\Domain\Memorial\MemorialPrivacyMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_profiles` — Task 3 of the
 * `2026-08-16-p4-memorial-qr-visitation` plan (Lane 3, Memorial).
 * `.kiro/specs/memorial-and-qr/design.md`'s Data section: the memorial
 * lifecycle's root aggregate.
 *
 * ---------------------------------------------------------------------------
 * AC7 boundary — `grave_record_id` is the ONLY link to GraveRegistry
 * ---------------------------------------------------------------------------
 * `grave_record_id` is a plain foreign key, never a copy of any
 * grave-record field: the memorial lifecycle (privacy_mode,
 * published_at/unpublished_at) never writes back to the grave record,
 * and no grave-record column is duplicated here. `display_name` (below)
 * is family-authored content, NOT copied from
 * `grave_records.deceased_name` — nothing in the creation action reads
 * the grave record's name.
 *
 * `restrictOnDelete()`: deleting a grave record that has a memorial
 * profile must be refused rather than silently erasing the memorial
 * lifecycle — same evidence-preservation choice as
 * `plot_reservations.plot_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // AC7: the ONLY GraveRegistry link. Unique — one profile per
            // grave (the action's idempotency contract relies on this as
            // the database backstop).
            $table->foreignUuid('grave_record_id')
                ->constrained('grave_records')
                ->restrictOnDelete();
            $table->unique('grave_record_id');

            // Family-authored display name for the public projection's
            // allowlist. Never auto-copied from the grave record (AC7);
            // nullable because a fresh private profile has no public
            // identity yet.
            $table->string('display_name')->nullable();

            // AC1: default `private`. Plain string column + app-level
            // `MemorialPrivacyMode::assertKnown()` in the model's saving
            // guard — the codebase's established convention for a domain
            // closed list (see `GraveRecordAccessMode`'s own doc block),
            // not a Postgres enum type.
            $table->string('privacy_mode', 32)->default(MemorialPrivacyMode::DEFAULT);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorial_profiles');
    }
};
