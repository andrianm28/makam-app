<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\MemorialMode;
use Tests\TestCase;

final class MemorialModeTest extends TestCase
{
    public function test_open_gate_resolves_to_public_memorial_mode(): void
    {
        $this->assertSame(MemorialMode::PublicMemorial, MemorialMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_unavailable_mode(): void
    {
        $this->assertSame(MemorialMode::Unavailable, MemorialMode::fromGateOpen(false));
    }

    public function test_public_memorial_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(MemorialMode::PublicMemorial->fallback());
    }

    public function test_unavailable_fallback_is_info_intent_and_dismissible(): void
    {
        $fallback = MemorialMode::Unavailable->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('info', $fallback->intent);
        $this->assertTrue($fallback->dismissible);
    }
}
