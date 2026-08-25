<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `OpenPaymentSession` when the command claims a `merchantRef`
 * that is not the merchant this deployment is bound to
 * (`config('payment.merchant_ref')`, the FIN-DEC-01 provisioning channel).
 *
 * AC13 requires every session to be bound to the merchant/badan-usaha pair,
 * and `WebhookValidator` re-enforces it at webhook time (`REJECTED_MERCHANT`).
 * Letting a caller open a session under a merchant the deployment does not
 * serve would create a session whose webhook can never validate — a payment
 * that could not be recognized. Failing closed here keeps that from ever
 * being written.
 */
final class PaymentSessionMerchantMismatchException extends RuntimeException
{
    public static function forRefs(string $claimedMerchant, string $boundMerchant): self
    {
        return new self(
            "Cannot open a payment session under merchant [{$claimedMerchant}]: this deployment "
            ."is bound to merchant [{$boundMerchant}]. A session may only open under the bound merchant."
        );
    }
}
