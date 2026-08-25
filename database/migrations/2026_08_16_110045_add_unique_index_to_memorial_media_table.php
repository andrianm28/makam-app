<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Unique `(memorial_profile_id, storage_ref)` on `memorial_media` — the
 * database backstop for the family page's lazy media attach
 * (`MemorialFamilyPage::attachAcceptedUploads()`).
 *
 * The attach path is exists-then-create on render; two concurrent renders
 * could both pass the exists check and both insert. The unique index makes
 * the loser fail with a `QueryException`, which the attach path classifies
 * and swallows (the incumbent row is the correct result either way).
 * `storage_ref` is the vault `documents.id` reference — one profile can
 * never attach the same accepted document twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memorial_media', function (Blueprint $table) {
            $table->unique(
                ['memorial_profile_id', 'storage_ref'],
                'memorial_media_profile_storage_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('memorial_media', function (Blueprint $table) {
            $table->dropUnique('memorial_media_profile_storage_unique');
        });
    }
};
