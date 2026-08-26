<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Marketplace\Models\MarketplaceOrder;

/**
 * Customer-facing order reads (requirement 13), scoped by the customer
 * reference — the marketplace's analogue of the vendor panel's
 * `CurrentVendorScope`-driven reads, but for the public side where no vendor
 * grant exists.
 *
 * ---------------------------------------------------------------------------
 * Enumeration safety (design-system §6.4)
 * ---------------------------------------------------------------------------
 * `findForCustomer()` returns `null` for BOTH an unknown order number and
 * one owned by a different customer — a single `where('order_number', ...)
 * ->where('customer_ref', ...)` read with no branch. The two cases are
 * deliberately indistinguishable, and no "belongs to another customer"
 * message may ever be produced on top of this class.
 */
final class MarketplaceOrderQuery
{
    public static function findForCustomer(string $orderNumber, string $customerRef): ?MarketplaceOrder
    {
        return MarketplaceOrder::query()
            ->with(['vendorOrders', 'items.listing.product'])
            ->where('order_number', $orderNumber)
            ->forCustomer($customerRef)
            ->first();
    }
}
