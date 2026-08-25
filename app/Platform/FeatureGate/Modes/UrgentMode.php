<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * `.kiro/specs/public-home-and-navigation/requirements.md` AC5 ("a truthful
 * Urgent-availability indicator on the homepage") and AC7-style mode value
 * (`requirements.md` in `platform-feature-gate`'s own spec — see
 * `Modes\PaymentMode`'s doc block for that AC7 text; this enum follows the
 * exact same shape for a fifth gate). Backed by `G-OPS-01` ("Urgent/At-Need
 * acceptance" — `database/migrations/2026_07_26_120400_seed_feature_gate_
 * registry.php`, seeded `closed`).
 *
 * design-system.md §6.9's banner table is the authority for what the closed
 * case means: *"`G-OPS-01` closed — `urgent` intent: operating hours and
 * coverage, no acceptance claim, hotline shown."* `mvp-scope.md` §7 agrees:
 * *"Urgent service | Opsi memberi jam/cakupan, tidak menerima order di luar
 * capacity, menampilkan hotline."* This enum only names the two cases and
 * their structural fallback (intent + dismissibility) — not the Indonesian
 * copy, which lives at the homepage view's own call site (see
 * `resources/views/livewire/public/home-page.blade.php`'s doc block for why
 * no literal hotline phone number is shown there: none is configured
 * anywhere in this repository today, confirmed by inspection before writing
 * that copy).
 */
enum UrgentMode: string
{
    /**
     * `G-OPS-01` open: the platform can accept Urgent/At-Need requests
     * through the normal booking flow. No fallback banner.
     */
    case AcceptingRequests = 'accepting_requests';

    /**
     * `G-OPS-01` closed: the platform cannot confirm Urgent/At-Need
     * acceptance automatically. This is deliberately NOT phrased as "Urgent
     * is unavailable" — mvp-scope.md's gated-fallback rule is "give hours/
     * coverage, do not accept beyond capacity, show a hotline", not "hide
     * the option". The booking wizard's Urgent choice at Step 3 is never
     * removed because this gate is closed (same Negative-criteria discipline
     * `PaymentMode::ManualCoordination` documents) — only the homepage's
     * up-front claim about it changes, to an honest "capacity unknown, call
     * us" state.
     */
    case CapacityUnknown = 'capacity_unknown';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::AcceptingRequests : self::CapacityUnknown;
    }

    /**
     * `null` for the open case — nothing to fall back to when the gate is
     * open. `intent: 'urgent'` (not `info`, unlike every other mode in this
     * namespace) per design-system.md §6.9's own table row for this exact
     * gate; confirmed a real supported `<x-mk.alert>` intent by reading
     * `resources/views/components/mk/alert.blade.php`'s own `$intents` array
     * before using it here.
     *
     * Never dismissible — same reasoning `PaymentMode::ManualCoordination`
     * documents for itself: an honest "capacity unknown, call us" state is
     * not something a user should be able to dismiss and forget. Closing
     * this gate does not change how a user pays, but it does change what
     * the platform can honestly promise about same-day/At-Need handling,
     * which is exactly the class of fact §6.9's dismissibility rule reserves
     * for "never dismissible".
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::AcceptingRequests => null,
            self::CapacityUnknown => new GateFallback(intent: 'urgent', dismissible: false),
        };
    }
}
