<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Exceptions;

use App\Domain\RefundObligation\RefundObligationStatus;
use RuntimeException;

/**
 * Thrown when an Action is asked to move an obligation along an edge the
 * status machine does not have — Stage R2 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * ---------------------------------------------------------------------------
 * Why this exists when `Models\RefundObligation::saving()` already refuses
 * ---------------------------------------------------------------------------
 * The model guard and the Postgres CHECK both reject an illegal transition,
 * and neither is removed by this class existing. What they cannot do is
 * refuse it *before* a transaction has been opened and a row locked, or say
 * anything about it in the operator's own vocabulary — a `LogicException`
 * from a model's `saving()` hook reads, correctly, as "a programming error
 * reached the database layer".
 *
 * A second operator clicking "Catat Eksekusi" on an obligation a colleague
 * executed thirty seconds ago is not a programming error. It is the ordinary
 * concurrency this lane's row lock exists to serialise, and it deserves an
 * answer that names the state the obligation is actually in. That is this
 * exception, raised while the row is held, so the answer cannot be stale by
 * the time it is read.
 */
final class RefundObligationTransitionNotAllowedException extends RuntimeException
{
    public static function from(
        string $obligationId,
        RefundObligationStatus $current,
        RefundObligationStatus $requested,
    ): self {
        return new self(
            "Refund obligation [{$obligationId}] is in status {$current->value} and cannot move to "
            ."{$requested->value}. The status machine is forward-only, and nothing closes an obligation "
            .'except a recorded execution with its evidence.'
        );
    }
}
