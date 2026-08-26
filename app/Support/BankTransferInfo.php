<?php

declare(strict_types=1);

namespace App\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;

/**
 * The ONE place this codebase resolves the manual bank-transfer destination
 * shown on the booking wizard's Step 8 "Pembayaran Manual" fallback card
 * (`App\Livewire\Public\Booking\BookingWizard`, rendered when `G-PAY-01` is
 * closed or as recovery when an online attempt fails).
 *
 * Same honest-null discipline `App\Support\CompanyInfo::nib()` already
 * established for this codebase: there is NO fictional placeholder fallback
 * here, unlike `ContactInfo`'s phone/email placeholders or `CompanyInfo`'s
 * "PT Contoh ..." name. A made-up bank name and account number would look
 * exactly like a real destination account and could mislead a customer into
 * transferring money nowhere useful — so every accessor returns null until
 * an operator enters a real value via the admin Site Settings page
 * (`/admin/pengaturan-situs`), and `isConfigured()` is false until all
 * three fields are set. Callers must not render a partial destination (bank
 * name with no account number, etc.) — treat the three as one unit.
 */
final class BankTransferInfo
{
    public static function bankName(): ?string
    {
        return self::resolve(SiteSetting::KEY_BANK_TRANSFER_BANK_NAME);
    }

    public static function accountNumber(): ?string
    {
        return self::resolve(SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_NUMBER);
    }

    public static function accountHolder(): ?string
    {
        return self::resolve(SiteSetting::KEY_BANK_TRANSFER_ACCOUNT_HOLDER);
    }

    /**
     * True only once ALL THREE fields have a real, non-empty value. A
     * partial configuration (e.g. bank name set but no account number) is
     * treated as "not configured" — it would be actively unusable/confusing
     * to show a payer a bank name with no account number to send to.
     */
    public static function isConfigured(): bool
    {
        return self::bankName() !== null
            && self::accountNumber() !== null
            && self::accountHolder() !== null;
    }

    private static function resolve(string $key): ?string
    {
        $value = trim((string) app(SettingsService::class)->setting($key, ''));

        return $value === '' ? null : $value;
    }
}
