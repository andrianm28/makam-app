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
 * `renewal` — added 25 Aug 2026, closing the last gap this class's own
 * "Partially live" section named
 * ---------------------------------------------------------------------------
 * `renewal.submitted.v1` (`Domain\Renewal\Actions\OpenRenewal`) and
 * `renewal.paid_online.v1` (`Domain\Renewal\Actions\MarkRenewalPaidOnline`)
 * both record `aggregate_type = 'renewal'`, which fell through this match's
 * `default => null` arm exactly like `order`/`quote` did before 18 Aug
 * 2026 — every renewal notification event was recorded onto
 * `notification_events` with zero recipients resolved, ever.
 *
 * Owner reference: unlike `booking_draft`/`order`, a renewal genuinely has
 * NO owner concept anywhere in this codebase to fall back on, guest or
 * otherwise. `renewals` (`2026_08_12_100000_create_renewals_table.php`)
 * carries only `grave_record_id`; `grave_records` carries no `user_id`, no
 * `contact_email`, no `contact_phone` — its own model doc block notes that
 * `heir_contact_reference` "has no write path anywhere" and is deliberately
 * excluded from both `$fillable` and every `GraveRecordProjection` shape.
 * Nothing else in the renewal journey (`RenewalQuote`,
 * `RenewalExternalMarking`, the online payment session) captures a contact
 * either. `renewalSubject()` therefore always returns `ownerRef: null` — not
 * a bug in this wiring, a true statement about what the renewal journey
 * collects today. The Customer column reads `EMAIL/WA` for both matrix rows
 * (`docs/contracts/notification-matrix.md`), so that column stays
 * unreachable in practice until a future change gives a renewal an actual
 * contact to notify; that is separate product/engineering work, not this
 * fix's scope.
 *
 * Scope entity: a renewal's cemetery is one hop further than a draft's —
 * `renewals.grave_record_id` -> `grave_records.cemetery_id`, which is
 * NOT NULL on every `grave_records` row
 * (`2026_08_08_100000_create_grave_records_table.php`), so
 * `hasScopeEntity()` is always `true` for a renewal with a real grave
 * record. This is the part of the fix that is NOT a no-op: it makes the
 * "Pengelola TPU/TPS" column (`IN_APP/EMAIL` for Renewal submitted,
 * `IN_APP` for Renewal paid/verified) resolve real cemetery-operator
 * recipients via `ScopeAssignmentResolver::actorsForEntity()`, where today
 * it resolves none.
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
 * `vendor_order` and `marketplace_order` — added 6 Sep 2026, Batch 2E
 * (NOTIF-02)
 * ---------------------------------------------------------------------------
 * Investigated all four remaining unmapped aggregate types this class's
 * `default => null` arm was silently swallowing
 * (`docs/superpowers/plans/2026-09-06-batch2e-notification-recipients-templates.md`
 * has the full table). Only two had a contact reachable within 1-2 hops:
 *
 * `vendor_order` (`vendor_order.decided.v1`, `vendor_order.complaint_filed.v1`,
 * emitted by `Domain\Marketplace\Actions\UpdateVendorOrderStatus`) — the
 * `vendor_orders` table carries inline, NOT NULL
 * `customer_name`/`customer_phone`/`customer_email` (zero hops) and a NOT
 * NULL `vendor_id` FK (zero hops) for the matrix's "Vendor" column.
 *
 * `marketplace_order` (`marketplace_order.submitted.v1`, emitted by
 * `Domain\Marketplace\Actions\PlaceMarketplaceOrder`) — `marketplace_orders.
 * customer_ref` is NOT NULL, but its content is one of two shapes depending
 * on `Checkout::placeOrder()`'s own ternary: an authenticated customer's
 * `(string) auth()->id()` (a digit string, resolvable), or, for a guest
 * checkout, `session()->getId()` — a PHP session id, NOT a `users.id`, and
 * NOT safely comparable to one (`users.id` is `bigint`; comparing it
 * against an arbitrary session-id string would raise a database error, not
 * merely fail to match). `marketplaceOrderSubject()` below distinguishes
 * the two with `ctype_digit()` (the same test `MarketplaceOrderInfolist`
 * already applies to this exact ambiguity for its own, unrelated display
 * purpose) and resolves `ownerRef: null` for the guest shape — identically
 * to an anonymous `booking_draft`. `vendor_id` is NOT NULL,
 * zero hops, for the "Vendor" column.
 *
 * `payment_session` and `work_evidence` were investigated and deliberately
 * left OUT of this fix — `payment_sessions` carries no customer/order
 * reference at all and ships empty by design (its own migration doc block:
 * "ships EMPTY, on purpose, and stays empty"), and `work_evidence`'s only
 * path toward a customer is 3+ hops through a nullable
 * `work_orders.subscription_cycle_id`. Both need a schema/design decision,
 * not a recipient-resolution wiring fix; see the derived plan doc above for
 * the full reasoning. Also verified per that plan: `vendor.evidence_
 * uploaded.v1` is emitted by `Domain\VendorFulfillment\Actions\
 * UploadEvidence` with `aggregateType: 'work_evidence'` — a different
 * domain from the `vendor_orders` table this fix DOES touch; the shared
 * "vendor" wording is coincidental (the seed migration's own doc block
 * already says so). This fix does not touch `work_evidence` or
 * `UploadEvidence` at all.
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

    /**
     * Marks an `ownerRef` as a `vendor_orders.id`, not a `users.id` — see
     * this class's own doc block's `vendor_order`/`marketplace_order`
     * section. Unlike `GUEST_ORDER_PARTY_PREFIX`, there is no separate
     * party row: `vendor_orders.customer_email` is inline on the same row
     * `vendor_orders.id` already identifies, so
     * `Contracts\RecipientAddressResolver`'s implementation re-reads the
     * same table by the same id rather than a second one.
     */
    public const string VENDOR_ORDER_CUSTOMER_PREFIX = 'vendor_order_customer:';

    public function subjectFor(string $aggregateType, int|string $aggregateId): ?RecipientResolutionSubject
    {
        return match ($aggregateType) {
            'booking_draft' => $this->bookingDraftSubject((string) $aggregateId),
            'order' => $this->orderSubject((string) $aggregateId),
            'quote' => $this->quoteSubject((string) $aggregateId),
            'renewal' => $this->renewalSubject((string) $aggregateId),
            'vendor_order' => $this->vendorOrderSubject((string) $aggregateId),
            'marketplace_order' => $this->marketplaceOrderSubject((string) $aggregateId),
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
     * `ownerRef` is always `null` here — see this class's own doc block's
     * `renewal` section for why no customer contact exists to resolve. The
     * scope entity (the grave record's cemetery) is what makes this method
     * worth having: it is what lets a cemetery operator actually be
     * notified where before nothing was.
     */
    private function renewalSubject(string $renewalId): ?RecipientResolutionSubject
    {
        $renewal = DB::table('renewals')->where('id', $renewalId)->first();

        if ($renewal === null) {
            return null;
        }

        $cemeteryId = DB::table('grave_records')->where('id', $renewal->grave_record_id)->value('cemetery_id');

        return new RecipientResolutionSubject(
            ownerRef: null,
            scopeEntityType: $cemeteryId !== null ? ScopeEntityType::CEMETERY : null,
            scopeEntityId: $cemeteryId,
        );
    }

    /**
     * `customer_email` is NOT NULL on `vendor_orders` (schema-enforced at
     * creation, see `2026_08_12_110000_create_vendor_orders_table.php`), so
     * unlike `ownerRefForParty()` there is no "row exists but has no
     * reachable contact" branch to guard here — a `vendor_orders` row
     * always has one.
     */
    private function vendorOrderSubject(string $vendorOrderId): ?RecipientResolutionSubject
    {
        $row = DB::table('vendor_orders')->where('id', $vendorOrderId)->first();

        if ($row === null) {
            return null;
        }

        return new RecipientResolutionSubject(
            ownerRef: self::VENDOR_ORDER_CUSTOMER_PREFIX.$row->id,
            scopeEntityType: ScopeEntityType::VENDOR,
            scopeEntityId: $row->vendor_id,
        );
    }

    /**
     * `customer_ref` is NOT NULL on `marketplace_orders` — but its CONTENT
     * carries two different shapes depending on `Checkout::placeOrder()`'s
     * own ternary (`auth()->check() ? (string) auth()->id() : session()->
     * getId()`): a real `users.id` digit string for an authenticated
     * checkout, or a PHP session id for a guest one. A session id is not a
     * `users.id` — comparing it against `users.id` (a `bigint` column)
     * would not merely fail to match, it would raise a database error
     * (an invalid `bigint` literal), so this method must distinguish the
     * two shapes itself rather than pass `customer_ref` through opaquely.
     * `ctype_digit` is the same test `MarketplaceOrderInfolist` already
     * uses for the identical ambiguity (`customer_ref === null || !
     * ctype_digit($order->customer_ref)` there gates a raw-string display
     * fallback; here it gates whether `ownerRef` may be set at all). A
     * guest order therefore resolves `ownerRef: null` — no owner to
     * notify, exactly like an anonymous `booking_draft` — while keeping
     * its Vendor-column scope.
     */
    private function marketplaceOrderSubject(string $marketplaceOrderId): ?RecipientResolutionSubject
    {
        $row = DB::table('marketplace_orders')->where('id', $marketplaceOrderId)->first();

        if ($row === null) {
            return null;
        }

        return new RecipientResolutionSubject(
            ownerRef: ctype_digit((string) $row->customer_ref) ? $row->customer_ref : null,
            scopeEntityType: ScopeEntityType::VENDOR,
            scopeEntityId: $row->vendor_id,
        );
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
