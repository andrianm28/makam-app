<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\NotificationSubjectSource;
use Illuminate\Support\Facades\DB;

/**
 * PROVISIONAL — task-3-brief.md D3. The map below has exactly ONE entry
 * today: `booking_draft`. This is the exact construction
 * `RecipientResolutionSubject`'s own class doc block gives as its worked
 * example (`new RecipientResolutionSubject(ownerRef: $draft->user_id,
 * scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId:
 * $draft->cemetery_id)`) and names as "Task 3's job" to wire — this class
 * is that wiring.
 *
 * ---------------------------------------------------------------------------
 * Query builder on the table name, never an `app/Domain/**` Eloquent model
 * ---------------------------------------------------------------------------
 * Reads `booking_drafts` via `DB::table('booking_drafts')`, not
 * `App\Domain\Booking\Models\BookingDraft`. `app/Platform/README.md` tiers
 * `Notification` as a Tier 2 platform foundation with the dependency rule
 * running the other way — "a feature module consumes a platform foundation
 * and must never redefine one" — so a platform foundation importing an
 * `app/Domain/**` model would invert that rule. Task 2 of this lane was
 * reviewed clean specifically on this point (`RecipientResolutionSubject`'s
 * own doc block makes the same argument for why `RecipientResolver` never
 * accepts a domain model directly); this class does not regress it.
 *
 * ---------------------------------------------------------------------------
 * Partially live — read before assuming full end-to-end coverage
 * ---------------------------------------------------------------------------
 * Of the 6 outbox-mapped matrix events
 * (`2026_08_09_100020_seed_notification_templates_from_matrix.php`'s
 * `outboxEventName()`), ONE has a real producer + consumer pair today:
 * `order.status_changed.v1` is emitted by
 * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` and bridged by
 * `App\Domain\OrderWorkflow\Listeners\DispatchOrderNotifications`
 * (DIPROSES → "Order processing", SELESAI → "Order completed"). The other
 * five have no producer yet: `booking.draft_submitted.v2` needs the wizard's
 * Step 9 submission flow (the step screens exist since 13 Aug 2026, but the
 * submission action that emits the event is not wired),
 * `availability.*`/`quote.*`/`payment.received.v1` have no emitting module.
 * This class is therefore proven end-to-end only for the order-status path;
 * the other five events are exercised by tests that record the mapped event
 * onto the outbox directly
 * (`Outbox::record(eventName: 'booking.draft_submitted.v2', ...)`), not by
 * a real caller reaching them yet. Stated here plainly, per
 * task-3-brief.md D3, rather than implying end-to-end production coverage
 * that does not exist.
 *
 * ---------------------------------------------------------------------------
 * Failure mode
 * ---------------------------------------------------------------------------
 * An unmapped `$aggregateType`, or a `booking_draft` id with no matching
 * row, both return `null` — never throw. `Actions\DispatchNotification`
 * treats `null` as "resolve zero recipients, still record the
 * `notification_events` row," logging a `warning` with the aggregate
 * reference only (never payload content).
 */
final class ProvisionalAggregateNotificationSubjectSource implements NotificationSubjectSource
{
    public function subjectFor(string $aggregateType, int|string $aggregateId): ?RecipientResolutionSubject
    {
        return match ($aggregateType) {
            'booking_draft' => $this->bookingDraftSubject((string) $aggregateId),
            default => null,
        };
    }

    private function bookingDraftSubject(string $draftId): ?RecipientResolutionSubject
    {
        $row = DB::table('booking_drafts')->where('id', $draftId)->first();

        if ($row === null) {
            return null;
        }

        return new RecipientResolutionSubject(
            ownerRef: $row->user_id,
            scopeEntityType: $row->cemetery_id !== null ? ScopeEntityType::CEMETERY : null,
            scopeEntityId: $row->cemetery_id,
        );
    }
}
