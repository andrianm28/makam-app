<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use DomainException;

/**
 * The domain translation of an `order_status_events_paid_once` violation —
 * the partial unique index that makes "paid at most once per order" a
 * database guarantee rather than an application convention.
 *
 * ---------------------------------------------------------------------------
 * Why this exists rather than letting the `QueryException` propagate
 * ---------------------------------------------------------------------------
 * Two reasons, and the second is the load-bearing one.
 *
 * 1. A caller cannot distinguish "this transition is illegal"
 *    (`IllegalOrderTransitionException`) from "this order is already paid"
 *    when the latter arrives as a driver-specific `QueryException` — it
 *    surfaces as an opaque 500 instead of a meaningful domain outcome.
 *
 * 2. `Illuminate\Database\QueryException::formatMessage()` appends the full
 *    INSERT statement with its BINDINGS INTERPOLATED. On this INSERT those
 *    bindings include `reason` (free operator/customer text) and the
 *    `metadata` JSON blob. An uncaught throw is logged verbatim, so the raw
 *    exception puts caller-supplied content into the log —
 *    `AGENTS.md` §Observability: "Never place restricted data in logs,
 *    Pulse, Horizon tags, or error trackers." Verified experimentally
 *    against this repository's SQLite test driver: a second `DIBAYAR` insert
 *    produces `SQLSTATE[23000]: ... UNIQUE constraint failed:
 *    order_status_events.order_id (... SQL: insert into
 *    "order_status_events" (...) values ({"note":"..."}, ..., <the reason
 *    text>, ...))`. The same documented behaviour is recorded in
 *    `App\Platform\Payment\Actions\Concerns\DetectsDuplicatePaymentReversal`.
 *
 * Because of (2), the originating `QueryException` is deliberately NOT
 * attached as this exception's `$previous`: the framework's exception
 * handler logs the whole chain, so chaining it would reintroduce exactly the
 * interpolated bindings this translation exists to keep out of the log. The
 * order id below is a reference, never content.
 */
final class OrderAlreadyPaidException extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(
            "Order [{$orderId}] already has a DIBAYAR status event; an order can be paid at most once."
        );
    }
}
