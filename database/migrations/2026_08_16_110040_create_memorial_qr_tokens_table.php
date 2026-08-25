<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `memorial_qr_tokens` — opaque, revocable QR tokens (AC4).
 *
 * `token` is `Str::random(48)` at the model layer, never derived from
 * `memorial_profile_id` or any other identifier — deriving it would make
 * tokens guessable against known profiles, the enumeration risk AC4
 * exists to close. A unique index on `token` itself backstops against an
 * astronomically-unlikely random collision (two identical tokens would
 * be a catastrophic bug, so it is refused at the database rather than
 * left to chance).
 *
 * Rotation mints a NEW row and mutates the old one in place
 * (`revoked_at` + `rotated_at`) — the old physical QR code fails the
 * same way a forgery would, per design.md's Error handling section.
 *
 * ---------------------------------------------------------------------------
 * `memorial_qr_tokens_active` — one active token per profile
 * ---------------------------------------------------------------------------
 * `CREATE UNIQUE INDEX ... ON (memorial_profile_id) WHERE revoked_at IS
 * NULL` — the mutable-row partial unique (identical shape to
 * `memorial_editors_active_editor`): the entry releases on revoke, so a
 * new token can be issued after rotation. Same portable syntax note
 * applies.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memorial_qr_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('memorial_profile_id')
                ->constrained('memorial_profiles')
                ->restrictOnDelete();

            $table->string('token', 64);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('rotated_at')->nullable();

            $table->timestamps();

            $table->index('memorial_profile_id');
            $table->unique('token');
        });

        DB::statement(
            'CREATE UNIQUE INDEX memorial_qr_tokens_active '.
            'ON memorial_qr_tokens (memorial_profile_id) '.
            'WHERE revoked_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS memorial_qr_tokens_active');
        Schema::dropIfExists('memorial_qr_tokens');
    }
};
