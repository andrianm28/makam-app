<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

/**
 * AC8: "route queues per the names and priorities in `queue-and-outbox.md`
 * ... SHALL NOT let `imports`, `media`, or `reports` starve `critical` or
 * `urgent`." `OutboxPublisher` calls `routeFor()` at DISPATCH time — it is
 * not a column on `outbox_events` (see that migration's own doc block:
 * `queue-and-outbox.md` §5's "minimum outbox schema" list has no `queue`
 * column either, and design.md's own publish-loop pseudocode already frames
 * this as "dispatch to queue per event_type routing" done by the publisher,
 * not stored at write time).
 *
 * ---------------------------------------------------------------------------
 * How this mapping was derived, and where a future event's queue
 * assignment should be declared
 * ---------------------------------------------------------------------------
 * `event-catalog.md` names each event's producer and consumers but never
 * its queue. `queue-and-outbox.md` §2's "Examples" column is the only place
 * in this spec's authority chain that ties a KIND of event to a queue in
 * prose, so `ROUTES` below contains only the entries with a clean, directly
 * quotable 1:1 match to that column:
 *
 *   - `payment.received.v1`         -> critical  ("payment webhook processing")
 *   - `funeral_case.task_overdue.v1` -> urgent    ("overdue escalation")
 *   - `grave.import_completed.v1`    -> imports   ("10,000-row grave import")
 *
 * Every other catalogue event name falls back to `DEFAULT_QUEUE`
 * (`default`) rather than guessing — deliberately incomplete, not silently
 * wrong. This is NOT a restatement of the event catalogue (AC3): it adds no
 * new event names, producers, consumers, or semantics, only an operational
 * routing decision layered on top of names the catalogue already owns.
 *
 * To route a future event explicitly, add one line to `ROUTES` below citing
 * the specific `queue-and-outbox.md` §2 text that justifies the queue
 * choice — the same evidentiary bar the three entries above were held to.
 * Do not add a case-by-case default guess; an unmapped event correctly
 * defaults to `default` until someone does this deliberately.
 */
final class OutboxQueueRouter
{
    /**
     * @var array<string, OutboxQueueName>
     */
    public const array ROUTES = [
        'payment.received.v1' => OutboxQueueName::Critical,
        'funeral_case.task_overdue.v1' => OutboxQueueName::Urgent,
        'grave.import_completed.v1' => OutboxQueueName::Imports,
    ];

    public const OutboxQueueName DEFAULT_QUEUE = OutboxQueueName::Default;

    public static function routeFor(string $eventName): OutboxQueueName
    {
        return self::ROUTES[$eventName] ?? self::DEFAULT_QUEUE;
    }
}
