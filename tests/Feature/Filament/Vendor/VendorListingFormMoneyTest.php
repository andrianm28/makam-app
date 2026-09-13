<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Vendor;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Filament\Vendor\Resources\VendorListings\Pages\CreateVendorListing;
use App\Filament\Vendor\Resources\VendorListings\Pages\EditVendorListing;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * MKT-09 — the vendor-panel listing form's `price_minor` field.
 *
 * Before this fix the field took and echoed the raw `price_minor` integer
 * with no conversion, labelled "dalam sen" — a vendor typing "150000"
 * thinking rupiah actually stored Rp 1.500,00. The fix makes the field
 * symmetric with the already-correct `VendorListingsTable`'s
 * `->money('IDR', divideBy: 100)`: the vendor types and sees plain rupiah;
 * the stored `price_minor` is that amount * 100.
 */
final class VendorListingFormMoneyTest extends TestCase
{
    use RefreshDatabase;

    private string $vendorId;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $vendor = Vendor::query()->create(['name' => 'Toko Bunga Rupiah', 'is_active' => true]);
        $this->vendorId = (string) $vendor->id;

        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product, 'The seeded FLOWER_BOARD product is missing.');
        $this->product = $product;

        Filament::setCurrentPanel('vendor');
    }

    public function test_creating_a_listing_stores_rupiah_input_as_minor_units(): void
    {
        $this->actingAsVendorGrantedTo($this->vendorId);

        Livewire::test(CreateVendorListing::class)
            ->fillForm([
                'product_id' => (string) $this->product->id,
                'price_minor' => '150000',
                'availability_mode' => AvailabilityMode::STOCKED,
                'evidence_requirement' => EvidenceRequirement::PHOTO,
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $listing = VendorListing::query()
            ->where('vendor_id', $this->vendorId)
            ->where('product_id', $this->product->id)
            ->sole();

        // Rp 150.000 typed by the vendor -> 15_000_000 stored minor units.
        $this->assertSame(15_000_000, $listing->price_minor);
    }

    public function test_editing_a_listing_round_trips_the_stored_minor_units_back_to_rupiah(): void
    {
        $this->actingAsVendorGrantedTo($this->vendorId);

        $listing = VendorListing::query()->create([
            'vendor_id' => $this->vendorId,
            'product_id' => $this->product->id,
            'price_minor' => 15_000_000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        // The edit form must show the field as plain rupiah (150000), not
        // the raw stored minor-unit value (15000000).
        Livewire::test(EditVendorListing::class, ['record' => $listing->getRouteKey()])
            ->assertFormSet(['price_minor' => 150_000]);

        // Saving with the field unchanged must round-trip back to the same
        // stored minor-unit value — the symmetry the fix exists for.
        Livewire::test(EditVendorListing::class, ['record' => $listing->getRouteKey()])
            ->fillForm(['price_minor' => '150000'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(15_000_000, $listing->refresh()->price_minor);
    }

    private function actingAsVendorGrantedTo(string $vendorId): User
    {
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => $vendorId,
        ]);

        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }
}
