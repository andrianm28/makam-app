<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The ONE place this codebase's placeholder customer-service contact
 * details are defined — hotline, WhatsApp, email, business hours.
 *
 * ---------------------------------------------------------------------------
 * These are DUMMY values, not real contact points — read before changing
 * ---------------------------------------------------------------------------
 * No real hotline/WhatsApp/email/hours were ever configured anywhere in
 * this repository (see docs/planning/sprint-plan.md's "data yang perlu
 * disediakan" checklist and `App\Platform\FeatureGate\Modes\UrgentMode`'s
 * own doc block, which explicitly documented this gap). The user has since
 * explicitly authorized filling every public-facing gap with clearly
 * plausible placeholder data for full display on the dev environment
 * (dev.makam.co.id is a real, public, non-production site by design —
 * docs/operations/dev-staging-environment.md — synthetic data is the
 * correct content type there).
 *
 * `PHONE` deliberately uses an obviously-placeholder digit pattern
 * (`0000-1234`) within a real, valid Indonesian mobile number FORMAT —
 * plausible enough to satisfy "look complete" without risking that the
 * exact digits happen to belong to a real person's phone (this codebase
 * has no way to verify a number is unassigned, so a sequential/placeholder
 * pattern is the safer middle ground). `EMAIL` uses the real `makam.co.id`
 * domain the business already owns (per design-system.md OQ-02, the live
 * site at that domain is real) — it is NOT actually configured to receive
 * mail today; this is a display value, not a working inbox.
 *
 * Every one of these four constants is the single source every view/Action
 * that shows contact information reads from — do NOT hardcode the phone
 * number, email, or hours a second time anywhere else (AGENTS.md
 * §Documentation's "do not duplicate canonical data" rule, applied here
 * the same way `StatusIntent` applies it to status colours).
 *
 * A future real-business-data batch replaces these four values with real
 * ones in this one file; nothing else needs to change.
 */
final class ContactInfo
{
    /**
     * Indonesian mobile format, obviously-placeholder digit pattern — see
     * class doc block for why. Used for both the voice hotline and
     * WhatsApp (one shared number, common for a small support team).
     */
    public const string PHONE = '+62 812-0000-1234';

    /**
     * Same number as PHONE — display copy should say "hubungi atau
     * WhatsApp nomor yang sama" rather than restating a second constant
     * that would only ever hold an identical value.
     */
    public const string WHATSAPP = self::PHONE;

    public const string EMAIL = 'bantuan@makam.co.id';

    /**
     * General customer-service hours. Urgent/At-Need coverage is a
     * SEPARATE claim (see UrgentMode's own honesty framing — this app
     * never claims 24-hour automatic Urgent acceptance, only that the
     * hotline itself can be called any time to ask) — do not conflate the
     * two when writing copy that cites this constant.
     */
    public const string BUSINESS_HOURS = 'Senin–Jumat 08.00–17.00 WIB';

    /**
     * Human-readable line combining phone + hours, for contexts (footer,
     * CS CTA cards) that want one ready-made sentence instead of
     * assembling the pieces themselves.
     */
    public static function summaryLine(): string
    {
        return self::PHONE.' · '.self::BUSINESS_HOURS;
    }
}
