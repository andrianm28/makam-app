<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when the order is already paid
 * (`orders.status = DIBAYAR`) and a new payment session would only allow a
 * second charge — see `OpenPaymentSession`'s class doc block for the
 * reasoning and for why the check sits before the six-condition guard.
 *
 * The refusal is recorded as a `PAYMENT_SESSION_OPENING_REFUSED` audit event
 * before this exception exists, so an operator can see the attempt.
 *
 * The order reference IS named in the message: this exception is never shown
 * to the customer (the wizard catches it with fixed Indonesian copy) and
 * never logged with restricted data — the reference is an internal record
 * identifier, the same class of value `PaymentSessionOrderNotFoundException`
 * already embeds.
 */
final class PaymentSessionOrderAlreadyPaidException extends RuntimeException
{
    public static function forReference(string $orderReference): self
    {
        return new self(
            "Cannot open a payment session for order [{$orderReference}]: the order has already been paid."
        );
    }
}
