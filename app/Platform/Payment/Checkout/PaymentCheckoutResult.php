<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

use Carbon\CarbonImmutable;

/**
 * What a successful `createPayment` call returns: the provider's own
 * payment id, the echoed order reference, the amount breakdown, and the
 * hosted-checkout link the customer is sent to.
 *
 * Amounts mirror the provider payload exactly (integer minor units), so
 * reconciliation (`platform-financial-ledger` AC10) can compare
 * `amountMinor`/`feeMinor`/`netAmountMinor` against provider settlement
 * records without any conversion.
 *
 * @param  string  $paymentId  provider-side `payment_id` (uuid)
 * @param  string  $orderId  echoed `order_id`
 * @param  int  $amountMinor  requested amount in minor units
 * @param  int  $feeMinor  provider fee in minor units
 * @param  int  $netAmountMinor  amount minus fee, in minor units
 * @param  string  $paymentLinkUrl  the hosted checkout URL to redirect to
 * @param  string  $status  provider payment status (sandbox answers `pending`)
 * @param  CarbonImmutable|null  $expiresAt  when the payment link expires, if the provider sent one
 */
final readonly class PaymentCheckoutResult
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public int $amountMinor,
        public int $feeMinor,
        public int $netAmountMinor,
        public string $paymentLinkUrl,
        public string $status,
        public ?CarbonImmutable $expiresAt,
    ) {}
}
