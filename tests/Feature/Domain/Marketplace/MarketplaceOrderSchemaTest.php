<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\PaymentState;
use App\Domain\Marketplace\ProductCode;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\FinancialLedger\Money;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Customer-side order schema: `marketplace_orders`, `marketplace_order_items`,
 * `PaymentState`, and the database-level single-vendor guarantee.
 *
 * `vendor_orders` is L10's table — this lane only links it via the additive
 * nullable `marketplace_order_id` column (`2026_08_12_110015`) and consumes
 * its NOT NULL `customer_name`/`customer_phone`/`customer_email`/`listing_id`
 * shape as-is.
 */
final class MarketplaceOrderSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(string $name = 'Toko Bunga'): Vendor
    {
        return Vendor::create(['name' => $name, 'is_active' => true]);
    }

    private function listing(Vendor $vendor, int $priceMinor = 150_000): VendorListing
    {
        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode(ProductCode::FLOWER_BOARD)->id,
            'price_minor' => $priceMinor,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    private function order(Vendor $vendor, array $overrides = []): MarketplaceOrder
    {
        return MarketplaceOrder::create(array_merge([
            'order_number' => 'MKT-'.uniqid(),
            'customer_ref' => 'cust-1',
            'entity_ref' => 'badan-usaha-test',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => 300_000,
            'delivery_fee_minor' => 25_000,
            'total_minor' => 325_000,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => uniqid('idem-'),
            'placed_at' => now(),
        ], $overrides));
    }

    private function vendorOrderFor(MarketplaceOrder $order, Vendor $vendor, VendorListing $listing): VendorOrder
    {
        return VendorOrder::create([
            'uuid' => (string) Str::uuid(),
            'marketplace_order_id' => $order->id,
            'vendor_id' => $vendor->id,
            'listing_id' => $listing->id,
            'customer_name' => 'Pelanggan Uji',
            'customer_phone' => '081234567890',
            'customer_email' => 'pelanggan@example.test',
            'status' => VendorProcessingStatus::MENUNGGU_VENDOR,
        ]);
    }

    public function test_an_order_totals_in_money_not_floats(): void
    {
        $order = $this->order($this->vendor());

        $this->assertEquals(new Money(325_000), $order->total());
        $this->assertIsInt($order->total()->toMinorInt());
    }

    public function test_payment_state_and_processing_status_are_separate_vocabularies(): void
    {
        // AC12: paid is not completed. Neither list may contain the other's values.
        $this->assertNotContains('DIBAYAR', VendorProcessingStatus::KNOWN_STATUSES);
        $this->assertNotContains(VendorProcessingStatus::SELESAI, PaymentState::KNOWN);
    }

    public function test_an_unknown_payment_state_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->order($this->vendor(), ['payment_state' => 'LUNAS_BANGET']);
    }

    public function test_the_idempotency_key_is_unique(): void
    {
        $vendor = $this->vendor();
        $this->order($vendor, ['idempotency_key' => 'fixed-key']);

        $this->expectException(QueryException::class);
        $this->order($vendor, ['idempotency_key' => 'fixed-key']);
    }

    public function test_a_vendor_order_starts_awaiting_the_vendor(): void
    {
        $vendor = $this->vendor();
        $order = $this->order($vendor);

        $vendorOrder = $this->vendorOrderFor($order, $vendor, $this->listing($vendor));

        $this->assertSame(VendorProcessingStatus::MENUNGGU_VENDOR, $vendorOrder->status);
        $this->assertSame($order->id, $vendorOrder->marketplace_order_id);
        $this->assertSame('pelanggan@example.test', $vendorOrder->customer_email);
    }

    public function test_a_second_vendor_order_on_one_order_is_refused_by_the_database(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The deferred single-vendor constraint trigger is PostgreSQL-only; asserted on PostgreSQL 18.'
            );
        }

        $vendorA = $this->vendor('Vendor A');
        $order = $this->order($vendorA);
        $this->vendorOrderFor($order, $vendorA, $this->listing($vendorA));

        $vendorB = $this->vendor('Vendor B');

        $this->assertTrue(
            DB::table('pg_trigger as t')
                ->join('pg_class as c', 'c.oid', '=', 't.tgrelid')
                ->where('t.tgname', 'vendor_orders_single_vendor')
                ->where('c.relname', 'vendor_orders')
                ->exists(),
            'The [vendor_orders_single_vendor] constraint trigger is not registered on [vendor_orders]. '
            .'The single-vendor invariant is unenforced, and this test would pass on the wrong error.',
        );

        // Nested so the aborted transaction unwinds to a SAVEPOINT rather than
        // poisoning RefreshDatabase's outer transaction (house pattern from
        // ManualPayoutTest::assertDeferredCheckRejects).
        try {
            DB::transaction(function () use ($order, $vendorB): void {
                $this->vendorOrderFor($order, $vendorB, $this->listing($vendorB));
                DB::statement('SET CONSTRAINTS vendor_orders_single_vendor IMMEDIATE');
            });

            $this->fail('Expected the database to refuse a second vendor on one marketplace order.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString(
                'MVP allows exactly one',
                $exception->getMessage(),
                'vendor_orders_single_vendor raised, but not for the invariant this test protects. '
                ."Got: {$exception->getMessage()}",
            );
        }
    }
}
