<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use RuntimeException;

/**
 * An `order_status_events` row is the evidence that a commercial status
 * transition happened, who caused it, and why. Revising or deleting one
 * rewrites that evidence — and deleting a `DIBAYAR` row additionally
 * releases `order_status_events_paid_once`, the partial unique index that
 * is this lane's load-bearing "paid at most once per order" guarantee.
 *
 * Same append-only accounting as
 * `App\Platform\Payment\Exceptions\PaymentIntentIsImmutableException` and
 * `App\Platform\Audit\Exceptions\AuditRecordIsImmutableException`, for the
 * same underlying reason: this project has one PostgreSQL role per
 * environment, which both owns the schema and runs the application, so
 * there is no lower-privileged role to REVOKE UPDATE/DELETE from.
 *
 * `create()` is deliberately NOT guarded — see the model's class doc block.
 */
final class OrderStatusEventIsAppendOnlyException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "order_status_events rows are append-only; [{$operation}] is not permitted on an OrderStatusEvent."
        );
    }
}
