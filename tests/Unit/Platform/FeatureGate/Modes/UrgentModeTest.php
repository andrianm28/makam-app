<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\UrgentMode;
use Tests\TestCase;

/**
 * Mirrors `PaymentModeTest` exactly in structure. requirements.md AC5/AC7:
 * mode values, not a bare boolean. design-system.md §6.9's banner table for
 * `G-OPS-01` is the authority for the two assertions below that matter
 * most: `CapacityUnknown` uses `urgent` intent (not `info`, unlike every
 * other mode) and is never dismissible.
 *
 * Those two assertions are ONE fact, not two. §6.9 reads "Dismissible
 * **only** for informational modes — never for one that changes how a user
 * must pay", and `urgent` is the intent §6.9's table gives this gate
 * *instead of* `info`. So the first assertion is the reason for the second:
 * a non-informational banner is outside the grant before the payment clause
 * is reached.
 *
 * ADR-0040 D4 flipped this to `assertTrue` on 14 Sep 2026, reading only the
 * payment clause. Reverted the same day, unpushed — see `UrgentMode::
 * fallback()`'s own doc block for why the dismissible siblings it cited
 * (`GraveSearchMode`, `WhatsAppMode`, `MemorialMode`) argue the other way:
 * all three are `intent: 'info'`.
 */
final class UrgentModeTest extends TestCase
{
    public function test_open_gate_resolves_to_accepting_requests_mode(): void
    {
        $this->assertSame(UrgentMode::AcceptingRequests, UrgentMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_capacity_unknown_mode(): void
    {
        $this->assertSame(UrgentMode::CapacityUnknown, UrgentMode::fromGateOpen(false));
    }

    public function test_accepting_requests_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(UrgentMode::AcceptingRequests->fallback());
    }

    public function test_capacity_unknown_fallback_is_urgent_intent_and_never_dismissible(): void
    {
        $fallback = UrgentMode::CapacityUnknown->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('urgent', $fallback->intent);
        $this->assertFalse($fallback->dismissible);
    }
}
