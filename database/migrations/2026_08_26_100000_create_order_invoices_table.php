<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `order_invoices` — closes the "no invoice/receipt concept exists"
 * half of NOTIF-02 for the ONLINE-payment path only
 * (`App\Domain\OrderWorkflow\Actions\ApplyPaidEffects`). See that
 * Action's own doc block and `docs/testing/release-gates.md`'s NOTIF-02
 * note (25 Aug 2026 UAT closing pass) for the confirmed gap this closes.
 *
 * Deliberately minimal — a single reference number, amount, currency and
 * one summary line per order, NOT a multi-line-item invoicing system. The
 * manual-payment path (`payment_verifications`, no `order_id` column) is
 * explicitly out of scope here; see `IssueInvoice`'s own doc block.
 *
 * `order_id` is UNIQUE: at most one invoice per order. `ApplyPaidEffects`
 * only ever reaches its "first paid" branch once per order — the
 * `order_status_events_paid_once` partial unique index is the structural
 * reason a duplicate paid trigger never re-enters `apply()` — so this
 * column is a second, independent backstop against a duplicate invoice
 * row, not the primary defence.
 *
 * `reference` is the customer-facing number (`'INV-'.Str::upper(Str::
 * random(10))`, `Actions\IssueInvoice`), unique with the same
 * generate-then-catch-the-collision shape
 * `Actions\AgreementCertificate\IssueCertificate` already uses for
 * `certificates.reference`.
 *
 * `restrictOnDelete()` on `order_id` — same reasoning
 * `order_documents.order_id` uses: an order with an issued invoice must
 * not be silently deletable out from under it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('order_id')
                ->unique('order_invoices_order_id_unq')
                ->constrained('orders')
                ->restrictOnDelete();

            $table->string('reference', 32)->unique('order_invoices_reference_unq');

            $table->bigInteger('amount_minor');
            $table->string('currency', 8);

            // A single summary line — see this migration's own doc block:
            // deliberately not a line-item table.
            $table->string('summary', 255);

            $table->timestamp('issued_at');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_invoices');
    }
};
