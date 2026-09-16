<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

/**
 * What a provider needs to reverse a collection it already made.
 *
 * No provider implements this today — see `Contracts\PaymentCheckoutClient
 * ::refund()` for the confirmed reason and for why the seam exists anyway.
 * The shape is therefore deliberately the SMALLEST thing a refund can be
 * asked with, not a guess at a particular provider's payload: the payment
 * being reversed, how much of it, and why.
 *
 * Amounts are in the module's INTERNAL unit — integer minor units (sen),
 * Wave 0 ruling 0c — exactly like `PaymentCheckoutResult`. A provider whose
 * wire unit is whole rupiah converts at its own client boundary, the way
 * `SumoPodPaymentClient` already does in both directions for `createPayment`.
 * No float enters this class.
 *
 * `amountMinor` is explicit rather than implied to be "all of it" because a
 * partial refund is a real case the refund plan's ledger already supports:
 * `refund_obligations.amount_minor` is its own column, not a copy of the
 * order total, precisely so a partial debt can be recorded.
 *
 * @param  string  $providerPaymentId  the provider's own id for the ORIGINAL payment
 * @param  int  $amountMinor  how much to reverse, in minor units; may be less than the original
 * @param  string  $reason  operator-supplied justification, carried for the provider's audit trail
 */
final readonly class RefundPaymentRequest
{
    public function __construct(
        public string $providerPaymentId,
        public int $amountMinor,
        public string $reason,
    ) {}
}
