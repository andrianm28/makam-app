<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * task-3-brief.md D3: the swappable seam that resolves a
 * `RecipientResolutionSubject` from an outbox envelope's aggregate
 * reference. Reads `booking_drafts` via the query builder, never via
 * `App\Domain\Booking\Models\BookingDraft` (see the class's own doc block).
 */
final class ProvisionalAggregateNotificationSubjectSourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_unmapped_aggregate_type_resolves_to_null_without_throwing(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('grave_marker', 'anything');

        $this->assertNull($subject);
    }

    public function test_a_missing_booking_draft_row_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('booking_draft', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    public function test_a_real_booking_draft_resolves_owner_and_cemetery_scope(): void
    {
        $user = User::factory()->create();
        $cemetery = $this->createCemetery();

        $draft = (new StartBookingDraft)(userId: $user->id);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertSame($user->id, $subject->ownerRef);
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
        $this->assertSame((string) $cemetery->id, (string) $subject->scopeEntityId);
    }

    public function test_an_anonymous_draft_has_no_owner_but_keeps_its_cemetery_scope(): void
    {
        $cemetery = $this->createCemetery();

        $draft = (new StartBookingDraft)(userId: null);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
    }

    public function test_a_draft_with_no_cemetery_selected_yet_has_no_scope_entity(): void
    {
        $user = User::factory()->create();
        $draft = (new StartBookingDraft)(userId: $user->id);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('booking_draft', $draft->id);

        $this->assertNotNull($subject);
        $this->assertSame($user->id, $subject->ownerRef);
        $this->assertFalse($subject->hasScopeEntity());
    }

    /**
     * The load-bearing case: 116 combined order/quote events on the real
     * dev database, 0 ever resolving a recipient before this branch existed
     * — see this class's own doc block. An anonymous customer's ONLY
     * reference is `order_parties.contact_email`, so ownerRef must carry the
     * `GUEST_ORDER_PARTY_PREFIX` form, not null.
     */
    public function test_an_order_for_an_anonymous_customer_resolves_the_guest_order_party_reference(): void
    {
        $cemetery = $this->createCemetery();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = $this->makeOrder($draft->id);

        $party = OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => null,
            'role' => OrderPartyRole::PEMESAN->value,
            'contact_email' => 'anon@example.test',
        ]);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertSame(
            ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX.$party->getKey(),
            $subject->ownerRef
        );
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
        $this->assertSame((string) $cemetery->id, (string) $subject->scopeEntityId);
    }

    public function test_an_order_for_an_authenticated_customer_resolves_their_user_id(): void
    {
        $user = User::factory()->create();
        $order = $this->makeOrder();

        OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => $user->id,
            'role' => OrderPartyRole::PEMESAN->value,
            'contact_email' => 'authenticated@example.test',
        ]);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertSame($user->id, $subject->ownerRef);
    }

    /**
     * A party with neither a user_id nor a contact_email (a draft that
     * never reached Step 6) has nothing to notify — ownerRef stays null,
     * identically to an anonymous booking draft.
     */
    public function test_an_order_party_with_no_reachable_contact_resolves_no_owner(): void
    {
        $order = $this->makeOrder();

        OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => null,
            'role' => OrderPartyRole::PEMESAN->value,
        ]);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
    }

    public function test_an_order_with_no_party_row_yet_resolves_no_owner_without_throwing(): void
    {
        $order = $this->makeOrder();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
    }

    /**
     * An order with no backing booking draft (a Funeral-Case/Pre-Need-only
     * submission) has no CEMETERY scope entity — never an error. It still
     * carries the platform's own `business_entity` scope (NOTIF-01,
     * `ProvisionalAggregateNotificationSubjectSource`'s own doc block) —
     * every order gets that one unconditionally, regardless of whether it
     * has a backing booking draft — so `hasScopeEntity()` is `true` here,
     * and the assertion is on the ABSENCE of a cemetery entity specifically,
     * not on the subject carrying zero scope entities.
     */
    public function test_an_order_with_no_booking_draft_has_no_cemetery_scope_entity(): void
    {
        $order = $this->makeOrder();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertTrue($subject->hasScopeEntity());
        $this->assertNull($subject->scopeEntityType);
        $this->assertCount(1, $subject->scopeEntities);
        $this->assertSame(ScopeEntityType::BUSINESS_ENTITY, $subject->scopeEntities[0]->type);
    }

    /**
     * NOTIF-01: every order subject also carries the platform's own
     * `business_entity` scope as a SECOND scope entity, alongside the
     * order's own cemetery scope — this is the load-bearing case that lets
     * a platform admin resolve as a recipient for the first time.
     */
    public function test_an_order_with_a_cemetery_also_carries_the_platform_business_entity_scope(): void
    {
        $cemetery = $this->createCemetery();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = $this->makeOrder($draft->id);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertCount(2, $subject->scopeEntities);

        $types = array_map(static fn ($ref) => $ref->type, $subject->scopeEntities);
        $this->assertContains(ScopeEntityType::CEMETERY, $types);
        $this->assertContains(ScopeEntityType::BUSINESS_ENTITY, $types);
    }

    public function test_a_missing_order_row_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('order', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    /**
     * A quote resolves through its OWN order — the same derivation, not a
     * second one. See this class's own doc block.
     */
    public function test_a_quote_resolves_the_same_subject_as_its_own_order(): void
    {
        $cemetery = $this->createCemetery();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = $this->makeOrder($draft->id);

        $party = OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => null,
            'role' => OrderPartyRole::PEMESAN->value,
            'contact_email' => 'quote-owner@example.test',
        ]);

        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('quote', $quote->getKey());

        $this->assertNotNull($subject);
        $this->assertSame(
            ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX.$party->getKey(),
            $subject->ownerRef
        );
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
        $this->assertSame((string) $cemetery->id, (string) $subject->scopeEntityId);
    }

    public function test_a_missing_quote_row_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('quote', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    /**
     * NOTIF-13: a visitation booking resolves the CEMETERY scope entity
     * from `visitation_bookings.cemetery_id` — no owner reference exists
     * (see `visitationBookingSubject()`'s own doc block for why, matching
     * the `renewal` precedent).
     */
    public function test_a_visitation_booking_resolves_its_cemetery_scope_with_no_owner(): void
    {
        $cemetery = $this->createCemetery();

        CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['open' => '08:00', 'close' => '17:00'],
            ],
            'daily_capacity' => 10,
        ]);

        $booking = app(RequestVisitation::class)(
            $cemetery,
            CarbonImmutable::now()->addDays(3)->toDateString(),
            2,
            '0812-3456-7890',
            'visitor@example.test',
            null,
            [],
            'subject-source-test-'.Str::random(8),
            'actor:customer',
        );

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('visitation_booking', $booking->getKey());

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
        $this->assertSame(ScopeEntityType::CEMETERY, $subject->scopeEntityType);
        $this->assertSame((string) $cemetery->id, (string) $subject->scopeEntityId);
    }

    public function test_a_missing_visitation_booking_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('visitation_booking', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    /**
     * `bookingDraftId` is accepted here rather than set via a later
     * `->save()` — `Order` is a guarded model (`OrderIsGuardedException`):
     * only `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` may
     * update a persisted row, so every field this test needs must be
     * present at `create()` time.
     */
    private function makeOrder(?string $bookingDraftId = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-SUBJECT-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $bookingDraftId,
        ]);
    }

    private function createCemetery(): Cemetery
    {
        static $sequence = 0;
        $sequence++;

        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => "Subject Source Test Cemetery {$sequence}",
            'slug' => "subject-source-test-cemetery-{$sequence}",
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba Subjek',
        ]);
    }
}
