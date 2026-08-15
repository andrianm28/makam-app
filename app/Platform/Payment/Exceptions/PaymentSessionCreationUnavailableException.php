<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown when a `payment_sessions` row is inserted while the online-payment
 * gate (`G-PAY-01`) is closed.
 *
 * Wave 1b ruling 1b-L3-01 made the payment guard deny-only while five of its
 * six conditions have no authoritative upstream record, so "there must be no
 * reachable PASS outcome, and therefore no `payment_sessions` row creatable
 * by any caller." `AGENTS.md` §Domain and financial invariants states the
 * underlying rule this protects: "Never create payment before valid
 * confirmation/reservation, accepted quote, and authorized opening."
 *
 * The online-payment gateway task widened the guard: `PaymentSession`'s
 * `creating` hook now allows creation when `G-PAY-01` is open (dev) and
 * throws this exception when it is not — production keeps the gate closed
 * and keeps getting this refusal.
 *
 * This is defence in depth, not the primary control — the primary control is
 * the six-condition guard itself, which is the only path that can reach
 * session creation.
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
