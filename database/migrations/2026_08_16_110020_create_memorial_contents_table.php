<?php

declare(strict_types=1);

use App\Domain\Memorial\MemorialModerationState;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_contents` — family-authored text messages, moderated before
 * they ever reach the public projection (AC6). `moderation_state`
 * defaults to `pending`; only `approved` bodies render in
 * `MemorialPublicProjection`. Rows are never deleted — moderation is a
 * state transition, not a row removal, so the moderation trail survives.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_contents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            $table->text('body');
            $table->string('moderation_state', 16)->default(MemorialModerationState::DEFAULT);

            $table->timestamps();

            $table->index(['memorial_profile_id', 'moderation_state']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('memorial_contents');
    }
};
