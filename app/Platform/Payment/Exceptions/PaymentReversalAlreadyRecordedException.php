<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use App\Platform\Payment\PaymentReversalType;
use RuntimeException;

/**
 * The `(reversal_type, reference)` UNIQUE constraint on `payment_reversals`
 * is this safe slice's stand-in for "double-refund blocked" — see the
 * migration's own doc block. Thrown by `Actions\RecordRefund`/
 * `Actions\RecordChargeback` when a caller (accidentally, via a duplicate
 * submit, or maliciously, via a replayed form post) attempts to record a
 * reversal for a `(type, reference)` pair that already has one, rather than
 * letting the raw database unique-violation `QueryException` leak to the
 * caller. Mirrors `PaymentVerificationAlreadyDecidedException`'s shape.
 */
final class PaymentReversalAlreadyRecordedException extends RuntimeException
{
    public static function forReference(PaymentReversalType $type, string $reference): self
    {
        return new self(
            "A {$type->value} reversal has already been recorded for reference [{$reference}] and cannot be recorded again."
        );
    }
}
