<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\Modes\WhatsAppMode;
use Tests\TestCase;

final class WhatsAppModeTest extends TestCase
{
    public function test_open_gate_resolves_to_whatsapp_mode(): void
    {
        $this->assertSame(WhatsAppMode::WhatsApp, WhatsAppMode::fromGateOpen(true));
    }

    public function test_closed_gate_resolves_to_email_in_app_fallback_mode(): void
    {
        $this->assertSame(WhatsAppMode::EmailInAppFallback, WhatsAppMode::fromGateOpen(false));
    }

    public function test_whatsapp_mode_has_no_fallback_banner(): void
    {
        $this->assertNull(WhatsAppMode::WhatsApp->fallback());
    }

    public function test_email_fallback_is_info_intent_and_dismissible(): void
    {
        $fallback = WhatsAppMode::EmailInAppFallback->fallback();

        $this->assertNotNull($fallback);
        $this->assertSame('info', $fallback->intent);
        $this->assertTrue($fallback->dismissible);
    }
}
