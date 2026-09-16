<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout\Contracts;

use App\Platform\Payment\Checkout\CreatePaymentRequest;
use App\Platform\Payment\Checkout\Exceptions\PaymentRefundNotSupportedException;
use App\Platform\Payment\Checkout\PaymentCheckoutResult;
use App\Platform\Payment\Checkout\PaymentRefundResult;
use App\Platform\Payment\Checkout\RefundPaymentRequest;

/**
 * The outbound payment-provider seam (ADR-0033).
 *
 * Exactly one implementation exists today (`SumoPodPaymentClient`); the
 * interface exists so a provider switch stays config-only, and so the
 * session-creation path (Task 4's `OpenPaymentSession`) can depend on the
 * seam rather than on SumoPod by name.
 *
 * Contract: a successful `createPayment` call returns the provider's
 * hosted-checkout coordinates. Failures are never silent — an unprovisioned
 * environment throws `PaymentCheckoutUnavailableException` before any HTTP
 * request, and a provider-side failure throws
 * `PaymentCheckoutProviderException`. The caller treats both as "online
 * checkout cannot happen right now" and preserves the mandatory manual
 * fallback.
 *
 * No `fetchStatus` method: confirmed 25 Aug 2026 that SumoPod's Managed
 * Payment product has no status-lookup endpoint at all (directly confirmed
 * by the merchant, not merely undocumented) — a same-day reconciliation
 * feature built against a guessed endpoint path was reverted after it
 * returned real HTTP 404s in production. The webhook and the browser return
 * URL (never trusted for state, see `PaymentReturnController`'s doc block)
 * are the only two confirmation mechanisms this provider offers.
 */
interface PaymentCheckoutClient
{
    public function createPayment(CreatePaymentRequest $request): PaymentCheckoutResult;

    /**
     * Whether this provider can reverse a collection it already made.
     *
     * ASK THIS before reaching for `refund()`. It is the difference between
     * a caller that knows the capability is absent and a caller that finds
     * out by crashing.
     *
     * `false` for every implementation that exists today.
     */
    public function supportsRefund(): bool;

    /**
     * Reverse a collection, in full or in part.
     *
     * -----------------------------------------------------------------------
     * No implementation exists, and that is the POINT of declaring it
     * -----------------------------------------------------------------------
     * Confirmed by the product owner, 13-14 Sep 2026: SumoPod does not
     * support refunds, and the mechanism is narrower than "no refund
     * endpoint" — the provider supports **withdraw to the main account
     * only**. There is no path that returns money to its source and no path
     * that sends it to the customer directly. One refund is therefore two
     * manual movements, both outside this system:
     *
     *     SumoPod --withdraw--> our main account --transfer--> customer
     *
     * So `SumoPodPaymentClient::refund()` throws
     * `PaymentRefundNotSupportedException` — explicitly, before any request,
     * never a silent failure and never a `false` that reads as "declined".
     *
     * -----------------------------------------------------------------------
     * Why this is declared when `fetchStatus` was deliberately NOT
     * -----------------------------------------------------------------------
     * This class's own note above records that no `fetchStatus` method exists
     * because SumoPod has no status-lookup endpoint, and that a feature built
     * against a GUESSED endpoint was reverted after returning real 404s in
     * production. That precedent argues against declaring methods a provider
     * cannot serve, so the difference is stated rather than left for the next
     * reader to wonder about:
     *
     * `fetchStatus` had a working alternative — the webhook — so the absence
     * cost nothing and the method would have been pure speculation. Refund has
     * no alternative: the money genuinely must go back, and today it goes back
     * by a human doing two bank operations under a three-working-day deadline.
     * The seam is not speculation about a provider's API shape; it is the
     * place a provider switch plugs into, and the refund plan's entire R-series
     * is written around that switch being a real, planned event.
     *
     * And critically, nothing here GUESSES a wire format. `refund()` is
     * declared; no URL, no payload, and no response parsing is invented. The
     * thing that caused the production revert was a fabricated endpoint path,
     * not an unimplemented interface method.
     *
     * @throws PaymentRefundNotSupportedException when the provider cannot refund at all
     */
    public function refund(RefundPaymentRequest $request): PaymentRefundResult;
}
