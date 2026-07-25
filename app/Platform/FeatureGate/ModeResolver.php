<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate;

use App\Platform\FeatureGate\Modes\GraveSearchMode;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use App\Platform\FeatureGate\Modes\WhatsAppMode;

/**
 * Translates `FeatureGateResolver`'s per-gate boolean into the four named
 * mode values requirements.md AC7 requires the UI to read
 * (`PaymentMode`/`WhatsAppMode`/`PreNeedMode`/`GraveSearchMode`), instead of
 * every consumer re-deriving "which gate id backs which mode" itself.
 *
 * This is the ONE place that pairs a mode enum with its backing gate id —
 * `G-PAY-01`, `G-WA-01`, `G-LEGAL-01`, `G-DATA-01` — so that pairing is
 * asserted once (and tested once, see
 * `tests/Unit/Platform/FeatureGate/ModeResolverTest.php`) rather than
 * scattered across every screen that needs a mode.
 */
final readonly class ModeResolver
{
    public function __construct(
        private FeatureGateResolver $gates,
    ) {}

    public function paymentMode(): PaymentMode
    {
        return PaymentMode::fromGateOpen($this->gates->isOpen('G-PAY-01'));
    }

    public function whatsAppMode(): WhatsAppMode
    {
        return WhatsAppMode::fromGateOpen($this->gates->isOpen('G-WA-01'));
    }

    public function preNeedMode(): PreNeedMode
    {
        return PreNeedMode::fromGateOpen($this->gates->isOpen('G-LEGAL-01'));
    }

    public function graveSearchMode(): GraveSearchMode
    {
        return GraveSearchMode::fromGateOpen($this->gates->isOpen('G-DATA-01'));
    }
}
