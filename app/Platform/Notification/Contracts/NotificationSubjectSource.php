<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

use App\Platform\Notification\RecipientResolutionSubject;

/**
 * task-3-brief.md D3: the swappable seam that resolves a
 * `RecipientResolutionSubject` from an outbox envelope's aggregate
 * reference — the piece `RecipientResolutionSubject`'s own class doc block
 * names as "Task 3's job," because the envelope itself cannot supply owner
 * or scope (outbox producers are references-only by contract; see
 * `App\Domain\Booking\Actions\StartBookingDraft`'s own comment: "`user_id`
 * is deliberately absent").
 *
 * Mirrors the shape ruling 2 established for `RecipientRoleSource`: never
 * consumed directly by name, always through this contract, so the
 * provisional implementation (`ProvisionalAggregateNotificationSubjectSource`)
 * can be replaced later without touching
 * `Actions\DispatchNotification`.
 */
interface NotificationSubjectSource
{
    /**
     * `null` for any aggregate type this source cannot map, or when the
     * referenced row no longer exists. MUST NOT throw for either case —
     * `Actions\DispatchNotification::consumeOutboxEvent()` treats `null` as
     * "record the event, resolve zero recipients," never as an error.
     */
    public function subjectFor(string $aggregateType, int|string $aggregateId): ?RecipientResolutionSubject;
}
