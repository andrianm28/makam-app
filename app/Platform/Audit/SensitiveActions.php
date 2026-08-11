<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * AC3's explicit, named list of actions that require a mandatory
 * `reason` — requirements.md: "WHEN a sensitive action occurs THE
 * SYSTEM SHALL require a mandatory reason where the domain requires
 * one — including DITOLAK, plot override, tariff-source change, gate
 * change, manual payment verification, certificate revoke, and vendor
 * payout, and recorded price versions." `tasks.md`: "Declare the
 * sensitive-action list requiring a mandatory reason."
 *
 * Deliberately a closed, explicitly-reviewed list rather than a
 * magic-string convention (e.g. "any action ending in _REJECTED" or
 * "any action containing OVERRIDE") — a convention like that is
 * exactly the kind of thing a future caller could accidentally miss
 * or accidentally trigger. This list is expected to grow as the 13
 * consuming specs are reconciled against audit (`tasks.md`'s still-open
 * line, out of scope for this batch) — extend it deliberately, never
 * infer sensitivity from an action's name at runtime.
 */
final class SensitiveActions
{
    /**
     * @var list<string>
     */
    public const array ACTIONS = [
        'DITOLAK',
        'PLOT_OVERRIDE',
        'TARIFF_SOURCE_CHANGE',
        'GATE_CHANGE',
        'PAYMENT_MANUAL_VERIFICATION',
        'CERTIFICATE_REVOKE',
        'VENDOR_PAYOUT',
        'JOURNAL_REVERSAL',
        'PRICE_VERSION_RECORDED',
        'SERVICE_DEFINITION_PRICE_VERSION_RECORDED',

        // Added by S3-T2 (platform-identity-and-access MFA batch). Only
        // the explicit, human-initiated revoke
        // (`Mfa\MfaEnrolmentService::revoke()`, `Mfa\MfaAuditActions::RESET`)
        // is listed here — it changes a security control (an actor's MFA
        // enrolment) in a way that could enable account takeover if abused
        // without a recorded justification, the same category of risk
        // `CERTIFICATE_REVOKE` already represents on this list.
        //
        // Deliberately NOT listed: `MFA_ENROLMENT_CONFIRMED`,
        // `MFA_CHALLENGE_SUCCEEDED`, `MFA_CHALLENGE_FAILED`,
        // `MFA_RECOVERY_USED` (`Mfa\MfaAuditActions`'s other four
        // constants). These are routine, machine-driven outcomes of a
        // login-time verification attempt — there is no human-authored
        // "reason" for a user typing a 6-digit code correctly or
        // incorrectly, so requiring one here would be a caller-facing
        // dead end, not a real control. `MFA_RESET` alone is the
        // deliberate, reasoned action in this group.
        'MFA_RESET',
        'DOCUMENT_DELETE',

        // Added by Task 5 of the `platform-financial-ledger` lane, under the
        // user-approved Wave 1c ruling recorded in
        // `docs/superpowers/plans/2026-08-10-wave1b-financial-decisions.md`
        // §"Reconciliation-exception audit". Written by
        // `App\Platform\FinancialLedger\Actions\ResolveException`, the only
        // path by which a reconciliation exception ever reaches `resolved`.
        //
        // Listed for the same reason `JOURNAL_REVERSAL` is: the decision
        // itself — accept a variance, escalate, or post a correcting batch —
        // changes a financial outcome, and the decider's justification is the
        // entire substance of it. An accepted variance with no recorded reason
        // is indistinguishable from a difference somebody made go away.
        'RECONCILIATION_EXCEPTION_RESOLVED',

        // Added by platform-payment-adapter Task 6 (Wave 1d
        // Append-Correction, `.superpowers/sdd/2026-08-09-platform-payment-
        // adapter/task-6-brief.md`). `App\Platform\Payment\Actions\
        // RecordRefund`/`RecordChargeback` are the only writers of
        // `payment_reversals` rows; both are explicit, human-initiated
        // financial operations in the same risk category as
        // `VENDOR_PAYOUT` above, so a recorded justification is mandatory.
        'PAYMENT_REFUND',
        'PAYMENT_CHARGEBACK',

        // Added by the `platform-identity-seam` lane (design doc decision 5),
        // written by `App\Platform\IdentityAccess\Roles\Actions
        // \GrantActorRole`/`RevokeActorRole` — the only write path to
        // `actor_role_assignments`. Granting or revoking a role such as
        // `admin` or `finance` is the same privilege-escalation category as
        // `MFA_RESET`/`CERTIFICATE_REVOKE` above: a grant with no recorded
        // justification is indistinguishable from one nobody authorised.
        'ROLE_GRANT',
        'ROLE_REVOKE',

        // Added alongside the two above, by the same lane. Written by
        // `App\Platform\IdentityAccess\Scopes\Actions
        // \GrantScopeAssignment`/`RevokeScopeAssignment` — the only write
        // path to `scope_assignments`. A scope grant decides which records
        // an actor can even see (`ScopeAssignmentGlobalScope`'s query
        // boundary), the same access-control category as a role grant, so
        // the same mandatory-reason reasoning applies.
        'SCOPE_GRANT',
        'SCOPE_REVOKE',
    ];

    public static function requiresReason(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }
}
