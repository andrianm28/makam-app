<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

/**
 * The outbound request shape for ADR-0033's `POST /api/v1/payments`.
 *
 * Money is integer minor units (`amountMinor`) — never float, per the plan's
 * global constraint and `AGENTS.md` §Domain and financial invariants.
 *
 * @param  string  $orderId  the merchant's own order reference (`order_id`)
 * @param  int  $amountMinor  the amount to collect, in the currency's minor unit
 * @param  string  $currency  ISO-4217 code; the provider authority names IDR
 * @param  string|null  $successReturnUrl  hosted-checkout success return URL
 * @param  string|null  $cancelReturnUrl  hosted-checkout cancel return URL
 * @param  int|null  $expiresInHours  ADR-0033: default 24, max 24
 */
final readonly class CreatePaymentRequest
{
    public function __construct(
        public string $orderId,
        public int $amountMinor,
        public string $currency = 'IDR',
        public ?string $successReturnUrl = null,
        public ?string $cancelReturnUrl = null,
        public ?int $expiresInHours = null,
    ) {}
}
