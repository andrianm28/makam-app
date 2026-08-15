<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

use Carbon\CarbonImmutable;

/**
 * What a successful `createPayment` call returns: the provider's own
 * payment id, the echoed order reference, the amount breakdown, and the
 * hosted-checkout link the customer is sent to.
 *
 * Amounts are in the module's INTERNAL unit — integer minor units (sen),
 * Wave 0 ruling 0c — after conversion at the client boundary: the provider's
 * wire values are whole rupiah (`SumoPodPaymentClient`'s class doc block
 * records the convention and the exact conversion in both directions).
 * Reconciliation (`platform-financial-ledger` AC10) therefore compares these
 * minor-unit values against the provider's settlement records via the same
 * conversion `WebhookEnvelope` applies on the inbound side; no float ever
 * enters this class.
 *
 * @param  string  $paymentId  provider-side `payment_id` (uuid)
 * @param  string  $orderId  echoed `order_id`
 * @param  int  $amountMinor  the provider's whole-rupiah amount, in minor units
 * @param  int  $feeMinor  the provider's whole-rupiah fee, in minor units
 * @param  int  $netAmountMinor  the provider's whole-rupiah net amount, in minor units
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
