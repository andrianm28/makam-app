<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `documents` — Platform Document Vault's private document reference and
 * quarantine state. The owner is a polymorphic record reference because the
 * vault serves booking drafts, grave records, orders, and funeral cases.
 *
 * Storage metadata is deliberately separate from the display filename:
 * `storage_key` is an opaque application-generated key and must never be
 * derived from client input. `client_upload_id` is nullable so resumable
 * uploads may opt in to the partial uniqueness guarantee without making it a
 * permanent document identity.
 *
 * `document_kind` and `state` are closed lists backed by both the
 * `DocumentKind`/`DocumentState` enums and PostgreSQL CHECK constraints. The
 * checks are guarded to PostgreSQL because SQLite cannot add a constraint with
 * `ALTER TABLE`, while SQLite remains the repository's local test driver.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('document_kind');
            $table->string('state');

            // A polymorphic record reference; the owning domain table varies
            // by document use case and therefore cannot be a foreign key.
            $table->string('owner_type');
            $table->string('owner_id');

            // Display metadata only. The opaque storage key is the authority
            // used by the later object-storage adapter.
            $table->string('original_filename');
            $table->string('storage_prefix');
            $table->string('storage_key');

            $table->unsignedBigInteger('size_bytes');
            $table->string('mime_declared');
            $table->string('mime_verified')->nullable();
            $table->string('checksum_sha256', 64)->nullable();

            $table->string('client_upload_id')->nullable();
            $table->boolean('scanner_required')->default(true);
            $table->timestamp('retention_until')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id'], 'documents_owner_type_owner_id_index');
            $table->index('state', 'documents_state_index');
            $table->index('storage_key', 'documents_storage_key_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE documents ADD CONSTRAINT documents_document_kind_check '.
                "CHECK (document_kind IN ('KTP', 'KK', 'DEATH_CERTIFICATE', 'PAYMENT_PROOF', 'AGREEMENT', 'CERTIFICATE', 'VENDOR_EVIDENCE', 'PRODUCT_IMAGE', 'GRAVE_IMPORT'))"
            );
            DB::statement(
                'ALTER TABLE documents ADD CONSTRAINT documents_state_check '.
                "CHECK (state IN ('UPLOADING', 'QUARANTINED', 'SCANNING', 'ACCEPTED', 'REJECTED', 'EXPIRED', 'DELETED'))"
            );
        }

        // SQLite and PostgreSQL both support partial indexes. Keeping this
        // explicit preserves the resume-token rule rather than relying on a
        // nullable UNIQUE column as an accidental equivalent.
        DB::statement(
            'CREATE UNIQUE INDEX documents_client_upload_id_index ON documents (client_upload_id) '.
            'WHERE client_upload_id IS NOT NULL'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
