<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\ReplaceCartWithVendor;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Platform\FinancialLedger\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CartTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $vendorName, string $code, int $priceMinor): VendorListing
    {
        $vendor = Vendor::create(['name' => $vendorName, 'is_active' => true]);

        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => $priceMinor,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_adding_a_first_item_locks_the_cart_to_that_vendor(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-1']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);

        $item = (new AddToCart)->handle($cart, $listing, 2);

        $this->assertInstanceOf(CartItem::class, $item);
        $this->assertSame($listing->vendor_id, $cart->fresh()->vendor_id);
        $this->assertEquals(new Money(300_000), $cart->fresh()->subtotal());
    }

    public function test_a_second_vendor_returns_a_conflict_and_changes_nothing(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-2']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 900_000);

        (new AddToCart)->handle($cart, $a, 1);
        $result = (new AddToCart)->handle($cart, $b, 1);

        $this->assertInstanceOf(CartConflict::class, $result);
        $this->assertSame($a->vendor_id, $result->existingVendorId);
        $this->assertSame($b->vendor_id, $result->incomingVendorId);
        $this->assertSame(1, $result->existingItemCount);

        // AC4: the existing item must NOT be lost, and the new one must NOT be added.
        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($a->id, $cart->fresh()->items()->first()->vendor_listing_id);
        $this->assertSame($a->vendor_id, $cart->fresh()->vendor_id);
    }

    public function test_replacing_the_cart_is_explicit_and_only_then_clears_items(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-3']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 150_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 900_000);

        (new AddToCart)->handle($cart, $a, 1);
        (new ReplaceCartWithVendor)->handle($cart, $b, 1);

        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame($b->vendor_id, $cart->fresh()->vendor_id);
        $this->assertEquals(new Money(900_000), $cart->fresh()->subtotal());
    }

    public function test_adding_the_same_listing_twice_increments_rather_than_duplicating(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-4']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        (new AddToCart)->handle($cart, $listing, 1);
        (new AddToCart)->handle($cart, $listing, 3);

        $this->assertSame(1, $cart->fresh()->items()->count());
        $this->assertSame(4, $cart->fresh()->items()->first()->quantity);
    }

    public function test_a_price_change_after_adding_is_detected(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-5']);
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        (new AddToCart)->handle($cart, $listing, 1);
        $this->assertFalse($cart->fresh()->hasStalePricing());

        $listing->update(['price_minor' => 120_000, 'price_version' => 2]);

        $this->assertTrue($cart->fresh()->hasStalePricing());
    }

    public function test_an_emptied_cart_releases_its_vendor_lock(): void
    {
        $cart = Cart::create(['customer_ref' => 'cust-6']);
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE, 500_000);

        $item = (new AddToCart)->handle($cart, $a, 1);
        $item->delete();
        $cart->fresh()->releaseVendorLockIfEmpty();

        // With the cart empty, the other vendor is now addable without a conflict.
        $this->assertInstanceOf(CartItem::class, (new AddToCart)->handle($cart->fresh(), $b, 1));
    }
}
