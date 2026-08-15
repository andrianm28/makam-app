<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\MarkMarketplaceOrderPaid` when the paid amount stated by
 * the caller does not EXACTLY equal the order's `total_minor`.
 *
 * The order total is the authoritative money fact: a marketplace order's
 * `payment_state` may move to `DIBAYAR` only for a payment of exactly what the
 * order owes. A session opened for a different amount — a checkout bug, a
 * tampered session, a provider anomaly — must never mark the order paid for
 * the wrong sum; the paid path refuses the transition before any state,
 * payable or audit write (the same stance as
 * `ApplyPaidEffects::assertAmountMatchesAcceptedQuote` on the booking leg).
 *
 * The comparison is integer minor units only. `MarketplaceOrder` carries no
 * currency column, and the webhook path's currency is already pinned to the
 * configured currency at validation.
 */
final class MarketplacePaymentAmountMismatchException extends RuntimeException
{
    public static function forOrder(string $orderId, int $expectedMinor, int $arrivedMinor): self
    {
        return new self(
            "Cannot mark marketplace order [{$orderId}] paid: arrived amount [{$arrivedMinor}] "
            ."minor units does not equal the order total [{$expectedMinor}] minor units."
        );
    }
}
