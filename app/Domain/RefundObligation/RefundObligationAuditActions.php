<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation;

/**
 * Audit action names for the refund obligation ledger —
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * Deliberately this context's own names rather than
 * `App\Platform\Payment\PaymentAuditActions::REFUND`. That action means "a
 * reversal was recorded against a payment"; these mean "a debt was opened,
 * paid, or confirmed". An operator reading the trail must be able to tell the
 * two apart without joining tables.
 */
final class RefundObligationAuditActions
{
    /**
     * A debt was created because a paid order was rejected. On
     * `SensitiveActions::ACTIONS`: an obligation with no stated cause cannot
     * be explained to the customer it belongs to.
     */
    public const string OPENED = 'REFUND_OBLIGATION_OPENED';

    /**
     * An operator recorded that they have transferred the money, with a
     * transfer reference and stored evidence — Stage R2. On
     * `SensitiveActions::ACTIONS` for a blunter reason than `OPENED`: this is
     * the only event in the whole ledger that claims somebody's debt has been
     * paid, and the system never sees the money move. Nothing verifies the
     * claim at the moment it is made, so the operator's stated justification
     * is a load-bearing part of the record rather than a courtesy.
     */
    public const string EXECUTED = 'REFUND_OBLIGATION_EXECUTED';

    /**
     * Receipt was confirmed — Stage R2, the terminal transition. Listed for
     * the same reason as `EXECUTED`: this says a grieving family has their
     * money back, and it is the last thing anybody writes about that debt.
     * A confirmation nobody had to justify is indistinguishable from one
     * entered to make a queue shorter.
     */
    public const string CONFIRMED = 'REFUND_OBLIGATION_CONFIRMED';
}
