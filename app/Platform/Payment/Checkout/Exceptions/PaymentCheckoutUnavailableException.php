<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout\Exceptions;

use RuntimeException;

/**
 * Thrown when online checkout cannot even be attempted because this
 * environment is not provisioned for it.
 *
 * ADR-0033: the credential (`SUMODOP_SANDBOX_API_KEY`) is protected-injected
 * into the dev host only, and `config/payment.php`'s rule is "a missing
 * credential must fail closed, never fall back to a value baked into the
 * repository." `SumoPodPaymentClient` enforces that before the first HTTP
 * request: an environment that has not been given a key (or a provider URL)
 * gets a refusal, never a network call.
 *
 * This is the same posture as `PaymentSessionCreationUnavailableException` —
 * the module prefers a loud refusal over a silently broken money path — and
 * callers treat it as "use the mandatory manual fallback".
 */
final class PaymentCheckoutUnavailableException extends RuntimeException
{
    public static function becauseApiKeyIsUnset(): self
    {
        return new self(
            'Online payment checkout is unavailable: SUMODOP_SANDBOX_API_KEY is not configured '
            .'for this environment. The checkout client fails closed; no provider request was made.'
        );
    }

    public static function becauseProviderUrlIsUnset(): self
    {
        return new self(
            'Online payment checkout is unavailable: no provider URL is configured for the active '
            .'payment provider. The checkout client fails closed; no provider request was made.'
        );
    }
}
