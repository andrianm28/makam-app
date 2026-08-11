<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Binds a cleanup marker to the document kind, opaque storage key, and
 * checksum observed during promotion. Existing markers are backfilled from
 * their referenced document before new cleanup attempts use the fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_storage_cleanups', function (Blueprint $table): void {
            $table->string('document_kind')->nullable();
            $table->string('storage_key')->nullable();
            $table->string('checksum_sha256', 64)->nullable();
        });

        DB::table('document_storage_cleanups')
            ->get()
            ->each(function (object $cleanup): void {
                $document = DB::table('documents')->where('id', $cleanup->document_id)->first();

                if ($document === null) {
                    return;
                }

                DB::table('document_storage_cleanups')
                    ->where('id', $cleanup->id)
                    ->update([
                        'document_kind' => $document->document_kind,
                        'storage_key' => $document->storage_key,
                        'checksum_sha256' => $document->checksum_sha256,
                    ]);
            });
    }

    public function down(): void
    {
        // Forward-only production rollback preserves cleanup evidence.
    }
};
