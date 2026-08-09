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
}
