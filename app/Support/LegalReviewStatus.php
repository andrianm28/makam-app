<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;

/**
 * H3 (`docs/superpowers/plans/2026-08-18-public-beta-release.md` Phase 0:
 * "Legal review of /privasi and /syarat-ketentuan — removes the DRAFT
 * label") made admin-editable rather than a code change. `App\Livewire\
 * Public\Legal\PrivacyPolicy`/`TermsOfService` read `note()` and, while it
 * is null, keep showing the honest "draf awal ... tinjauan hukum resmi"
 * disclaimer their own doc blocks require. This class cannot make the
 * legal review happen — only remove the deploy dependency once a human
 * confirms it did.
 *
 * `note()` returns the operator's own confirmation text (who reviewed the
 * pages and when), not a bare boolean: the admin Site Settings form (like
 * every other field there) is a single `TextInput` bound to one
 * `site_settings` string row, and a free-text confirmation is more useful
 * on an audit trail than an unlabelled toggle would be.
 */
final class LegalReviewStatus
{
    public static function note(): ?string
    {
        $value = trim((string) app(SettingsService::class)->setting(SiteSetting::KEY_LEGAL_REVIEW_NOTE, ''));

        return $value === '' ? null : $value;
    }

    public static function isReviewed(): bool
    {
        return self::note() !== null;
    }
}
