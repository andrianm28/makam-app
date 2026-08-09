<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Ruling 5, `docs/superpowers/plans/2026-08-10-wave1a-notifications-
 * decisions.md`: a plain immutable value object wrapping the recipients
 * `RecipientResolver::resolve()` produced for one event. Deliberately
 * carries no behaviour beyond iteration/counting — dispatch (Task 3) reads
 * it, this lane does not act on it.
 *
 * @implements IteratorAggregate<int, Recipient>
 */
final class RecipientSet implements Countable, IteratorAggregate
{
    /**
     * @param  list<Recipient>  $recipients
     */
    public function __construct(private readonly array $recipients = []) {}

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->recipients === [];
    }

    public function count(): int
    {
        return count($this->recipients);
    }

    /**
     * @return list<Recipient>
     */
    public function all(): array
    {
        return $this->recipients;
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->recipients);
    }
}
