<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use DomainException;

/**
 * `AuthorizeOrderPaymentOpening` throws this when the actor is not
 * authorised to open payment on an order.
 *
 * Carries the order id only — never a reason, never metadata, never
 * customer content (`AGENTS.md` §Observability).
 */
final class OrderPaymentOpeningNotAuthorisedException extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self("Actor is not authorised to open payment on order [{$orderId}].");
    }
}
