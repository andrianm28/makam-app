<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;

/**
 * The ONE place this codebase's legal-entity details (company name,
 * registered address) are resolved — used by the footer's small
 * company-info line and by the two legal pages (`App\Livewire\Public\
 * Legal\PrivacyPolicy`, `TermsOfService`). Same single-source discipline
 * `App\Support\ContactInfo` already establishes in this codebase for
 * contact details (AGENTS.md §Documentation: "Do not duplicate canonical
 * catalog data in multiple hand-maintained documents or code locations")
 * — do NOT hardcode a company name/address a second time anywhere else;
 * call `name()`/`address()` instead.
 *
 * ---------------------------------------------------------------------------
 * Settings-aware since 18 Aug 2026 (public-beta readiness) — read before changing
 * ---------------------------------------------------------------------------
 * `name()`/`address()` resolve through `SettingsService`'s config → env →
 * `site_settings` → default precedence (`SiteSetting::KEY_COMPANY_NAME`/
 * `KEY_COMPANY_ADDRESS`), the same precedence `App\Livewire\Public\Support\
 * HelpCentre` already used for `service_hours` before this change — an
 * operator can now enter the real legal entity via the admin Site Settings
 * page (`SiteSettingsForm`) with no deploy, and every caller picks it up
 * immediately, because none of them may read `NAME`/`ADDRESS` directly
 * (both stay `private` for exactly this reason — a public constant would
 * invite a call site to bypass the settings layer the way `NAME`/`ADDRESS`
 * themselves used to BE the only source).
 *
 * The constants remain the fallback DEFAULT for as long as no real value is
 * configured — see their own doc block for why they are deliberately
 * fictional. Nothing else needs to change when a real value is entered:
 * the admin page, not a code deploy, is now how this gap gets closed.
 */
final class CompanyInfo
{
    /**
     * `NAME` uses the literal word "Contoh" ("Example") — the same honesty
     * marker word `2026_07_26_190300_seed_cemeteries_and_capability_
     * profiles.php` already established for this codebase's placeholder
     * addresses — so nothing here could be mistaken for a real registered
     * PT while it remains the effective value. `ADDRESS` reuses that exact
     * migration's "Jl. Contoh ..." placeholder-street convention, with a
     * street name and sub-area not used by any of that migration's ten
     * seeded cemetery addresses, so this is never confusable with any of
     * those fixture rows.
     */
    private const string NAME = 'PT Contoh Makam Digital Indonesia';

    private const string ADDRESS = 'Jl. Contoh Cendana No. 88, Kuningan, Jakarta Selatan';

    public static function name(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_COMPANY_NAME, self::NAME);
    }

    public static function address(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_COMPANY_ADDRESS, self::ADDRESS);
    }

    /**
     * Nomor Induk Berusaha — Indonesia's single business registration
     * number. Unlike `name()`/`address()`, this has no fictional fallback
     * constant: a plausible-looking fake registration number would be
     * actively misleading in a way "PT Contoh ..." is not (that name
     * self-labels as a placeholder; a 13-digit NIB pattern would not).
     * Returns null until an operator enters a real one via the admin Site
     * Settings page — callers must not render an NIB line when this is
     * null, not substitute a placeholder of their own.
     */
    public static function nib(): ?string
    {
        $value = trim((string) app(SettingsService::class)->setting(SiteSetting::KEY_COMPANY_NIB, ''));

        return $value === '' ? null : $value;
    }
}
