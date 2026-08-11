<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable operational markers for post-promotion object cleanup. The marker
 * is committed with the accepted document state and remains pending until the
 * quarantine deletion has actually succeeded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_storage_cleanups', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->restrictOnDelete();
            $table->string('operation');
            $table->unsignedInteger('attempt_count')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('completed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['document_id', 'operation'], 'document_storage_cleanups_document_operation_unique');
            $table->index(
                ['completed_at', 'available_at'],
                'document_storage_cleanups_pending_index',
            );
        });
    }

    public function down(): void
    {
        // Forward-only production rollback preserves cleanup evidence.
    }
};
