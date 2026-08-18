<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;

/**
 * The ONE place this codebase's customer-service contact details are
 * resolved — hotline, WhatsApp, email, business hours.
 *
 * ---------------------------------------------------------------------------
 * Settings-aware since 18 Aug 2026 (public-beta readiness) — read before changing
 * ---------------------------------------------------------------------------
 * `phone()`/`whatsapp()`/`email()`/`businessHours()` resolve through
 * `SettingsService`'s config → env → `site_settings` → default precedence.
 * `service_hours`/`support_phone`/`support_whatsapp`/`support_email` were
 * already `SiteSetting::KNOWN_KEYS` rows exposed on the admin Site Settings
 * form BEFORE this change, but nothing in the public-facing code actually
 * read three of the four — `App\Livewire\Public\Support\HelpCentre` wired
 * only `service_hours` (see its own now-simplified comment). An operator
 * could configure a real phone number in the admin panel and the public
 * site would keep showing the placeholder regardless. This class closes
 * that gap once, for every caller, rather than each call site wiring
 * `SettingsService` for itself.
 *
 * `WHATSAPP` and `PHONE` are independently overridable (`support_whatsapp`
 * is its own `site_settings` row) even though they currently share one
 * fallback default — a real deployment may legitimately run support voice
 * and WhatsApp on different numbers.
 *
 * Every constant below stays the fallback DEFAULT for as long as no real
 * value is configured — see the class's prior revision for why they are
 * deliberately fictional placeholders. All four stay `private`: no call
 * site may read a constant directly, or it would silently stop honouring
 * whatever an operator has since configured.
 */
final class ContactInfo
{
    /**
     * Indonesian mobile format, obviously-placeholder digit pattern —
     * plausible enough to satisfy "look complete" without risking that the
     * exact digits happen to belong to a real person's phone (this codebase
     * has no way to verify a number is unassigned, so a sequential/
     * placeholder pattern is the safer middle ground while it remains the
     * effective value).
     */
    private const string PHONE = '+62 812-0000-1234';

    /**
     * Uses the real `makam.co.id` domain the business already owns (per
     * design-system.md OQ-02) — it is NOT actually configured to receive
     * mail until an operator sets a real, monitored inbox via the admin
     * Site Settings page; this is a display fallback, not a working inbox.
     */
    private const string EMAIL = 'bantuan@makam.co.id';

    /**
     * General customer-service hours. Urgent/At-Need coverage is a
     * SEPARATE claim (see `UrgentMode`'s own honesty framing — this app
     * never claims 24-hour automatic Urgent acceptance, only that the
     * hotline itself can be called any time to ask) — do not conflate the
     * two when writing copy that cites this method.
     */
    private const string BUSINESS_HOURS = 'Senin–Jumat 08.00–17.00 WIB';

    public static function phone(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_SUPPORT_PHONE, self::PHONE);
    }

    /**
     * Falls back to `PHONE`'s resolved value, not the bare `WHATSAPP`
     * constant, when no `support_whatsapp` setting is configured — a real
     * deployment that has only entered one contact number almost certainly
     * means "reach us the same way either channel," which `phone()`'s own
     * resolution already expresses.
     */
    public static function whatsapp(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_SUPPORT_WHATSAPP, self::phone());
    }

    public static function email(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_SUPPORT_EMAIL, self::EMAIL);
    }

    public static function businessHours(): string
    {
        return (string) app(SettingsService::class)->setting(SiteSetting::KEY_SERVICE_HOURS, self::BUSINESS_HOURS);
    }

    /**
     * Human-readable line combining phone + hours, for contexts (footer,
     * CS CTA cards) that want one ready-made sentence instead of
     * assembling the pieces themselves.
     */
    public static function summaryLine(): string
    {
        return self::phone().' · '.self::businessHours();
    }
}
