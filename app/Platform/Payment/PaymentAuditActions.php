<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The audit action names this module writes, in one place, so no call site
 * spells one as a magic string — the same convention as
 * `App\Domain\Faq\FaqAuditActions`,
 * `App\Domain\ServiceCatalog\ServiceCatalogAuditActions`, and
 * `App\Platform\IdentityAccess\Mfa\MfaAuditActions`.
 *
 * ---------------------------------------------------------------------------
 * None of these is on `SensitiveActions::ACTIONS`, deliberately
 * ---------------------------------------------------------------------------
 * `App\Platform\Audit\SensitiveActions::ACTIONS` already carries this
 * module's two future sensitive actions (`PAYMENT_MANUAL_VERIFICATION`,
 * `VENDOR_PAYOUT`), which make a `reason` mandatory at write time.
 * `PAYMENT_GUARD_DENIED` is deliberately NOT among them: the plan's Global
 * Constraints say "No new `SensitiveActions` entries in this lane beyond the
 * two already present", and Wave 1b ruling 1b-L3-01 did not add one. A guard
 * denial is a high-volume, automatic, machine-decided event; a mandatory
 * free-text reason on it would be either boilerplate or a place for a
 * careless caller to paste restricted data. The structured
 * condition/reason/missing-upstream fields on `payment_intents` carry the
 * explanation instead.
 */
final class PaymentAuditActions
{
    /**
     * Written on every guard denial, with `AuditOutcome::Denied` and the
     * `payment_intents` row as its subject. design.md §Observability names
     * "blocked early-payment attempts, guard denial reasons" as things this
     * module must make observable; this action is how.
     */
    public const string GUARD_DENIED = 'PAYMENT_GUARD_DENIED';

    /**
     * AC6: "WHEN validation fails THE SYSTEM SHALL record and reject the
     * webhook, and SHALL NOT silently ignore it." Written by `ReceiveWebhook`
     * with `AuditOutcome::Denied` on every rejected delivery, alongside the
     * `provider_events.status = REJECTED_*` row. The row is the machine record;
     * this is the trail an operator reads.
     *
     * Also used for the one rejection that has no row to point at — a body over
     * `config('payment.webhook.max_body_bytes')`, which is refused before
     * anything is stored.
     *
     * Not on `SensitiveActions::ACTIONS`, for the same reason as
     * `GUARD_DENIED`: a mandatory free-text reason on a high-volume,
     * machine-decided event would be boilerplate or a place to paste
     * restricted data. The structured status and the closed-list `note` carry
     * the explanation instead.
     */
    public const string WEBHOOK_REJECTED = 'PAYMENT_WEBHOOK_REJECTED';

    /**
     * A redelivery that collided with one of `provider_events`' unique guards.
     *
     * This one is not merely a convenience: the colliding row is the row the
     * database refused to write, so an audit event is the ONLY durable record
     * that a second delivery arrived at all. `payment-webhook.md` §Idempotency
     * requires the duplicate to be acknowledged with the original processing
     * reference and to repeat no effects — this records that it happened
     * without touching the append-only original.
     */
    public const string WEBHOOK_DUPLICATE = 'PAYMENT_WEBHOOK_DUPLICATE';

    /**
     * A settling provider event refused at apply time because its provider
     * transaction was already claimed by a different settling event —
     * `payment-webhook.md` §Idempotency: "A secondary guard should prevent the
     * same provider transaction from settling multiple invoices."
     *
     * Written by `ProcessWebhookEvent` with `AuditOutcome::Denied` alongside the
     * `provider_events.status = MANUAL_REVIEW` row. The status is the machine
     * record and the reason the event is never applied; this is the trail that
     * tells an operator a human decision is owed, and which other row holds the
     * claim.
     *
     * Not on `SensitiveActions::ACTIONS`, for the same reason as the two actions
     * above and because the plan's Global Constraints say this lane adds no new
     * entry beyond the two already present. Nothing here is a human-initiated
     * action with a reason to state — it is a machine refusal, and the
     * structured status plus the closed-list `note` carry the explanation.
     */
    public const string WEBHOOK_SETTLEMENT_CONFLICT = 'PAYMENT_WEBHOOK_SETTLEMENT_CONFLICT';

    /**
     * Task 5 (Wave 1c Append-Correction) — written by `SubmitManualPayment`
     * with `AuditOutcome::Allowed`, subject = the new `PaymentVerification`
     * row, on every manual payment submission (with or without a proof
     * file).
     *
     * Not on `SensitiveActions::ACTIONS`, for the same category of reason as
     * the three actions above: a customer submitting a manual payment
     * reference is a routine, self-service action with no free-text
     * "reason" to give — only evidence (the submitted fields and,
     * optionally, a proof document reference).
     */
    public const string MANUAL_SUBMITTED = 'PAYMENT_MANUAL_SUBMITTED';

    /**
     * AC8/AC9 — written by `VerifyManualPayment` with `AuditOutcome::Allowed`
     * (approve) or `AuditOutcome::Denied` (reject), subject = the
     * `PaymentVerification` row.
     *
     * ALREADY on `SensitiveActions::ACTIONS` (added by an earlier task per
     * the plan's own Files note: "SensitiveActions (already has
     * `PAYMENT_MANUAL_VERIFICATION`)") — `Audit::record()`'s existing
     * `SensitiveActions::requiresReason()` check enforces the mandatory
     * reason; `VerifyManualPayment` does not re-implement that check.
     */
    public const string MANUAL_VERIFICATION = 'PAYMENT_MANUAL_VERIFICATION';
}
