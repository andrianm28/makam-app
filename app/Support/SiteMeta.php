<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The site-wide defaults for `layouts/app.blade.php`'s social/search
 * metadata — the one place the fallback page description and the share-card
 * image are defined.
 *
 * Same single-source discipline `App\Support\CompanyInfo` and
 * `App\Support\ContactInfo` already establish in this codebase (`AGENTS.md`
 * §Documentation: "Do not duplicate canonical catalog data in multiple
 * hand-maintained documents or code locations"): a second hand-written copy
 * of this sentence in a Blade file is exactly the drift that rule exists to
 * prevent, and a share card that contradicts the page it links to is worse
 * than no share card.
 *
 * ---------------------------------------------------------------------------
 * Why this class holds no business data
 * ---------------------------------------------------------------------------
 * `DESCRIPTION` states only what the four mandatory MVP services are
 * (`AGENTS.md` §Mandatory MVP UX: Pemesanan Makam, Layanan Pemakaman,
 * Perpanjangan Makam, FAQ) — no price, no coverage claim, no operating
 * hours, no SLA, no partner or cemetery name. Anything of that kind belongs
 * in `SiteSetting`/`ContactInfo` behind an operator-entered value, never in
 * a constant that ships with the image, and must not be added here.
 *
 * A per-page override is supported by the layout (`$description`), so a
 * screen with something more specific to say can pass its own; this is the
 * fallback for every page that does not.
 */
final class SiteMeta
{
    /**
     * Kept under ~160 characters so neither Google's result snippet nor a
     * WhatsApp preview card truncates it mid-sentence.
     */
    private const string DESCRIPTION = 'Pesan makam, urus layanan pemakaman, dan perpanjang masa sewa makam '
        .'secara online. Setiap langkah tercatat jelas, dari pemesanan hingga konfirmasi.';

    /**
     * The homepage hero photograph, already committed and already served on
     * `/` — a share card showing the image the visitor lands on, rather than
     * a new asset added for the card alone. Path relative to `public/`, to
     * be passed through `asset()` by the caller: `og:image` must be an
     * absolute URL or the card renders with no image at all.
     */
    private const string OG_IMAGE_PATH = 'images/home/family-warmth.jpg';

    /**
     * Describes the photograph, not the brand — this is the alt text a
     * screen reader announces for the share card.
     */
    private const string OG_IMAGE_ALT = 'Keluarga yang hangat dan bahagia bersama';

    public static function description(): string
    {
        return self::DESCRIPTION;
    }

    public static function ogImagePath(): string
    {
        return self::OG_IMAGE_PATH;
    }

    public static function ogImageAlt(): string
    {
        return self::OG_IMAGE_ALT;
    }
}
