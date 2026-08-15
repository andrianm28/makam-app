<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Platform\Payment\OrderType;

/**
 * Everything `OpenPaymentSession` needs to open one hosted-checkout session.
 *
 * Money is integer minor units (`amountMinor`) — never float, per the plan's
 * global constraint and `AGENTS.md` §Domain and financial invariants; the
 * guard's condition 5 verifies it against the current quote total.
 *
 * `merchantRef` is the merchant the caller claims to open under. It is NOT
 * trusted: `OpenPaymentSession` fails closed unless it equals the
 * config-bound merchant of record (`config('payment.merchant_ref')`, the
 * FIN-DEC-01 provisioning channel) — a session must never open under a
 * merchant this deployment does not serve (AC13's binding, enforced at
 * creation the way `WebhookValidator` re-enforces it at webhook time).
 *
 * @param  OrderType  $orderType  which downstream aggregate the session settles
 * @param  string  $orderRef  the aggregate's reference (`orders.reference`
 *                            today; a `MarketplaceOrder` reference once the
 *                            marketplace path lands)
 * @param  int  $amountMinor  the amount to collect, in the currency's minor unit
 * @param  string  $merchantRef  the merchant to bind the session to — must
 *                               equal `config('payment.merchant_ref')`
 * @param  string|null  $successReturnUrl  hosted-checkout success return URL
 * @param  string|null  $cancelReturnUrl  hosted-checkout cancel return URL
 */
final readonly class OpenPaymentSessionCommand
{
    public function __construct(
        public OrderType $orderType,
        public string $orderRef,
        public int $amountMinor,
        public string $merchantRef,
        public ?string $successReturnUrl = null,
        public ?string $cancelReturnUrl = null,
    ) {}
}
