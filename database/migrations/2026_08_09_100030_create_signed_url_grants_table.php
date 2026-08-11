<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `signed_url_grants` — one purpose-scoped grant per signed URL issuance.
 * The token is opaque, consumption is tracked without rewriting the access
 * event, and PostgreSQL enforces the five-minute maximum independently of any
 * future Action validation. `document_id` is RESTRICTed so grants cannot be
 * left pointing at a deleted document.
 *
 * The expiry CHECK is guarded to PostgreSQL because SQLite cannot add a
 * constraint with `ALTER TABLE` and remains the local PHPUnit driver. The
 * migration has no destructive rollback: grant history is durable evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signed_url_grants', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->string('purpose');
            $table->string('token')->unique('signed_url_grants_token_unique');
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at');

            $table->index(['document_id', 'expires_at'], 'signed_url_grants_document_expires_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE signed_url_grants ADD CONSTRAINT signed_url_grants_purpose_check '.
                "CHECK (purpose IN ('VIEW', 'DOWNLOAD', 'UPDATE', 'DELETE', 'GRANT'))"
            );
            DB::statement(
                'ALTER TABLE signed_url_grants ADD CONSTRAINT signed_url_grants_expires_at_check '.
                "CHECK (expires_at <= created_at + interval '5 minutes')"
            );
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: issued grants are durable access
        // evidence. Schema compatibility is handled by a later migration.
    }
};
