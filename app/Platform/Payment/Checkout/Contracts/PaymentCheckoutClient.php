<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout\Contracts;

use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\PaymentCheckoutResult;

/**
 * The outbound payment-provider seam (ADR-0033).
 *
 * Exactly one implementation exists today (`SumoPodPaymentClient`); the
 * interface exists so a provider switch stays config-only, and so the
 * session-creation path (Task 4's `OpenPaymentSession`) can depend on the
 * seam rather than on SumoPod by name.
 *
 * Contract: a successful call returns the provider's hosted-checkout
 * coordinates. Failures are never silent — an unprovisioned environment
 * throws `PaymentCheckoutUnavailableException` before any HTTP request, and a
 * provider-side failure throws `PaymentCheckoutProviderException`. The caller
 * treats both as "online checkout cannot happen right now" and preserves the
 * mandatory manual fallback.
 */
interface PaymentCheckoutClient
{
    public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult;
}
