<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\MarkMarketplaceOrderPaid;
use App\Domain\Marketplace\Actions\PlaceMarketplaceOrder;
use App\Domain\Marketplace\Actions\UpdateVendorOrderStatus;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\Audit\AuditSource;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

/**
 * Two marketplace orders for the Task-4 demo vendor — placed/unpaid and
 * paid/vendor-processing — driven through the real checkout Actions
 * (`AddToCart` -> `PlaceMarketplaceOrder`, then `MarkMarketplaceOrderPaid` ->
 * `UpdateVendorOrderStatus` for the second), never a direct order write.
 *
 * ---------------------------------------------------------------------------
 * The demo listing/service area are NOT `TaggedAsDemoData` — confirmed by
 * reading the migration, not assumed
 * ---------------------------------------------------------------------------
 * `2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php`'s `TABLES`
 * list adds `demo_batch_id` to `vendors`/`marketplace_orders`/`vendor_orders`
 * and thirteen other tables — it deliberately does NOT include
 * `vendor_listings` or `service_areas`. Calling `TaggedAsDemoData::tag()` on
 * either model would fail against real Postgres with "column demo_batch_id
 * does not exist"; both tables carry `vendor_id` under a `restrictOnDelete()`
 * foreign key, so a purge keyed off the already-tagged demo `vendors` row
 * (delete children by `vendor_id`, then the vendor) is the coherent removal
 * path this subsystem's table selection implies, not a second tag column on
 * two tables it deliberately left out.
 *
 * ---------------------------------------------------------------------------
 * No dedicated "create listing"/"create service area" domain Action exists
 * ---------------------------------------------------------------------------
 * `App\Filament\Vendor\Resources\VendorListings\Pages\CreateVendorListing`
 * and `...\ServiceAreas\Pages\CreateServiceArea` are both plain Filament
 * `CreateRecord` pages over the Eloquent model — the same confirmed exception
 * `VendorAccountExampleData`'s doc block already establishes for
 * Vendor/VendorUser/User. Direct model creation here follows that precedent.
 *
 * `PlaceMarketplaceOrder`'s `?CarbonImmutable $now` is left at its default
 * (real wall-clock `now()`) — the same choice `RenewalExampleData`/
 * `BookingOrderExampleData` make for every Action carrying an implicit
 * "when this really happened" timestamp; no random value is generated
 * anywhere in this class.
 */
final class MarketplaceOrderExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @return list<MarketplaceOrder>
     */
    public static function seed(string $batchId, Vendor $vendor): array
    {
        // `PlaceMarketplaceOrder` refuses to run without a configured badan
        // usaha ref (`BadanUsahaNotConfiguredException`); nothing in this
        // test/demo environment sets one otherwise (no `site_settings` row,
        // no `MARKETPLACE_BADAN_USAHA_REF` env var) — same fallback
        // `PlaceMarketplaceOrderTest::setUp()` already relies on.
        config(['marketplace.badan_usaha_ref' => 'demo-badan-usaha-contoh']);

        $listing = self::demoListing($vendor, $batchId);
        $area = self::demoServiceArea($vendor);

        return [
            self::placedUnpaid($listing, $area, $batchId, 0),
            self::paidAndProcessing($listing, $area, $batchId, 1),
        ];
    }

    private static function demoListing(Vendor $vendor, string $batchId): VendorListing
    {
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);

        $listing = VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $product?->id,
            'price_minor' => 150_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'production_lead_time_days' => 2,
            'cancellation_policy' => sprintf(
                'Contoh kebijakan pembatalan demo untuk vendor %s: dapat dibatalkan maksimal 24 jam sebelum pengerjaan dimulai.',
                $vendor->name,
            ),
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        return $listing;
    }

    private static function demoServiceArea(Vendor $vendor): ServiceArea
    {
        return ServiceArea::query()->create([
            'vendor_id' => $vendor->id,
            'area_code' => 'EX-DEMO-01',
            'area_label' => 'Jakarta Pusat Contoh',
            'delivery_fee_minor' => 25_000,
            'is_active' => true,
        ]);
    }

    private static function customerRef(string $batchId, int $index): string
    {
        return sprintf('demo-marketplace-customer-%d-%s', $index, $batchId);
    }

    private static function placeOrder(VendorListing $listing, ServiceArea $area, string $batchId, int $index): MarketplaceOrder
    {
        $customerRef = self::customerRef($batchId, $index);

        $cart = Cart::query()->firstOrCreate([
            'customer_ref' => $customerRef,
            'session_ref' => null,
        ]);

        (new AddToCart)->handle($cart, $listing, 1);

        return (new PlaceMarketplaceOrder)->handle(
            cart: $cart->fresh(),
            customerRef: $customerRef,
            area: $area,
            idempotencyKey: sprintf('demo-marketplace-order-%d-%s', $index, $batchId),
            recipientName: DemoContactData::personName($index),
            recipientPhone: DemoContactData::phone($index),
            recipientEmail: DemoContactData::email($index),
        );
    }

    private static function tagOrderAndVendorOrders(MarketplaceOrder $order, string $batchId): void
    {
        TaggedAsDemoData::tag($order, $batchId);

        foreach ($order->vendorOrders as $vendorOrder) {
            TaggedAsDemoData::tag($vendorOrder, $batchId);
        }
    }

    private static function placedUnpaid(VendorListing $listing, ServiceArea $area, string $batchId, int $index): MarketplaceOrder
    {
        $order = self::placeOrder($listing, $area, $batchId, $index);
        self::tagOrderAndVendorOrders($order, $batchId);

        return $order->fresh();
    }

    private static function paidAndProcessing(VendorListing $listing, ServiceArea $area, string $batchId, int $index): MarketplaceOrder
    {
        $order = self::placeOrder($listing, $area, $batchId, $index);
        self::tagOrderAndVendorOrders($order, $batchId);

        (new MarkMarketplaceOrderPaid)(
            order: $order,
            amountMinor: (int) $order->total_minor,
            actorRef: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
            source: AuditSource::Job,
        );

        $vendorOrder = $order->vendorOrders()->firstOrFail();

        (new UpdateVendorOrderStatus)(
            order: $vendorOrder,
            status: VendorProcessingStatus::DIPROSES,
            actorReference: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
            auditSource: AuditSource::Job,
        );

        return $order->fresh();
    }
}
