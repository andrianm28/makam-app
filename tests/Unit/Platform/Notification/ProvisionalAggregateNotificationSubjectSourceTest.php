<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\PaymentState;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
     * submission) has no scope entity — never an error.
     */
    public function test_an_order_with_no_booking_draft_has_no_scope_entity(): void
    {
        $order = $this->makeOrder();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertFalse($subject->hasScopeEntity());
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
     * `bookingDraftId` is accepted here rather than set via a later
     * `->save()` — `Order` is a guarded model (`OrderIsGuardedException`):
     * only `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` may
     * update a persisted row, so every field this test needs must be
     * present at `create()` time.
     */
    /**
     * The load-bearing case for Batch 2E's `vendor_order` fix — a real
     * `vendor_orders` row resolves the prefixed owner reference (see
     * `EloquentRecipientAddressResolverTest` for the address-resolution
     * side of this same reference) and the Vendor-column scope, where
     * before this batch `vendor_order` fell through `default => null` and
     * `vendor_order.decided.v1`/`vendor_order.complaint_filed.v1` resolved
     * zero recipients.
     */
    public function test_a_real_vendor_order_resolves_the_prefixed_customer_reference_and_vendor_scope(): void
    {
        [$vendor, $order] = $this->makeVendorOrder();

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('vendor_order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertSame(
            ProvisionalAggregateNotificationSubjectSource::VENDOR_ORDER_CUSTOMER_PREFIX.$order->getKey(),
            $subject->ownerRef
        );
        $this->assertSame(ScopeEntityType::VENDOR, $subject->scopeEntityType);
        $this->assertSame($vendor->id, (string) $subject->scopeEntityId);
    }

    public function test_a_missing_vendor_order_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('vendor_order', 999_999);

        $this->assertNull($subject);
    }

    /**
     * The load-bearing case for Batch 2E's `marketplace_order` fix — an
     * authenticated customer's `customer_ref` (already a `users.id`
     * string, set by `Checkout`/`Cart`/`ProductDetail`) resolves straight
     * through as `ownerRef`, no prefix, plus the Vendor-column scope. Where
     * before this batch `marketplace_order` fell through `default =>
     * null` and `marketplace_order.submitted.v1` resolved zero recipients.
     */
    public function test_a_marketplace_order_for_an_authenticated_customer_resolves_their_user_id_and_vendor_scope(): void
    {
        $user = User::factory()->create();
        [$vendor, $order] = $this->makeMarketplaceOrder(customerRef: (string) $user->id);

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('marketplace_order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertSame((string) $user->id, $subject->ownerRef);
        $this->assertSame(ScopeEntityType::VENDOR, $subject->scopeEntityType);
        $this->assertSame($vendor->id, (string) $subject->scopeEntityId);
    }

    /**
     * A guest checkout's `customer_ref` is NOT NULL but carries a PHP
     * session id (`Checkout::placeOrder()`'s own `session()->getId()`
     * branch), not a `users.id` — `ctype_digit()` rejects it, so no owner
     * is resolved, but the Vendor-column scope still is, matching the
     * anonymous-`booking_draft` precedent.
     */
    public function test_a_guest_marketplace_order_has_no_owner_but_keeps_its_vendor_scope(): void
    {
        [$vendor, $order] = $this->makeMarketplaceOrder(customerRef: 'a1b2c3d4e5f6g7h8session');

        $subject = (new ProvisionalAggregateNotificationSubjectSource)->subjectFor('marketplace_order', $order->getKey());

        $this->assertNotNull($subject);
        $this->assertNull($subject->ownerRef);
        $this->assertSame(ScopeEntityType::VENDOR, $subject->scopeEntityType);
        $this->assertSame($vendor->id, (string) $subject->scopeEntityId);
    }

    public function test_a_missing_marketplace_order_resolves_to_null(): void
    {
        $subject = (new ProvisionalAggregateNotificationSubjectSource)
            ->subjectFor('marketplace_order', '00000000-0000-0000-0000-000000000000');

        $this->assertNull($subject);
    }

    /**
     * @return array{0: Vendor, 1: VendorOrder}
     */
    private function makeVendorOrder(): array
    {
        $vendor = Vendor::query()->create(['name' => 'Vendor Subjek Uji', 'is_active' => true]);

        $productId = DB::table('products')->insertGetId([
            'code' => 'PRD-'.Str::random(8),
            'category' => 'KARANGAN_BUNGA',
            'name' => 'Produk Subjek Uji',
            'description' => 'Deskripsi uji.',
            'base_price_idr' => 100_000,
            'price_version' => 1,
            'is_active' => true,
            'sort_order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $listing = VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $productId,
            'price_minor' => 150_000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'stock_quantity' => 5,
            'is_active' => true,
        ]);

        $order = VendorOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Subjek Uji',
            'customer_phone' => '081234567891',
            'customer_email' => 'subjek-uji@example.test',
            'status' => 'MENUNGGU_VENDOR',
        ]);

        return [$vendor, $order];
    }

    /**
     * @return array{0: Vendor, 1: MarketplaceOrder}
     */
    private function makeMarketplaceOrder(string $customerRef): array
    {
        $vendor = Vendor::query()->create(['name' => 'Vendor Marketplace Uji', 'is_active' => true]);

        $order = MarketplaceOrder::query()->create([
            'order_number' => 'MKT-SUBJECT-'.Str::upper(Str::random(8)),
            'customer_ref' => $customerRef,
            'entity_ref' => 'badan-usaha-subject-test',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => 300_000,
            'delivery_fee_minor' => 0,
            'total_minor' => 300_000,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'idem-subject-'.Str::random(12),
            'placed_at' => CarbonImmutable::now(),
        ]);

        return [$vendor, $order];
    }

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
