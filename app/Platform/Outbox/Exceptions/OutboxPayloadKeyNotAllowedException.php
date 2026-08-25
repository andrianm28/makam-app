<?php

declare(strict_types=1);

namespace App\Platform\Outbox\Exceptions;

use RuntimeException;

/**
 * AC7: thrown by `PayloadClassification::assertSafe()` when an event's
 * `data` payload contains a key on `PayloadClassification::DENYLISTED_KEYS`,
 * at any nesting depth.
 */
final class OutboxPayloadKeyNotAllowedException extends RuntimeException
{
    public static function forKey(string $path): self
    {
        return new self(
            "Outbox event payload key [{$path}] is on the restricted-data denylist. ".
            'See App\Platform\Outbox\PayloadClassification::DENYLISTED_KEYS.'
        );
    }
}
