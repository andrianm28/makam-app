<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use App\Domain\OrderWorkflow\OrderStatus;
use DomainException;

/**
 * `RecordOrderStatusChange::initial()` refused to write a second
 * no-predecessor `MASUK` event.
 *
 * That method is the one status path with no `OrderTransition` assertion
 * behind it — there is no from-state for an order's arrival — so its
 * single-use-per-order preconditions ARE its guard. This exception is what
 * they throw, and it is deliberately its own type rather than an
 * `IllegalOrderTransitionException`: nothing illegal was attempted against
 * the transition graph, and a caller catching graph violations must not
 * silently absorb "this order has already been opened".
 *
 * Carries the order id only. Never a reason, never metadata, never customer
 * content — `AGENTS.md` §Observability, and the same discipline
 * `OrderAlreadyPaidException` follows for the same reason (an exception
 * message ends up in logs and error trackers).
 */
final class OrderAlreadyOpenedException extends DomainException
{
    public static function becauseHistoryExists(string $orderId): self
    {
        return new self(
            "Order [{$orderId}] already has status history; an initial MASUK event may be recorded only once."
        );
    }

    public static function becauseStatusHasMovedOn(string $orderId, OrderStatus $current): self
    {
        return new self(
            "Order [{$orderId}] is at [{$current->value}], not MASUK; an initial event has no place on it."
        );
    }
}
