<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\PreNeedMode;
use Tests\TestCase;

final class PreNeedModeTest extends TestCase
{
    public function test_open_gate_resolves_to_payment_enabled_mode(): void
    {
        $this->assertSame(PreNeedMode::PaymentEnabled, PreNeedMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_interest_only_mode(): void
    {
        $this->assertSame(PreNeedMode::InterestOnly, PreNeedMode::fromGateOpen(false));
    }

    public function test_payment_enabled_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(PreNeedMode::PaymentEnabled->fallback());
    }

    public function test_interest_only_fallback_is_info_intent_and_never_dismissible(): void
    {
        // §6.9: "registers interest; no payment created" — changes what
        // the user receives, so (like PaymentMode::ManualCoordination) this
        // is never dismissible.
        $fallback = PreNeedMode::InterestOnly->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('info', $fallback->intent);
        $this->assertFalse($fallback->dismissible);
    }
}
