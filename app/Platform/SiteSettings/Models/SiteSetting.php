<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings\Models;

use Illuminate\Database\Eloquent\Model;

final class SiteSetting extends Model
{
    public const string KEY_SERVICE_HOURS = 'service_hours';

    public const string KEY_SUPPORT_PHONE = 'support_phone';

    public const string KEY_SUPPORT_WHATSAPP = 'support_whatsapp';

    public const string KEY_SUPPORT_EMAIL = 'support_email';

    public const string KEY_MARKETPLACE_BADAN_USAHA_REF = 'marketplace_badan_usaha_ref';

    public const string KEY_PAYMENT_MERCHANT_REF = 'payment_merchant_ref';

    public const string KEY_PAYMENT_BADAN_USAHA_REF = 'payment_badan_usaha_ref';

    public const array KNOWN_KEYS = [
        self::KEY_SERVICE_HOURS,
        self::KEY_SUPPORT_PHONE,
        self::KEY_SUPPORT_WHATSAPP,
        self::KEY_SUPPORT_EMAIL,
        self::KEY_MARKETPLACE_BADAN_USAHA_REF,
        self::KEY_PAYMENT_MERCHANT_REF,
        self::KEY_PAYMENT_BADAN_USAHA_REF,
    ];

    protected $table = 'site_settings';

    protected $fillable = ['key', 'value', 'updated_by_ref'];

    public static function valueFor(string $key): ?string
    {
        $row = self::query()->where('key', $key)->first();

        return $row?->value;
    }
}
