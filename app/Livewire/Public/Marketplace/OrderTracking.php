<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\MarketplaceOrderQuery;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use Illuminate\Contracts\View\View;
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
 */
final class OrderTracking extends Component
{
    public string $orderNumber = '';

    public string $customerRef = '';

    public ?MarketplaceOrder $order = null;

    public function mount(string $orderNumber): void
    {
        $this->orderNumber = $orderNumber;
        if ($this->customerRef === '') {
            $this->customerRef = auth()->check() ? (string) auth()->id() : session()->getId();
        }
    }

    public function render(): View
    {
        $this->order = MarketplaceOrderQuery::findForCustomer($this->orderNumber, $this->customerRef);

        $vendorStatus = null;
        if ($this->order !== null) {
            $vendorOrder = $this->order->vendorOrders->first();
            if ($vendorOrder !== null) {
                $vendorStatus = $vendorOrder->status;
            }
        }

        return view('livewire.public.marketplace.order-tracking', [
            'vendorStatus' => $vendorStatus,
        ])->layout('layouts.app', [
            'title' => 'Status Pesanan - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }
}
