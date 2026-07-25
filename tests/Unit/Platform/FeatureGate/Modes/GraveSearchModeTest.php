<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Tests\TestCase;

final class GraveSearchModeTest extends TestCase
{
    public function test_open_gate_resolves_to_search_enabled_mode(): void
    {
        $this->assertSame(GraveSearchMode::SearchEnabled, GraveSearchMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_manual_assistance_mode(): void
    {
        $this->assertSame(GraveSearchMode::ManualAssistance, GraveSearchMode::fromGateOpen(false));
    }

    public function test_search_enabled_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(GraveSearchMode::SearchEnabled->fallback());
    }

    public function test_manual_assistance_fallback_is_info_intent_and_dismissible(): void
    {
        $fallback = GraveSearchMode::ManualAssistance->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('info', $fallback->intent);
        $this->assertTrue($fallback->dismissible);
    }
}
