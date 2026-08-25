<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * What `Actions\ReconcilePaymentSession` did with one `PaymentSession`.
 *
 * Every case is a normal, terminal result — a reconciliation attempt never
 * throws for "the provider hasn't decided yet" or "someone already handled
 * this"; those are `StillPending` and `AlreadyReconciled` respectively, the
 * same "return an outcome, not a boolean or an exception" shape
 * `ProcessWebhookEventOutcome` already establishes for the same reason: every
 * case is distinguishable in a test.
 */
enum ReconciliationOutcome
{
    /**
     * The session was already `PAID`/`FAILED`/`EXPIRED` before this call —
     * nothing to reconcile, and no provider API call was made.
     */
    case AlreadyTerminal;

    /**
     * The provider's own status record still reports the payment as pending
     * (or an unrecognised, non-terminal value). No write happened.
     */
    case StillPending;

    /**
     * The provider reports a terminal outcome and this call was the one that
     * applied it — `Actions\ApplyPaymentSettlement` ran, inside
     * `ProcessWebhookEvent`'s claim, the same settlement path a real webhook
     * uses.
     */
    case Settled;

    /**
     * A `provider_events` row for this reconciliation already existed and was
     * already claimed/processed by an earlier reconciliation attempt (or the
     * real webhook arrived and settled it independently) — a safe no-op, not
     * an error.
     */
    case AlreadyReconciled;

    /**
     * The provider transaction this reconciliation resolved is already
     * claimed by a DIFFERENT settling `provider_events` row (a genuine
     * financial-integrity anomaly — `ProcessWebhookEvent`'s own
     * `SettlementConflict` outcome). Recorded and audited there; never
     * silently retried.
     */
    case SettlementConflict;
}
