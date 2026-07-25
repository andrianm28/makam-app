<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * requirements.md AC7 mode value. Backs `G-LEGAL-01` (Paid Pre-Need plot
 * purchase; assumptions-and-gates.md §2 / feature-flag-registry.md's
 * `feature.preneed_payment`). `feature.preneed_interest` (the always-on,
 * `G-LEGAL-01`-independent flag — see the seed migration) is what keeps
 * `InterestOnly` a real, useful product mode rather than a dead end: the
 * interest flow itself is never gated by `G-LEGAL-01`.
 */
enum PreNeedMode: string
{
    /**
     * `G-LEGAL-01` open: Pre-Need plot purchase can create a real payment.
     */
    case PaymentEnabled = 'payment_enabled';

    /**
     * `G-LEGAL-01` closed: §6.9 — "registers interest; no payment
     * created." The interest-registration step itself is never removed
     * (Negative criteria) — only whether it can proceed to payment
     * changes.
     */
    case InterestOnly = 'interest_only';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::PaymentEnabled : self::InterestOnly;
    }

    /**
     * Not dismissible: like `PaymentMode::ManualCoordination`, this mode
     * changes what the user receives (no payment/reservation is created),
     * which §6.9's dismissibility rule excludes from being dismissible.
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::PaymentEnabled => null,
            self::InterestOnly => new GateFallback(intent: 'info', dismissible: false),
        };
    }
}
