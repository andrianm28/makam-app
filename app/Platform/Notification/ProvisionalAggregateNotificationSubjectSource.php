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
 * Correct-but-dormant in production — read before assuming live coverage
 * ---------------------------------------------------------------------------
 * Every one of the 6 outbox-mapped matrix events
 * (`2026_08_09_100020_seed_notification_templates_from_matrix.php`'s
 * `outboxEventName()`) currently has NO producer in this codebase:
 * `booking.draft_submitted.v2` needs wizard Step 9, and
 * `App\Domain\Booking\BookingWizardStep::LAST_IMPLEMENTED` is 5;
 * `availability.*`/`quote.*`/`payment.received.v1` have no domain module at
 * all (`app/Domain/OrderWorkflow` and `app/Domain/FuneralCase` are empty
 * scaffolding). This class — and the whole dispatch pipeline it feeds — is
 * therefore correct but dormant in production today: it is proven only by
 * tests that record a mapped event onto the outbox directly
 * (`Outbox::record(eventName: 'booking.draft_submitted.v2', ...)`), not by
 * any real caller in this codebase reaching it yet. Stated here plainly,
 * per task-3-brief.md D3, rather than implying end-to-end production
 * coverage that does not exist.
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
