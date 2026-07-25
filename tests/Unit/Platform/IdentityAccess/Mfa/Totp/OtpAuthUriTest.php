<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Mfa\Totp;

use App\Platform\IdentityAccess\Mfa\Totp\OtpAuthUri;
use Tests\TestCase;

/**
 * `OtpAuthUri::build()` — the `otpauth://` string shape a future QR
 * renderer would consume. No QR rendering happens in this module; this
 * only asserts the URI's own shape and required query parameters.
 */
final class OtpAuthUriTest extends TestCase
{
    public function test_uri_has_the_expected_scheme_label_and_query_parameters(): void
    {
        $uri = OtpAuthUri::build('Makam.co.id', 'user@example.com', 'JBSWY3DPEHPK3PXP', 6, 30);

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Makam.co.id', $uri);
        $this->assertStringContainsString('algorithm=SHA1', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    public function test_account_label_is_percent_encoded(): void
    {
        $uri = OtpAuthUri::build('Makam.co.id', 'user@example.com', 'JBSWY3DPEHPK3PXP');

        $this->assertStringContainsString(rawurlencode('user@example.com'), $uri);
    }

    public function test_uri_never_contains_a_raw_secret_placeholder_other_than_the_provided_base32_value(): void
    {
        // Documents intent: this builder only ever receives an already
        // Base32-encoded secret — it never accepts or derives one from raw
        // key bytes, so there is no path here that could leak
        // non-Base32/raw binary into a URI.
        $uri = OtpAuthUri::build('Makam.co.id', 'user@example.com', 'JBSWY3DPEHPK3PXP');

        $this->assertMatchesRegularExpression('/secret=[A-Z2-7]+/', $uri);
    }
}
