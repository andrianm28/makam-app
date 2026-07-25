<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\PaymentMode;
use Tests\TestCase;

/**
 * requirements.md AC7: mode values, not a bare boolean. design-system.md
 * §6.9's banner table for `PaymentMode` is the authority for the two
 * assertions below that matter most: `ManualCoordination` uses `info`
 * intent and is never dismissible (it "changes how a user must pay").
 */
final class PaymentModeTest extends TestCase
{
    public function test_open_gate_resolves_to_online_mode(): void
    {
        $this->assertSame(PaymentMode::Online, PaymentMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_manual_coordination_mode(): void
    {
        $this->assertSame(PaymentMode::ManualCoordination, PaymentMode::fromGateOpen(false));
    }

    public function test_online_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(PaymentMode::Online->fallback());
    }

    public function test_manual_coordination_fallback_is_info_intent_and_never_dismissible(): void
    {
        $fallback = PaymentMode::ManualCoordination->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('info', $fallback->intent);
        $this->assertFalse($fallback->dismissible);
    }
}
