<?php

declare(strict_types=1);

namespace App\Platform\FeatureGate\Modes;

use App\Platform\FeatureGate\GateFallback;

/**
 * requirements.md AC7 mode value. Backs `G-DATA-01` (Grave search/
 * reminder; assumptions-and-gates.md §2 / feature-flag-registry.md's
 * `feature.grave_search`).
 */
enum GraveSearchMode: string
{
    /**
     * `G-DATA-01` open: registry search is available.
     */
    case SearchEnabled = 'search_enabled';

    /**
     * `G-DATA-01` closed: §6.9 — "registry search unavailable; manual
     * assistance path." Distinct from the §6.2 "no result" empty state:
     * that is "the search ran and found nothing"; this mode is "the search
     * capability itself is not available", which §6.2 also flags
     * explicitly for PUB-031 ("say so explicitly rather than implying the
     * record does not exist").
     */
    case ManualAssistance = 'manual_assistance';

    public static function fromGateOpen(bool $open): self
    {
        return $open ? self::SearchEnabled : self::ManualAssistance;
    }

    /**
     * §6.9: dismissible — closing `G-DATA-01` changes a search/assistance
     * path, not payment or what the user receives.
     */
    public function fallback(): ?GateFallback
    {
        return match ($this) {
            self::SearchEnabled => null,
            self::ManualAssistance => new GateFallback(intent: 'info', dismissible: true),
        };
    }
}
