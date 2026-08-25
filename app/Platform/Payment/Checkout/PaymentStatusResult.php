<?php

declare(strict_types=1);

namespace App\Platform\Payment\Checkout;

use Carbon\CarbonImmutable;

/**
 * What a successful `fetchStatus` call returns: the provider's own
 * authoritative record of one payment, at the moment of the call.
 *
 * ---------------------------------------------------------------------------
 * Why this exists — closing the single-point-of-failure a real incident
 * exposed
 * ---------------------------------------------------------------------------
 * Before this class, the ONLY way a payment ever settled on our side was an
 * inbound webhook (`ReceiveWebhook` -> `ProcessWebhookEvent` ->
 * `Actions\ApplyPaymentSettlement`). On 25 Aug 2026 a real sandbox payment
 * (order `MK-2026-BHXYGZCH`) completed on the provider's own side while the
 * provider's webhook URL was misconfigured to an unreachable domain — the
 * payment sat at `AWAITING_PAYMENT` indefinitely, discoverable only by a
 * human checking the provider's own dashboard. `fetchStatus()` is the
 * server-to-server reconciliation path that closes that gap:
 * `Actions\ReconcilePaymentSession` calls it to independently verify a
 * still-open session's real status, using OUR OWN authenticated API call —
 * never the customer's browser return URL (`AGENTS.md` §Domain and financial
 * invariants: "Never mark paid from browser return URL" stays intact; the
 * `payment_id` a return URL carries is used only as a lookup key into THIS
 * call, never as the evidence itself).
 *
 * Same unit convention as `PaymentCheckoutResult`: the provider's wire
 * values are whole rupiah; this class holds the module's internal integer
 * minor units (sen) after conversion at the client boundary (Wave 0 ruling
 * 0c — no float ever enters this class).
 *
 * @param  string  $paymentId  provider-side `payment_id` (uuid)
 * @param  string  $orderId  the provider's own record of the order/invoice
 *                           reference this payment settles — the SAME field
 *                           a real webhook would carry as `data.order_id`,
 *                           read here from the provider's own status record
 *                           instead of a webhook delivery.
 * @param  string  $status  provider payment status (`pending`, `completed`,
 *                          `failed`, `expired`, ... — provider-defined, not
 *                          a closed list this module owns)
 * @param  int  $amountMinor  the provider's whole-rupiah amount, in minor units
 * @param  int  $feeMinor  the provider's whole-rupiah fee, in minor units
 * @param  int  $netAmountMinor  the provider's whole-rupiah net amount, in minor units
 * @param  string|null  $paymentMethod  the method actually used, when the
 *                                      provider reports one (e.g. `qris`)
 * @param  CarbonImmutable|null  $completedAt  when the provider recorded
 *                                             completion, if it sent one
 */
final readonly class PaymentStatusResult
{
    public function __construct(
        public string $paymentId,
        public string $orderId,
        public string $status,
        public int $amountMinor,
        public int $feeMinor,
        public int $netAmountMinor,
        public ?string $paymentMethod,
        public ?CarbonImmutable $completedAt,
    ) {}
}
