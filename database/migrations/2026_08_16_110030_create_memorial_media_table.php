<?php

declare(strict_types=1);

use App\Domain\Memorial\MemorialModerationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_media` — family-authored media, moderated like content.
 *
 * `storage_ref` is the platform document vault's `documents.id` UUID —
 * a reference to the vault row, never a file path and never the media's
 * bytes. The model's creating guard refuses any `storage_ref` that does
 * not resolve to a vault document in `DocumentState::Accepted`: a scan
 * that has not completed (or failed) is never usable — the fail-closed
 * rule from `.kiro/specs/memorial-and-qr/design.md`'s Error handling
 * section. `moderation_state` (not the design draft's `scan_state`)
 * governs renderability in the public projection, per the plan's Task 3
 * brief fillable list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_media', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            // Vault `documents.id` reference — see class doc block.
            $table->string('storage_ref');
            $table->string('moderation_state', 16)->default(MemorialModerationState::DEFAULT);

            $table->timestamps();

            $table->index(['memorial_profile_id', 'moderation_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorial_media');
    }
};
