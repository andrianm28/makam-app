<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\ProductCode;
use App\Livewire\Public\Marketplace\Cart;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class CartScreenTest extends TestCase
{
    use RefreshDatabase;

    private function listing(string $vendorName, string $code, int $price = 150_000): VendorListing
    {
        $vendor = Vendor::create(['name' => $vendorName, 'is_active' => true]);

        return VendorListing::create([
            'vendor_id' => $vendor->id,
            'product_id' => Product::findByCode($code)->id,
            'price_minor' => $price,
            'price_version' => 1,
            'availability_mode' => AvailabilityMode::STOCKED,
            'stock_quantity' => 10,
            'evidence_requirement' => EvidenceRequirement::NONE,
            'is_active' => true,
        ]);
    }

    public function test_an_empty_cart_shows_the_empty_state_not_a_broken_table(): void
    {
        Livewire::test(Cart::class)
            ->assertOk()
            ->assertSee('Keranjang Anda masih kosong')
            ->assertSee('Lihat katalog');
    }

    public function test_adding_a_second_vendor_opens_the_conflict_modal_and_keeps_both_options(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null)
            ->assertSet('conflictOpen', true)
            ->assertSee('Vendor A')
            ->assertSee('Vendor B')
            // AC4: the constraint is stated, and both resolutions are offered.
            ->assertSee('satu vendor')
            ->assertSee('Ganti keranjang')
            ->assertSee('Selesaikan pesanan ini dulu');
    }

    public function test_the_conflict_never_loses_the_existing_item(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        $component = Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null);

        $cart = CartModel::firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($a->id, $cart->items()->first()->vendor_listing_id);

        // Dismissing the modal still keeps the original item.
        $component->call('dismissConflict')->assertSet('conflictOpen', false);
        $this->assertSame(1, $cart->fresh()->items()->count());
    }

    public function test_replacing_is_explicit_and_only_then_swaps_the_vendor(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null)
            ->call('resolveConflictByReplacing')
            ->assertSet('conflictOpen', false);

        $cart = CartModel::firstOrFail();
        $this->assertSame(1, $cart->items()->count());
        $this->assertSame($b->id, $cart->items()->first()->vendor_listing_id);
    }

    public function test_a_changed_price_demands_reconfirmation_before_checkout(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD, 100_000);

        $component = Livewire::test(Cart::class)->call('addListing', $listing->id, 1, null);
        $listing->update(['price_minor' => 130_000, 'price_version' => 2]);

        $component->call('$refresh')
            ->assertSee('Harga berubah')
            ->assertSee('Konfirmasi harga baru');
    }

    public function test_the_screen_offers_a_support_affordance(): void
    {
        Livewire::test(Cart::class)->assertSee('Butuh bantuan');
    }

    public function test_update_quantity_cannot_touch_an_item_from_another_cart(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);

        $foreignCart = CartModel::create(['customer_ref' => null, 'session_ref' => 'another-session']);
        $foreignItem = (new AddToCart)->handle($foreignCart, $listing, 1);

        Livewire::test(Cart::class)
            ->call('updateQuantity', $foreignItem->id, 7)
            ->assertOk();

        // Fail closed: the foreign line is untouched and the acting cart
        // neither gained a line nor had one stolen from it.
        $this->assertSame(1, $foreignItem->fresh()->quantity);
        $this->assertSame(1, $foreignCart->fresh()->items()->count());
        $this->assertDatabaseCount('cart_items', 1);
    }

    public function test_remove_item_cannot_delete_an_item_from_another_cart(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);

        $foreignCart = CartModel::create(['customer_ref' => null, 'session_ref' => 'another-session']);
        $foreignItem = (new AddToCart)->handle($foreignCart, $listing, 1);

        Livewire::test(Cart::class)
            ->call('removeItem', $foreignItem->id)
            ->assertOk();

        $this->assertSame(1, $foreignItem->fresh()->quantity);
        $this->assertDatabaseHas('cart_items', ['id' => $foreignItem->id]);
    }

    public function test_update_quantity_changes_the_quantity_of_an_item_in_own_cart(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);

        $component = Livewire::test(Cart::class)->call('addListing', $listing->id, 1, null);

        $item = CartModel::where('session_ref', session()->getId())->firstOrFail()->items()->firstOrFail();

        $component->call('updateQuantity', $item->id, 4)->assertOk();

        $this->assertSame(4, $item->fresh()->quantity);
    }

    public function test_remove_item_deletes_an_item_from_own_cart(): void
    {
        $listing = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);

        $component = Livewire::test(Cart::class)->call('addListing', $listing->id, 1, null);

        $item = CartModel::where('session_ref', session()->getId())->firstOrFail()->items()->firstOrFail();

        $component->call('removeItem', $item->id)->assertOk();

        $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
    }

    public function test_replacing_the_cart_leaves_another_sessions_cart_untouched(): void
    {
        $a = $this->listing('Vendor A', ProductCode::FLOWER_BOARD);
        $b = $this->listing('Vendor B', ProductCode::GRAVESTONE_GRANITE);

        $foreignCart = CartModel::create(['customer_ref' => null, 'session_ref' => 'another-session']);
        $foreignItem = (new AddToCart)->handle($foreignCart, $a, 2);

        Livewire::test(Cart::class)
            ->call('addListing', $a->id, 1, null)
            ->call('addListing', $b->id, 1, null)
            ->call('resolveConflictByReplacing')
            ->assertSet('conflictOpen', false);

        // The acting cart swapped to vendor B; the other session's cart is untouched.
        $actingCart = CartModel::where('session_ref', session()->getId())->firstOrFail();
        $this->assertSame($b->vendor_id, $actingCart->fresh()->vendor_id);
        $this->assertSame(1, $actingCart->fresh()->items()->count());
        $this->assertSame(2, $foreignItem->fresh()->quantity);
        $this->assertSame($a->vendor_id, $foreignCart->fresh()->vendor_id);
    }
}
