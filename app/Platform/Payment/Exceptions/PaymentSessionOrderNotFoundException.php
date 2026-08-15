<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when the command's `orderRef` does not match
 * any known order.
 *
 * This is a caller bug, not a guard denial: there is nothing to evaluate the
 * six-condition guard against, so no `payment_intents` decision record is
 * written and no provider call is made.
 */
final class PaymentSessionOrderNotFoundException extends RuntimeException
{
    public static function forReference(string $orderRef): self
    {
        return new self(
            "Cannot open a payment session: no order carries the reference [{$orderRef}]."
        );
    }
}
