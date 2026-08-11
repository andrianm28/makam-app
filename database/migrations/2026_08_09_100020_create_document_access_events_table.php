<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `document_access_events` — append-only access evidence for the private
 * Document Vault. Every restricted access records who attempted it, the
 * purpose, the outcome, the client address, and when it happened.
 *
 * `document_id` is RESTRICTed so deleting a document cannot orphan its access
 * history. The application-level immutable guard lives on
 * `DocumentAccessEvent`; the database-level REVOKE for UPDATE/DELETE is
 * deliberately owned by Task 8 once separate application and migration roles
 * exist. Closed-list checks are PostgreSQL-only because SQLite cannot add a
 * constraint with `ALTER TABLE` and remains the local PHPUnit driver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_access_events', function (Blueprint $table) {
            $table->id();

            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->string('actor_ref')->nullable();
            $table->string('actor_role');
            $table->string('purpose');
            $table->string('outcome');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('occurred_at');

            $table->index(['document_id', 'occurred_at'], 'document_access_events_document_occurred_index');
            $table->index(['actor_ref', 'occurred_at'], 'document_access_events_actor_occurred_index');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE document_access_events ADD CONSTRAINT document_access_events_purpose_check '.
                "CHECK (purpose IN ('VIEW', 'DOWNLOAD', 'UPDATE', 'DELETE', 'GRANT'))"
            );
            DB::statement(
                'ALTER TABLE document_access_events ADD CONSTRAINT document_access_events_outcome_check '.
                "CHECK (outcome IN ('allowed', 'denied', 'failed'))"
            );
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: access evidence must survive an
        // application rollback. Schema compatibility is handled forward.
    }
};
