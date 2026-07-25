<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * AC3's explicit, named list of actions that require a mandatory
 * `reason` — requirements.md: "WHEN a sensitive action occurs THE
 * SYSTEM SHALL require a mandatory reason where the domain requires
 * one — including DITOLAK, plot override, tariff-source change, gate
 * change, manual payment verification, certificate revoke, and vendor
 * payout." `tasks.md`: "Declare the sensitive-action list requiring a
 * mandatory reason."
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
    ];

    public static function requiresReason(string $action): bool
    {
        return in_array($action, self::ACTIONS, true);
    }
}
