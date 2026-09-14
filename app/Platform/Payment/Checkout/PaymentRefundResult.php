<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

/**
 * What a successful `refund` call would return.
 *
 * Nothing returns one today. This type exists so `PaymentCheckoutClient
 * ::refund()` has a return shape a future provider can satisfy without the
 * contract changing underneath every caller — see that method's doc block
 * for why the seam is declared before anyone can implement it.
 *
 * Three fields, and the reason each is here rather than a fuller guess:
 *
 * - `refundId` — the provider's own handle, which is what an operator quotes
 *   when they have to ask the provider what happened.
 * - `amountMinor` — what the provider says it reversed, in the module's
 *   internal minor units, NOT what we asked it to reverse. Those are
 *   different facts and a ledger that conflates them cannot detect a partial
 *   execution.
 * - `status` — a provider string, deliberately not an enum. Refunds are
 *   asynchronous at every provider researched for this platform, so the
 *   first honest value is "pending", and inventing a closed list before a
 *   single provider's vocabulary is known would be exactly the guess that
 *   got a reconciliation feature reverted from production once already
 *   (see `PaymentCheckoutClient`'s note on the absent `fetchStatus`).
 *
 * @param  string  $refundId  provider-side refund identifier
 * @param  int  $amountMinor  the amount the provider reports as reversed, in minor units
 * @param  string  $status  provider refund status, verbatim
 */
final readonly class PaymentRefundResult
{
    public function __construct(
        public string $refundId,
        public int $amountMinor,
        public string $status,
    ) {}
}
