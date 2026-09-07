<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use App\Platform\Notification\EloquentRecipientAddressResolver;
use App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `Contracts\RecipientAddressResolver`'s one implementation — see that
 * contract's doc block for the two `actorRef` shapes it distinguishes.
 */
final class EloquentRecipientAddressResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_resolves_a_staff_actor_ref_to_their_user_email(): void
    {
        $user = User::factory()->create(['email' => 'admin@example.test']);

        $recipient = new Recipient(
            actorRef: (string) $user->id,
            actorRole: RecipientRole::PLATFORM_ADMIN,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $email = (new EloquentRecipientAddressResolver)->emailFor($recipient);

        $this->assertSame('admin@example.test', $email);
    }

    public function test_it_resolves_a_guest_order_party_actor_ref_to_the_partys_contact_email(): void
    {
        $order = Order::query()->create([
            'reference' => 'MK-RESOLVER-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);

        $party = OrderParty::query()->create([
            'order_id' => $order->getKey(),
            'user_id' => null,
            'role' => OrderPartyRole::PEMESAN->value,
            'contact_email' => 'guest@example.test',
        ]);

        $recipient = new Recipient(
            actorRef: ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX.$party->getKey(),
            actorRole: RecipientRole::CUSTOMER,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $email = (new EloquentRecipientAddressResolver)->emailFor($recipient);

        $this->assertSame('guest@example.test', $email);
    }

    public function test_it_returns_null_for_a_user_id_that_no_longer_exists(): void
    {
        $recipient = new Recipient(
            actorRef: '999999',
            actorRole: RecipientRole::PLATFORM_ADMIN,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $this->assertNull((new EloquentRecipientAddressResolver)->emailFor($recipient));
    }

    public function test_it_returns_null_for_a_guest_order_party_reference_that_no_longer_exists(): void
    {
        $recipient = new Recipient(
            actorRef: ProvisionalAggregateNotificationSubjectSource::GUEST_ORDER_PARTY_PREFIX.'00000000-0000-0000-0000-000000000000',
            actorRole: RecipientRole::CUSTOMER,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $this->assertNull((new EloquentRecipientAddressResolver)->emailFor($recipient));
    }

    /**
     * Batch 2E's `vendor_order` fix — the prefixed reference re-reads the
     * SAME `vendor_orders` row (by its own id) rather than a separate party
     * table, because `customer_email` is inline and always present there.
     */
    public function test_it_resolves_a_vendor_order_customer_actor_ref_to_the_orders_inline_email(): void
    {
        $vendor = Vendor::query()->create(['name' => 'Vendor Resolver Uji', 'is_active' => true]);

        $productId = DB::table('products')->insertGetId([
            'code' => 'PRD-RESOLVER',
            'category' => 'KARANGAN_BUNGA',
            'name' => 'Produk Resolver Uji',
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

        $vendorOrder = VendorOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'vendor_id' => $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Resolver Uji',
            'customer_phone' => '081234567892',
            'customer_email' => 'resolver-uji@example.test',
            'status' => 'MENUNGGU_VENDOR',
        ]);

        $recipient = new Recipient(
            actorRef: ProvisionalAggregateNotificationSubjectSource::VENDOR_ORDER_CUSTOMER_PREFIX.$vendorOrder->getKey(),
            actorRole: RecipientRole::CUSTOMER,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $email = (new EloquentRecipientAddressResolver)->emailFor($recipient);

        $this->assertSame('resolver-uji@example.test', $email);
    }

    public function test_it_returns_null_for_a_vendor_order_customer_reference_that_no_longer_exists(): void
    {
        $recipient = new Recipient(
            actorRef: ProvisionalAggregateNotificationSubjectSource::VENDOR_ORDER_CUSTOMER_PREFIX.'999999',
            actorRole: RecipientRole::CUSTOMER,
            scopeEntityType: null,
            scopeEntityId: null,
        );

        $this->assertNull((new EloquentRecipientAddressResolver)->emailFor($recipient));
    }
}
