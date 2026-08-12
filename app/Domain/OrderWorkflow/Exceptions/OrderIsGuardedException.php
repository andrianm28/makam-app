<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use RuntimeException;

/**
 * An `orders` row carries the commercial status of a real, money-bearing
 * order. Moving that status is not an attribute write — it is a transition
 * that must also produce an `order_status_events` row, an `audit_events`
 * row, and an `order.status_changed.v1` outbox event, all in one
 * transaction. A bare `$order->update(['status' => 'DIBAYAR'])` produces
 * the status and none of the three, and — because the paid-once partial
 * unique index lives on `order_status_events`, not on `orders` — it does
 * not even touch the database guarantee this lane exists to establish.
 *
 * Thrown by `App\Domain\OrderWorkflow\Models\Order`'s
 * `update()`/`performUpdate()`/`delete()` overrides. See that model's class
 * doc block for exactly which write paths those close and which they
 * cannot.
 */
final class OrderIsGuardedException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            'orders rows are written only by App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange; '.
            "[{$operation}] is not permitted on an Order."
        );
    }
}
