<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * requirements.md AC7 mode value. Backs `G-WA-01` (WhatsApp;
 * assumptions-and-gates.md §2 / feature-flag-registry.md's
 * `feature.whatsapp`).
 */
enum WhatsAppMode: string
{
    /**
     * `G-WA-01` open: WhatsApp notifications are available.
     */
    case WhatsApp = 'whatsapp';

    /**
     * `G-WA-01` closed: §6.9 — "notifications via email + in-app; WhatsApp
     * not yet available." Also the mode §6.8's success-state rule reads:
     * "neutral 'WhatsApp belum tersedia' (when G-WA-01 is closed)" —
     * that badge and this mode describe the same underlying gate state.
     */
    case EmailInAppFallback = 'email_in_app_fallback';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::WhatsApp : self::EmailInAppFallback;
    }

    /**
     * §6.9: dismissible for this mode — unlike `PaymentMode`, closing
     * `G-WA-01` changes a notification channel, not how a user pays or
     * what they receive, so it is one of the "informational modes" §6.9's
     * dismissibility rule allows to be dismissed.
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::WhatsApp => null,
            self::EmailInAppFallback => new GateFallback(intent: 'info', dismissible: true),
        };
    }
}
