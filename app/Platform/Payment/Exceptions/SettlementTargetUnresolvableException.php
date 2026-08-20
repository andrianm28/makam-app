<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\ApplyPaymentSettlement` when a claimed settling provider
 * event cannot be mapped to the payment this system authorized.
 *
 * Two failures raise it:
 *
 *  1. No `payment_sessions` row carries `(provider, provider_transaction_id)`.
 *     The row's VALIDATED status is only reachable through `WebhookValidator`,
 *     which performs the same lookup, so this arm is defence in depth against
 *     an event seeded or replayed around the validator — the settlement never
 *     trusts the claim on its own.
 *  2. No target carries the event's invoice reference: the booking `Order`
 *     by `reference`, the `MarketplaceOrder` by `order_number`, or the
 *     `SubscriptionCycle` by its own id (see `Actions\ApplyPaymentSettlement`'s
 *     "Care subscription" doc section for why a cycle is keyed by id rather
 *     than a business reference column). The invoice reference is the
 *     provider's echo of the order id we sent at session opening, so an
 *     unresolvable one is a data-integrity anomaly that must fail loudly
 *     rather than settle nowhere.
 *
 * The exception propagates out of `ProcessWebhookEvent`'s claim transaction:
 * the claim rolls back, the row stays at VALIDATED, no effect is half-applied,
 * and the queue retry re-claims it. The retry is BOUNDED (`Jobs\ProcessProviderEventJob`
 * — `$tries`/`retryUntil`/`backoff`): a transient failure gets its retries,
 * and after the last attempt the job fails permanently into `failed_jobs` for
 * human recovery. A row that is genuinely unresolvable therefore ends as a
 * failed job with the row visibly still VALIDATED — never `PROCESSED` for
 * work that did not commit, never silently dropped.
 */
final class SettlementTargetUnresolvableException extends RuntimeException
{
    public static function becauseNoSession(string $provider, ?string $providerTransactionId): self
    {
        return new self(
            'Cannot settle: no payment session is bound to provider transaction '
            ."[{$providerTransactionId}] for provider [{$provider}]."
        );
    }

    public static function becauseNoOrder(string $invoiceReference): self
    {
        return new self(
            'Cannot settle: no booking order (by reference), marketplace order '
            .'(by order number), or subscription cycle (by id) carries invoice '
            ."reference [{$invoiceReference}]."
        );
    }
}
