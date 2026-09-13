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
}
