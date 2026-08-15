<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use App\Platform\Payment\OrderType;
use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when the command's `orderType` has no
 * session-opening path in this task.
 *
 * Task 4 implements `OrderType::Booking` only. The marketplace path is
 * deferred per the plan's escalation clause: the six-condition guard is
 * `Order`/`Quote`-typed, and the marketplace has no `Quote`, no opening
 * authorizer, and no accepted session shape — mapping its state onto the
 * booking conditions would invent semantics no approved spec has ratified.
 * The `Marketplace` case is declared so the follow-up and the Task 5 router
 * can code against the closed enum; until then a marketplace command fails
 * loudly, never silently.
 */
final class PaymentSessionOrderTypeNotSupportedException extends RuntimeException
{
    public static function forOrderType(OrderType $orderType): self
    {
        return new self(
            "Opening a payment session for order type [{$orderType->value}] is not supported yet. "
            .'The session-creation path currently serves booking orders only; marketplace session '
            .'opening is a deferred follow-up of the online-payment gateway.'
        );
    }
}
