<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\MarkMarketplaceOrderPaid` when a marketplace order being
 * marked paid has no `vendor_payables` row — i.e. the placement assessment
 * (`PlaceMarketplaceOrder`) never opened one for it.
 *
 * Checkout opens the payable in the same transaction as the order, so a paid
 * order without one is a data-integrity anomaly, and the paid path refuses it
 * rather than guessing an amount: a debt recognised from an invented amount
 * silently misattributes money, the same failure `BadanUsahaNotConfiguredException`
 * guards against on the placement side.
 */
final class MarketplacePayableMissingException extends RuntimeException
{
    public static function forOrder(string $orderId): self
    {
        return new self(
            "Cannot mark marketplace order [{$orderId}] paid: no vendor payable "
            .'was opened for it by the placement assessment.'
        );
    }
}
