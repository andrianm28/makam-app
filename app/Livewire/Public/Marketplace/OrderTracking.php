<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\UpdateVendorOrderStatus;
use App\Domain\Marketplace\MarketplaceOrderQuery;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\Audit\AuditSource;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/**
 * `/marketplace/pesanan/{orderNumber}` — PUB-024, order tracking.
 *
 * Requirement 13: the customer sees the current vendor-processing status.
 * Requirement 12: a paid order is never shown as fulfilment-complete —
 * payment and fulfilment render as TWO distinct indicator rows, each
 * resolved through `StatusIntent` in the view (never a `match` on a status
 * in Blade).
 *
 * The customer reference is resolved from the session (the same identity the
 * cart and checkout used), never trusted from the request; the route carries
 * only the order number. `MarketplaceOrderQuery::findForCustomer()` returns
 * null for BOTH a foreign order and a nonexistent one — design-system §6.4
 * enumeration safety: the view renders "Pesanan tidak ditemukan" and nothing
 * about the order.
 *
 * ---------------------------------------------------------------------------
 * `fileComplaint()` — the customer's own complaint-filing entry point
 * ---------------------------------------------------------------------------
 * `VendorProcessingStatus::KOMPLAIN` previously had exactly one write path:
 * the VENDOR's own one-click "Tandai komplain" self-flag
 * (`EditVendorOrder::getHeaderActions()`). There was no customer-facing way
 * to raise a complaint at all. This method is the second, customer-initiated
 * caller of that same closed list's `KOMPLAIN` value — routed through the
 * SAME single audited write path, `UpdateVendorOrderStatus`, per that
 * Action's own "the ONLY path vendor_orders.status may be written through"
 * rule. No parallel write path is introduced here.
 *
 * Re-resolves ownership from `$this->orderNumber`/`$this->customerRef`
 * fresh, inside this method, rather than trusting `$this->order` left over
 * from the last render — defence in depth against a tampered Livewire
 * request, on top of Livewire's own snapshot signing. A foreign or
 * nonexistent order is treated identically (both resolve to `null` from
 * `MarketplaceOrderQuery::findForCustomer()`) and the method silently
 * returns — the same enumeration-safe non-disclosure the page's read side
 * already guarantees; no separate "not your order" error is ever produced.
 *
 * Eligibility (`VendorProcessingStatus::isCustomerComplaintEligible()`) is
 * re-checked here too, not only hidden client-side by the view's `@if` —
 * the guard that actually matters is server-side.
 */
final class OrderTracking extends Component
{
    public string $orderNumber = '';

    public string $customerRef = '';

    public ?MarketplaceOrder $order = null;

    public string $complaintReason = '';

    public bool $complaintSubmitted = false;

    public ?string $complaintError = null;

    public function mount(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
        if ($this->customerRef === '') {
            $this->customerRef = auth()->check() ? (string) auth()->id() : session()->getId();
        }
    }

    public function fileComplaint(): void
    {
        $this->complaintError = null;

        $validated = Validator::make(
            ['complaintReason' => $this->complaintReason],
            ['complaintReason' => ['required', 'string', 'min:10', 'max:2000']],
        )->validate();

        $order = MarketplaceOrderQuery::findForCustomer($this->orderNumber, $this->customerRef);

        if ($order === null) {
            // Enumeration-safe: identical outcome to "order not found" —
            // see the class doc block. The read side already renders
            // "Pesanan tidak ditemukan" for this case; nothing more to do.
            return;
        }

        $vendorOrder = $order->vendorOrders->first();

        if ($vendorOrder === null || ! VendorProcessingStatus::isCustomerComplaintEligible($vendorOrder->status)) {
            $this->complaintError = 'Komplain tidak dapat diajukan untuk status pesanan saat ini.';

            return;
        }

        (new UpdateVendorOrderStatus)(
            order: $vendorOrder,
            status: VendorProcessingStatus::KOMPLAIN,
            actorReference: auth()->check() ? auth()->id() : null,
            actorRole: 'customer',
            auditSource: AuditSource::Api,
            notes: 'Komplain pelanggan: '.trim($validated['complaintReason']),
        );

        $this->complaintReason = '';
        $this->complaintSubmitted = true;
    }

    public function render(): View
    {
        $this->order = MarketplaceOrderQuery::findForCustomer($this->orderNumber, $this->customerRef);

        $vendorStatus = null;
        $canFileComplaint = false;
        if ($this->order !== null) {
            $vendorOrder = $this->order->vendorOrders->first();
            if ($vendorOrder !== null) {
                $vendorStatus = $vendorOrder->status;
                $canFileComplaint = ! $this->complaintSubmitted
                    && VendorProcessingStatus::isCustomerComplaintEligible($vendorStatus);
            }
        }

        return view('livewire.public.marketplace.order-tracking', [
            'vendorStatus' => $vendorStatus,
            'canFileComplaint' => $canFileComplaint,
        ])->layout('layouts.app', [
            'title' => 'Status Pesanan - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }
}
