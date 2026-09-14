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
 * other mode) and — since ADR-0040 D4, 14 Sep 2026 — IS dismissible.
 *
 * That second assertion was `assertFalse` until ADR-0040. It changed
 * because the decision changed, not to make code pass: §6.9's literal rule
 * is "Dismissible only for informational modes — never for one that changes
 * how a user must pay", and closing `G-OPS-01` changes what the platform
 * can claim about Urgent acceptance, not how anyone pays. `PaymentModeTest`
 * and `PreNeedModeTest` still assert `assertFalse` for their own modes,
 * which really do alter the payment path — the distinction is the point of
 * the rule, so do not "align" those with this one.
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

    public function test_capacity_unknown_fallback_is_urgent_intent_and_dismissible(): void
    {
        $fallback = UrgentMode::CapacityUnknown->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('urgent', $fallback->intent);
        $this->assertTrue($fallback->dismissible);
    }
}
