<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * The provider event types ADR-0033 §Decision names:
 * "`payment.completed` / `payment.failed` / `payment.expired` / `payment.test`
 * delivered to the configured webhook URL".
 *
 * Only `payment.completed` is a SETTLING event — the one that would move money
 * on the domain side. That distinction is not cosmetic: it is what the
 * `provider_events` secondary unique guard is scoped to (see the migration's
 * doc block for why the guard cannot be applied to every row without breaking
 * the out-of-order delivery Task 4 must handle).
 *
 * An unrecognised `event_type` is NOT rejected here — a provider is free to add
 * event types, and refusing to persist an unknown one would lose the durable
 * record AC5 requires. It is persisted, validated, and left for Task 4's apply
 * step to ignore.
 */
enum ProviderEventType: string
{
    case Completed = 'payment.completed';
    case Failed = 'payment.failed';
    case Expired = 'payment.expired';
    case Test = 'payment.test';

    /**
     * Event types that would settle an invoice, and are therefore covered by
     * the `(provider, provider_transaction_id, invoice_reference)` guard.
     *
     * @return list<string>
     */
    public static function settlingValues(): array
    {
        return [self::Completed->value];
    }

    public function isSettling(): bool
    {
        return in_array($this->value, self::settlingValues(), true);
    }
}
