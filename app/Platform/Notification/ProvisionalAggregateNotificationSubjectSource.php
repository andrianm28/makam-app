<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\NotificationSubjectSource;
use Illuminate\Support\Facades\DB;

/**
 * PROVISIONAL — task-3-brief.md D3. This is the exact construction
 * `RecipientResolutionSubject`'s own class doc block gives as its worked
 * example (`new RecipientResolutionSubject(ownerRef: $draft->user_id,
 * scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId:
 * $draft->cemetery_id)`) and names as "Task 3's job" to wire — this class
 * is that wiring.
 *
 * ---------------------------------------------------------------------------
 * `order` and `quote` — added 18 Aug 2026, public-beta readiness
 * ---------------------------------------------------------------------------
 * Until this addition the map had exactly one entry (`booking_draft`), and
 * every one of the four outbox events with real production traffic —
 * `order.status_changed.v1` and `payment.received.v1` (`aggregate_type` =
 * `order`), `quote.issued.v1` and `quote.accepted.v1` (`aggregate_type` =
 * `quote`) — fell through the `default => null` arm. `Actions\
 * DispatchNotification::recordRecipientsAndDeliveries()` treats a `null`
 * subject as "resolve zero recipients," so none of the events actually
 * carrying real orders ever notified anyone at all — not a customer, not
 * staff — even once the outbox itself was being drained
 * (`Console\Commands\OutboxPublishCommand`). Verified against the dev
 * database before this change: 116 combined `order`/`quote` events
 * recorded, 0 ever resolving a recipient.
 *
 * Both resolve through the SAME order: a `quote` belongs to exactly one
 * order (`quotes.order_id`), so `quoteSubject()` reads the quote only far
 * enough to find its `order_id`, then defers entirely to `orderSubject()` —
 * there is one owner/scope derivation for "this order," not two.
 *
 * Owner reference: `order_parties` (role `PEMESAN`) is the only reference
 * to a customer's contact details this codebase has — there is no customer
 * account area, so `order_parties.user_id` is null for every order today.
 * `ownerRef` therefore widens beyond its doc block's literal "matches
 * `scope_assignments.actor_identifier`'s shape" for this one caller: when
 * the ordering party has no `user_id` but DOES have a `contact_email`
 * (carried from Step 6 by `SubmitBookingDraft`), `ownerRef` carries
 * `self::GUEST_ORDER_PARTY_PREFIX . $party->id` instead. This is safe
 * because `RecipientResolver` and `RecipientResolutionSubject` never
 * interpret `ownerRef`'s content — they only ever test it for null and
 * hand it through opaquely to `Recipient::actorRef`
 * (`RecipientResolver::resolve()`'s customer branch, ruling 5). The only
 * code that must understand the prefixed shape is
 * `Contracts\RecipientAddressResolver`'s implementation, which is exactly
 * where that understanding belongs — resolving an opaque recipient
 * reference to a real address is its whole job. An ordering party with
 * NEITHER a `user_id` NOR a `contact_email` (a draft that never reached
 * Step 6) yields `ownerRef: null`, identically to an anonymous booking
 * draft today — there is nothing to notify.
 *
 * Scope entity: an order's cemetery is one hop further than a draft's —
 * `orders.booking_draft_id` -> `booking_drafts.cemetery_id`. An order with
 * no `booking_draft_id` (a Pre-Need or Funeral-Case-only submission with no
 * backing draft row) has no scope entity, exactly like a draft with no
 * cemetery selected yet (`hasScopeEntity() === false`) — never an error.
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
    /**
     * Marks an `ownerRef` as an `order_parties.id`, not a `users.id` — see
     * this class's own doc block for why that distinction exists and why
     * only `Contracts\RecipientAddressResolver` needs to understand it.
     */
    public const string GUEST_ORDER_PARTY_PREFIX = 'guest_order_party:';

    public function subjectFor(string $aggregateType, int|string $aggregateId): ?RecipientResolutionSubject
    {
        return match ($aggregateType) {
            'booking_draft' => $this->bookingDraftSubject((string) $aggregateId),
            'order' => $this->orderSubject((string) $aggregateId),
            'quote' => $this->quoteSubject((string) $aggregateId),
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

    private function orderSubject(string $orderId): ?RecipientResolutionSubject
    {
        $order = DB::table('orders')->where('id', $orderId)->first();

        if ($order === null) {
            return null;
        }

        $cemeteryId = $order->booking_draft_id !== null
            ? DB::table('booking_drafts')->where('id', $order->booking_draft_id)->value('cemetery_id')
            : null;

        $party = DB::table('order_parties')
            ->where('order_id', $orderId)
            ->where('role', 'PEMESAN')
            ->first();

        return new RecipientResolutionSubject(
            ownerRef: $this->ownerRefForParty($party),
            scopeEntityType: $cemeteryId !== null ? ScopeEntityType::CEMETERY : null,
            scopeEntityId: $cemeteryId,
        );
    }

    private function quoteSubject(string $quoteId): ?RecipientResolutionSubject
    {
        $orderId = DB::table('quotes')->where('id', $quoteId)->value('order_id');

        if ($orderId === null) {
            return null;
        }

        return $this->orderSubject((string) $orderId);
    }

    /**
     * `null` (no party row, or a party with neither a `user_id` nor a
     * `contact_email` — nothing to notify), the party's `user_id` (an
     * authenticated ordering customer, matching `ownerRef`'s documented
     * shape unchanged), or `GUEST_ORDER_PARTY_PREFIX` . the party's own id
     * (an anonymous customer whose only reference is the order party row
     * carrying their Step 6 contact details).
     */
    private function ownerRefForParty(?object $party): int|string|null
    {
        if ($party === null) {
            return null;
        }

        if ($party->user_id !== null) {
            return $party->user_id;
        }

        if ($party->contact_email !== null) {
            return self::GUEST_ORDER_PARTY_PREFIX.$party->id;
        }

        return null;
    }
}
