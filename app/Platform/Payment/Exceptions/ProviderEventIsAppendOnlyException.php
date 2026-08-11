<?php

declare(strict_types=1);

namespace App\Platform\Payment\Exceptions;

use RuntimeException;

/**
 * Thrown by `Models\ProviderEvent` when something tries to change or delete a
 * durably received webhook in a way design.md §Data forbids: "`provider_events`
 * is append-only and is the replay source of truth."
 *
 * Same shape and same honesty caveat as
 * `PaymentIntentIsImmutableException`: this guards the Eloquent path only.
 */
final class ProviderEventIsAppendOnlyException extends RuntimeException
{
    public static function forOperation(string $operation): self
    {
        return new self(
            "provider_events is append-only; [{$operation}] is not permitted on a received webhook."
        );
    }

    /**
     * @param  list<string>  $columns
     */
    public static function forImmutableColumns(array $columns): self
    {
        return new self(
            'provider_events is append-only; these columns cannot be changed after receipt: '
            .implode(', ', $columns).'.'
        );
    }
}
