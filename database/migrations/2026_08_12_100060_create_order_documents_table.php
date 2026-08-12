<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_documents` — Task 5 of the `platform-order-orchestration` plan.
 * The order-side registry that records WHICH vault `Document` is attached to
 * WHICH `Order`, by WHOM and WHEN. The kind/role of the document lives on
 * the vault Document itself (`documents.document_kind`) — that is the single
 * source of truth, never duplicated here; this row is the purpose-scoped
 * binding plus the attachment attribution.
 *
 * Both foreign keys are `restrictOnDelete()`: an order with attached
 * documents must not be silently deleted (its attachment record is
 * audit-worthy), and a vault document that is still referenced by an order
 * must not vanish from the registry (its quarantine/acceptance history is
 * the exact thing the vault is for).
 *
 * The unique `(order_id, document_id)` pair is the database backstop for
 * `Actions\AttachOrderDocument`'s `firstOrCreate` idempotency: a given vault
 * document can be bound to a given order exactly once, no matter how many
 * times the upload (or its client retry) is submitted.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('order_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->foreignUuid('document_id')
                ->constrained('documents')
                ->restrictOnDelete();

            $table->string('attached_by_ref');
            $table->string('attached_by_role');

            // When the document was bound to the order. Immutable once set —
            // see `Models\OrderDocument`'s cast.
            $table->timestamp('attached_at');

            $table->timestamps();

            $table->unique(['order_id', 'document_id'], 'order_documents_order_document_unq');
            $table->index('order_id', 'order_documents_order_id_index');
            $table->index('document_id', 'order_documents_document_id_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Matches every sibling migration in this lane: a create migration
        // rolls back by dropping the table it created, so `migrate:rollback`
        // leaves no orphan table behind and a subsequent `migrate` succeeds.
        // Production rollback still uses the previous application artifact
        // against this forward-compatible schema.
        Schema::dropIfExists('order_documents');
    }
};
