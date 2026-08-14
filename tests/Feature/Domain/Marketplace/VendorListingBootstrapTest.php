<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\ProductDetail;
use App\Support\ExampleData\VendorListingExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The bootstrap contract of `2026_08_14_100000_seed_vendors_and_listings
 * .php`: every fresh database ships five example vendors and one ACTIVE
 * listing per product code, so the marketplace browse → add → cart journey
 * is operable end to end from seed alone — no test fixture needed for the
 * add affordance to exist. Real seeded rows and real Actions only
 * (`makam-testing` forbids domain factories).
 */
final class VendorListingBootstrapTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The seeded offer `ProductDetail` itself picks for `$code`: the first
     * ACTIVE listing by creation order — exactly
     * `firstActiveListing()`'s underlying query.
     */
    private function seededListing(string $code): VendorListing
    {
        $product = Product::findByCode($code);
        $this->assertNotNull($product, "Seeded product [{$code}] is missing.");

        return VendorListing::query()
            ->active()
            ->forProduct($product->id)
            ->orderBy('id')
            ->firstOrFail();
    }

    public function test_the_seed_migration_creates_five_vendors_and_nine_listings(): void
    {
        $this->assertSame(5, Vendor::query()->count());
        $this->assertSame(9, VendorListing::query()->count());

        // Every seeded vendor is active — each listing is a live offer.
        $this->assertSame(5, Vendor::active()->count());
        // All nine listings are active: the whole catalogue is orderable.
        $this->assertSame(9, VendorListing::query()->active()->count());
    }

    public function test_every_product_code_has_an_active_seeded_listing(): void
    {
        foreach (ProductCode::KNOWN_CODES as $code) {
            $listing = $this->seededListing($code);

            $this->assertNotNull($listing, "No active listing for [{$code}].");
            $this->assertSame($code, $listing->product->code);
        }
    }

    public function test_the_detail_page_finds_the_seeded_listing_for_every_product(): void
    {
        // Goes through ProductDetail::render() → firstActiveListing(): each
        // product page renders its seeded offer's add affordance.
        foreach (ProductCode::KNOWN_CODES as $code) {
            Livewire::test(ProductDetail::class, ['productCode' => $code])
                ->assertSee('Tambah ke Keranjang');
        }
    }

    public function test_the_add_to_cart_flow_works_end_to_end_for_a_seeded_listing(): void
    {
        $listing = $this->seededListing(ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(ProductDetail::class, ['productCode' => ProductCode::GRAVESTONE_GRANITE])
            ->call('addToCart')
            ->assertRedirect(route('marketplace.cart'));

        $cart = CartModel::where('session_ref', session()->getId())->firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($listing->id, $cart->items()->first()->vendor_listing_id);
        $this->assertSame($listing->vendor_id, $cart->vendor_id);
    }

    public function test_adding_across_seeded_vendors_surfaces_the_single_vendor_conflict(): void
    {
        // Listing i belongs to vendor i % 5, so the first two products sit
        // under different seeded vendors (indices 0 and 1) — the conflict is
        // reachable from seeded data alone.
        $first = $this->seededListing(ProductCode::FLOWER_BOARD);
        $second = $this->seededListing(ProductCode::FLOWER_PETAL_PACKAGE);
        $this->assertNotSame($first->vendor_id, $second->vendor_id);

        Livewire::test(ProductDetail::class, ['productCode' => ProductCode::FLOWER_BOARD])
            ->call('addToCart')
            ->assertRedirect(route('marketplace.cart'));

        $component = Livewire::test(ProductDetail::class, ['productCode' => ProductCode::FLOWER_PETAL_PACKAGE])
            ->call('addToCart')
            ->assertSet('conflictOpen', true)
            ->assertSee($first->vendor->name)
            ->assertSee($second->vendor->name);

        // The conflict never mutates the cart.
        $cart = CartModel::where('session_ref', session()->getId())->firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($first->id, $cart->items()->first()->vendor_listing_id);

        // Dismissing keeps the existing items and the vendor lock.
        $component->call('dismissConflict')->assertSet('conflictOpen', false);
        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($first->vendor_id, $cart->fresh()->vendor_id);
    }

    public function test_seeding_twice_is_idempotent(): void
    {
        // The generator guards on the synthetic names: a re-run (partial
        // rollback, manual invocation) must not duplicate or fail.
        VendorListingExampleData::seed();

        $this->assertSame(5, Vendor::query()->count());
        $this->assertSame(9, VendorListing::query()->count());
    }
}
