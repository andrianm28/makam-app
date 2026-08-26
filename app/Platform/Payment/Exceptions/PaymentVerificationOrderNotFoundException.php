<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `SubmitManualPayment` when `$reference` does not match any
 * `marketplace_orders.order_number` — PAY-02's real-order-linkage
 * requirement: `payment_verifications.order_id` is a real foreign key now,
 * so a submission that cannot be resolved to a real order must be refused
 * before a row is written, never silently accepted as free text the way
 * `reference` alone used to be.
 *
 * Mirrors `PaymentSessionOrderNotFoundException`'s own shape and reasoning
 * for the sibling gap on the online-payment path: this is a caller-input
 * problem, not a guard denial, so nothing is written and no audit event is
 * recorded — there is no row yet to be its subject.
 */
final class PaymentVerificationOrderNotFoundException extends RuntimeException
{
    public static function forReference(string $reference): self
    {
        return new self(
            "Cannot submit a manual payment: no marketplace order carries the reference [{$reference}]."
        );
    }
}
