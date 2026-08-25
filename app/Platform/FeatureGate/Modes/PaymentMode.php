<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * requirements.md AC7: "expose gate state to the UI as an explicit mode
 * value, not a boolean" — `PaymentMode` is one of the four named modes.
 * Backs `G-PAY-01` (online payment; assumptions-and-gates.md §2 /
 * feature-flag-registry.md's `feature.online_payment`).
 *
 * design-system.md §6.9's banner table is the authority for what each case
 * means; this enum only names the cases and their structural fallback
 * (intent + dismissibility), not the Indonesian copy — see `GateFallback`.
 */
enum PaymentMode: string
{
    /**
     * `G-PAY-01` open: the online payment path is available. No fallback
     * banner.
     */
    case Online = 'online';

    /**
     * `G-PAY-01` closed: §6.9 — "online payment not yet available; Step 8
     * uses manual coordination. Step 8 is never removed." This is the mode
     * requirements.md's Negative criteria has in mind for "No MVP route or
     * booking step removed because a gate is closed" — the booking wizard
     * keeps Step 8, it just renders manual coordination instead of a
     * payment form.
     */
    case ManualCoordination = 'manual_coordination';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::Online : self::ManualCoordination;
    }

    /**
     * `null` for the open case — there is nothing to fall back to when the
     * gate is open. §6.9: never dismissible for `ManualCoordination`,
     * because it "changes how a user must pay" (§6.9's own dismissibility
     * rule, quoted verbatim in `GateFallback`'s doc block).
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::Online => null,
            self::ManualCoordination => new GateFallback(intent: 'info', dismissible: false),
        };
    }
}
