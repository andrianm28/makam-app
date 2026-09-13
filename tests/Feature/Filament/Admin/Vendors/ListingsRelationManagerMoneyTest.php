<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Vendors;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Filament\Admin\Resources\Vendors\RelationManagers\ListingsRelationManager;
use App\Models\User;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * MKT-09 — the admin `ListingsRelationManager` form and table.
 *
 * Before this fix: the form's `price_minor` field was labelled "Harga (Rp)"
 * but wrote the raw minor-unit integer unconverted, and the table column
 * displayed that raw integer with a bare "Rp " prefix — a listing genuinely
 * priced at Rp 150.000 (stored `price_minor = 15_000_000`) rendered as
 * "Rp 15000000" in the admin table. Both are fixed to be symmetric with the
 * vendor panel's already-correct `VendorListingsTable`'s
 * `->money('IDR', divideBy: 100)`.
 */
final class ListingsRelationManagerMoneyTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_creating_a_listing_from_the_admin_form_stores_rupiah_input_as_minor_units(): void
    {
        $this->admin();

        $vendor = Vendor::query()->create(['name' => 'Toko Bunga Admin', 'is_active' => true]);
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product, 'The seeded FLOWER_BOARD product is missing.');

        Livewire::test(ListingsRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('create', data: [
                'product_id' => (string) $product->id,
                'price_minor' => '150000',
                'availability_mode' => AvailabilityMode::STOCKED,
                'evidence_requirement' => EvidenceRequirement::PHOTO,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $listing = VendorListing::query()
            ->where('vendor_id', $vendor->id)
            ->where('product_id', $product->id)
            ->sole();

        $this->assertSame(15_000_000, $listing->price_minor);
    }

    public function test_editing_a_listing_from_the_admin_form_shows_and_round_trips_rupiah(): void
    {
        $this->admin();

        $vendor = Vendor::query()->create(['name' => 'Toko Bunga Admin 2', 'is_active' => true]);
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product, 'The seeded FLOWER_BOARD product is missing.');

        $listing = VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'price_minor' => 15_000_000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        Livewire::test(ListingsRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->callTableAction('edit', $listing, data: [
                'product_id' => (string) $product->id,
                'price_minor' => '150000',
                'availability_mode' => AvailabilityMode::STOCKED,
                'evidence_requirement' => EvidenceRequirement::PHOTO,
                'is_active' => true,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(15_000_000, $listing->refresh()->price_minor);
    }

    public function test_the_admin_table_renders_the_real_rupiah_amount_not_the_raw_minor_units(): void
    {
        $this->admin();

        $vendor = Vendor::query()->create(['name' => 'Toko Bunga Admin 3', 'is_active' => true]);
        $product = Product::findByCode(ProductCode::FLOWER_BOARD);
        $this->assertNotNull($product, 'The seeded FLOWER_BOARD product is missing.');

        VendorListing::query()->create([
            'vendor_id' => $vendor->id,
            'product_id' => $product->id,
            'price_minor' => 15_000_000,
            'availability_mode' => AvailabilityMode::STOCKED,
            'evidence_requirement' => EvidenceRequirement::PHOTO,
            'is_active' => true,
        ]);

        // This table now renders through the shared `->money()` Filament
        // column macro (ARCH-06, `AppServiceProvider::boot()`), which routes
        // every render through `Money::format()` — not Laravel's own
        // `Number::currency()` formatter, which the old Filament-native
        // `->money('IDR', divideBy: 100)` modifier used and which always
        // appends a ",00" fraction even for a whole-rupiah amount.
        $expected = (new Money(15_000_000))->format();

        Livewire::test(ListingsRelationManager::class, [
            'ownerRecord' => $vendor,
            'pageClass' => EditVendor::class,
        ])
            ->assertSee($expected)
            ->assertDontSee('15000000')
            ->assertDontSee('15.000.000');
    }
}
