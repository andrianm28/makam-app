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
 *  2. No order carries the event's invoice reference (the booking `Order` by
 *     `reference`, or the `MarketplaceOrder` by `order_number`). The invoice
 *     reference is the provider's echo of the order id we sent at session
 *     opening, so an unresolvable one is a data-integrity anomaly that must
 *     fail loudly rather than settle nowhere.
 *
 * The exception propagates out of `ProcessWebhookEvent`'s claim transaction:
 * the claim rolls back, the row stays at VALIDATED, no effect is half-applied,
 * and the queue retry re-claims it. A permanently unresolvable event keeps
 * failing — visible in the queue and in the row's perpetually-VALIDATED
 * status — until a human intervenes; that is recorded as the intended
 * fail-closed behaviour, never a silent drop.
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
            'Cannot settle: no booking order (by reference) or marketplace order '
            ."(by order number) carries invoice reference [{$invoiceReference}]."
        );
    }
}
