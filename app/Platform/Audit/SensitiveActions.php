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
    ];

    public static function requiresReason(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }
}
