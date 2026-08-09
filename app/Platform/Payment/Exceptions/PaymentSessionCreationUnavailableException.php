<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown whenever anything attempts to insert a `payment_sessions` row.
 *
 * Wave 1b ruling 1b-L3-01: the payment guard is deny-only while five of its
 * six conditions have no authoritative upstream record, so "there must be no
 * reachable PASS outcome, and therefore no `payment_sessions` row creatable
 * by any caller." `AGENTS.md` §Domain and financial invariants states the
 * underlying rule this protects: "Never create payment before valid
 * confirmation/reservation, accepted quote, and authorized opening."
 *
 * This is defence in depth, not the primary control — the primary control is
 * that `GuardResult` has no pass variant and `CreatePaymentSession` does not
 * exist. Removing this refusal is part of the task that implements a real
 * pass path, and only alongside a guard that can genuinely evaluate all six
 * conditions.
 */
final class PaymentSessionCreationUnavailableException extends RuntimeException
{
    public static function becauseGuardIsDenyOnly(): self
    {
        return new self(
            'No payment session may be created: the payment guard is deny-only until the confirmation, '
            .'reservation, quote, opening-authorization, and merchant/badan_usaha upstreams exist '
            .'(Wave 1b ruling 1b-L3-01).'
        );
    }
}
