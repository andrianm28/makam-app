<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Totp;

/**
 * Builds the `otpauth://totp/...` URI a future enrolment UI would encode as
 * a QR code (Google Authenticator's de facto key-uri-format, widely
 * supported by other authenticator apps). This module does NOT render a QR
 * code or build any UI — that is explicitly out of this batch's scope; this
 * is just the string a QR library would consume.
 */
final class OtpAuthUri
{
    public static function build(
        string $issuer,
        string $accountLabel,
        string $base32Secret,
        int $digits = 6,
        int $period = 30,
    ): string {
        // `Issuer:account` label, each segment percent-encoded per the key
        // uri format's own convention (e.g. spaces in "Makam.co.id" or an
        // email-shaped account label must not break the URI).
        $label = rawurlencode($issuer).':'.rawurlencode($accountLabel);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            // Base32's own alphabet (A-Z, 2-7, '=') is already URL-safe —
            // no additional percent-encoding needed or applied.
            $base32Secret,
            rawurlencode($issuer),
            $digits,
            $period,
        );
    }
}
