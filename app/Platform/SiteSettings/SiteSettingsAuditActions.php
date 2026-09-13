<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings;

final class SiteSettingsAuditActions
{
    public const string UPDATED = 'SITE_SETTING_UPDATED';

    /**
     * A distinct action name for any save touching one or more
     * `bank_transfer_*` keys — the manual-payment destination account
     * shown on the booking wizard's Step 8 fallback card. Listed on
     * `App\Platform\Audit\SensitiveActions::ACTIONS` so `Audit::record()`
     * refuses to write this event without a human-authored `$reason` (finding
     * SEC-02, 6 Sep 2026 audit: this write path previously carried no
     * re-authentication, no reason, and no distinct audit trail from an
     * ordinary copy-text edit).
     */
    public const string BANK_TRANSFER_UPDATED = 'SITE_SETTING_BANK_TRANSFER_UPDATED';
}
