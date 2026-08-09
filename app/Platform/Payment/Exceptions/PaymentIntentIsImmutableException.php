<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * A `payment_intents` row is a decision record: it states what the guard
 * evaluated and what it decided, at one instant. Revising it after the fact
 * would rewrite the evidence that a payment attempt was blocked — the same
 * append-only reasoning `App\Platform\Audit\Models\AuditEvent` applies to
 * `audit_events`, and for the same reason (design.md §Observability names
 * "blocked early-payment attempts, guard denial reasons" as observable
 * facts, which they stop being if they can be edited).
 *
 * See `App\Platform\Payment\Models\PaymentIntent`'s class doc block for
 * exactly which write paths this closes and which it cannot.
 */
final class PaymentIntentIsImmutableException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "payment_intents rows are append-only; [{$operation}] is not permitted on a PaymentIntent."
        );
    }
}
