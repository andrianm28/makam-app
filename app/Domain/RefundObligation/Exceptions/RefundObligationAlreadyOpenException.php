<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Exceptions;

use RuntimeException;

/**
 * Thrown when an order already carries a refund obligation.
 *
 * `refund_obligations.order_id` is UNIQUE, so two concurrent rejections of one
 * order cannot open two debts against it. This exception is that constraint
 * violation translated into a domain answer instead of a raw SQL error — the
 * pattern `PaymentReversalAlreadyRecordedException` already established.
 *
 * Reaching this is not a bug in itself: it is the second rejection discovering
 * the debt is already recorded, which is exactly the outcome wanted.
 */
final class RefundObligationAlreadyOpenException extends RuntimeException
{
    public static function forOrder(string $orderId): self
    {
        return new self(
            "Order [{$orderId}] already has a refund obligation. An order carries at most one; "
            .'see the refund_obligations migration doc block for why.'
        );
    }
}
