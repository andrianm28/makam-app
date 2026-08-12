<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\ServiceArea;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class VendorListingTest extends TestCase
{
    use RefreshDatabase;

    private function vendor(): Vendor
    {
        return Vendor::create(['name' => 'Toko Uji', 'is_active' => true]);
    }

    private function product(): Product
    {
        return Product::findByCode(ProductCode::FLOWER_BOARD);
    }

    public function test_a_listing_carries_every_field_requirement_2_names(): void
    {
        $listing = VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 250_000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 12,
            'production_lead_time_days' => 2,
            'cancellation_policy' => 'Batal maksimal 24 jam sebelum pengiriman.',
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        $this->assertEquals(new Money(250_000), $listing->priceMoney());
        $this->assertSame(12, $listing->stock_quantity);
        $this->assertSame(EvidenceRequirement::PHOTO, $listing->evidence_requirement);
    }

    public function test_price_is_returned_as_money_never_a_float(): void
    {
        $listing = VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 199_999,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::MADE_TO_ORDER,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);

        $this->assertInstanceOf(Money::class, $listing->priceMoney());
        $this->assertIsInt($listing->priceMoney()->toMinorInt());
        $this->assertSame(199_999, $listing->priceMoney()->toMinorInt());
    }

    public function test_an_unknown_evidence_requirement_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        VendorListing::create([
            'vendor_id' => $this->vendor()->id,
            'product_id' => $this->product()->id,
            'price_minor' => 1000,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => 'VIDEO_4K',
            'is_active' => true,
        ]);
    }

    public function test_a_vendor_may_list_a_product_only_once(): void
    {
        $vendor = $this->vendor();
        $product = $this->product();
        $row = [
            'vendor_id' => $vendor->id, 'product_id' => $product->id,
            'price_minor' => 1000, 'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::NONE, 'is_active' => true,
        ];

        VendorListing::create($row);

        $this->expectException(\Illuminate\Database\QueryException::class);
        VendorListing::create($row);
    }

    public function test_a_service_area_carries_its_own_delivery_fee(): void
    {
        $area = ServiceArea::create([
            'vendor_id' => $this->vendor()->id,
            'area_code' => 'JKT-SELATAN',
            'area_label' => 'Jakarta Selatan',
            'delivery_fee_minor' => 25_000,
            'is_active' => true,
        ]);

        $this->assertEquals(new Money(25_000), $area->deliveryFeeMoney());
    }

    public function test_availability_marks_a_blocked_date(): void
    {
        $vendor = $this->vendor();

        VendorAvailability::create([
            'vendor_id' => $vendor->id,
            'available_date' => '2026-09-01',
            'capacity' => 0,
            'is_blocked' => true,
        ]);

        $this->assertTrue(
            VendorAvailability::where('vendor_id', $vendor->id)
                ->whereDate('available_date', '2026-09-01')->first()->is_blocked
        );
    }
}
