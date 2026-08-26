<?php

declare(strict_types=1);

namespace App\Livewire\Public\Invoices;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderInvoice;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/kwitansi/{reference}` — the public, unauthenticated receipt view for a
 * basic order invoice (`Actions\IssueInvoice`), the customer-facing surface
 * NOTIF-02's `payment.received.v1` "+ invoice" link can point at.
 *
 * ---------------------------------------------------------------------------
 * Keyed by the generated reference, not the order id — same shape
 * `SubscriptionStatusPage` (`/langganan/{subscriptionReference}`) already
 * uses for an unauthenticated, reference-keyed customer page
 * ---------------------------------------------------------------------------
 * `order_invoices.reference` is a 14-character generated token
 * (`'INV-'.Str::upper(Str::random(10))`, `Actions\IssueInvoice`) — not
 * sequential, not derived from the order id, and not guessable from any
 * other value a visitor could hold. An unknown reference is a plain 404,
 * no enumeration.
 *
 * ---------------------------------------------------------------------------
 * What this page shows and does not show
 * ---------------------------------------------------------------------------
 * The invoice's own fields (reference, amount, currency, summary line,
 * issued date) plus the order's human-facing reference — the same
 * reference-only content `emitPaymentReceived()` already treats as safe to
 * put in a notification payload. No party/customer name, no restricted
 * document, no vault reference: none of that is stored on `order_invoices`
 * in the first place, so there is nothing more restrictive this page could
 * accidentally leak even if it tried.
 */
final class InvoiceReceiptPage extends Component
{
    public string $reference = '';

    public function mount(string $reference): void
    {
        $this->reference = $reference;

        if ($this->findInvoice() === null) {
            abort(404);
        }
    }

    public function render(): View
    {
        $invoice = $this->findInvoice();

        if ($invoice === null) {
            abort(404);
        }

        $order = Order::query()->find($invoice->order_id);

        return view('livewire.public.invoices.invoice-receipt-page', [
            'invoice' => $invoice,
            'order' => $order,
        ])->layout('layouts.app', [
            'title' => 'Kwitansi - Makam.co.id',
            'active' => null,
        ]);
    }

    private function findInvoice(): ?OrderInvoice
    {
        if (trim($this->reference) === '') {
            return null;
        }

        return OrderInvoice::query()->where('reference', $this->reference)->first();
    }
}
