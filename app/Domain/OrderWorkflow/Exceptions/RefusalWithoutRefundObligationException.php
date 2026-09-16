<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Exceptions;

use DomainException;

/**
 * An attempt to mark an order `DITOLAK_SETELAH_BAYAR` while no
 * `refund_obligations` row exists for it — Stage R1 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * ---------------------------------------------------------------------------
 * Why this is a data precondition and not a flag
 * ---------------------------------------------------------------------------
 * The invariant being defended is: *a paid order cannot be refused unless the
 * debt back to the customer is already recorded*. A boolean argument
 * (`refundRecorded: true`) or a token object would both be things a caller
 * asserts. This one is a thing the caller must have already DONE, checked
 * against the database inside the same transaction that is about to write the
 * refusal. There is no value a caller can pass to make it true.
 *
 * The failure it prevents is not abstract: a grieving family has paid in
 * full, an admin refuses the order, and the only record that their money must
 * come back is a note somebody meant to write later.
 *
 * A `DomainException`, not an `InvalidArgumentException`: the arguments are
 * all individually valid: it is the state of the world that forbids the call.
 */
final class RefusalWithoutRefundObligationException extends DomainException
{
    public static function forOrder(string $orderId): self
    {
        return new self(
            "Order [{$orderId}] cannot be marked DITOLAK_SETELAH_BAYAR: no refund obligation exists for it. "
            .'A paid order may only be refused together with the debt owed back to the customer. Call '
            .'App\Domain\OrderWorkflow\Actions\RefusePaidOrder, which opens the refund obligation and '
            .'records the status change in one transaction, instead of calling RecordOrderStatusChange '
            .'directly.'
        );
    }
}
