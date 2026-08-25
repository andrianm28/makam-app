<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property string|null $value
 * @property string|null $updated_by_ref
 */
final class SiteSetting extends Model
{
    public const string KEY_SERVICE_HOURS = 'service_hours';

    public const string KEY_SUPPORT_PHONE = 'support_phone';

    public const string KEY_SUPPORT_WHATSAPP = 'support_whatsapp';

    public const string KEY_SUPPORT_EMAIL = 'support_email';

    public const string KEY_MARKETPLACE_BADAN_USAHA_REF = 'marketplace_badan_usaha_ref';

    public const string KEY_PAYMENT_MERCHANT_REF = 'payment_merchant_ref';

    public const string KEY_PAYMENT_BADAN_USAHA_REF = 'payment_badan_usaha_ref';

    /**
     * Added for public-beta readiness alongside `App\Support\CompanyInfo`
     * becoming settings-aware — see that class's own doc block. Placeholder
     * "Contoh"/"Jl. Contoh ..." values stay its fallback default until an
     * operator sets a real one here.
     */
    public const string KEY_COMPANY_NAME = 'company_name';

    public const string KEY_COMPANY_ADDRESS = 'company_address';

    /**
     * Nomor Induk Berusaha — Indonesia's single business registration
     * number (OSS). Unlike `KEY_COMPANY_NAME`/`KEY_COMPANY_ADDRESS`, this
     * has NO fictional placeholder fallback (see `App\Support\
     * CompanyInfo::nib()`): a made-up 13-digit pattern would look exactly
     * like a real registration number, unlike "PT Contoh ..." which
     * self-labels as fake. Empty means "not yet configured," and callers
     * must not render an NIB line at all in that case.
     */
    public const string KEY_COMPANY_NIB = 'company_nib';

    /**
     * H3 (`docs/superpowers/plans/2026-08-18-public-beta-release.md` Phase
     * 0) as an admin-editable field rather than a code deploy: empty means
     * the legal pages still show the honest "draf awal" disclaimer
     * (`App\Support\LegalReviewStatus`'s own doc block); a non-empty value
     * is treated as the operator's own review confirmation text (who
     * reviewed it and when) and replaces that disclaimer. This repository
     * cannot make the legal review happen, only remove the deploy
     * dependency once a human confirms it happened.
     */
    public const string KEY_LEGAL_REVIEW_NOTE = 'legal_review_note';

    public const array KNOWN_KEYS = [
        self::KEY_SERVICE_HOURS,
        self::KEY_SUPPORT_PHONE,
        self::KEY_SUPPORT_WHATSAPP,
        self::KEY_SUPPORT_EMAIL,
        self::KEY_MARKETPLACE_BADAN_USAHA_REF,
        self::KEY_PAYMENT_MERCHANT_REF,
        self::KEY_PAYMENT_BADAN_USAHA_REF,
        self::KEY_COMPANY_NAME,
        self::KEY_COMPANY_ADDRESS,
        self::KEY_COMPANY_NIB,
        self::KEY_LEGAL_REVIEW_NOTE,
    ];

    protected $table = 'site_settings';

    protected $fillable = ['key', 'value', 'updated_by_ref'];

    public static function valueFor(string $key): ?string
    {
        $row = self::query()->where('key', $key)->first();

        return $row?->value;
    }
}
