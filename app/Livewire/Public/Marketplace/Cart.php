<?php

declare(strict_types=1);

namespace App\Livewire\Public\Marketplace;

use App\Domain\Marketplace\Actions\AddToCart;
use App\Domain\Marketplace\Actions\RemoveCartItem;
use App\Domain\Marketplace\Actions\ReplaceCartWithVendor;
use App\Domain\Marketplace\Actions\UpdateCartItem;
use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\Models\Cart as CartModel;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\VendorListing;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/marketplace/keranjang` — PUB-022, the cart screen.
 *
 * One vendor per checkout (requirement 4), made explicit: a second vendor's
 * `addListing` does NOT mutate the cart — `AddToCart` returns a
 * `CartConflict`, the component opens the §3.4 conflict modal, and only an
 * explicit `resolveConflictByReplacing` (or "finish this order first",
 * which keeps the cart untouched) resolves it. Items are never silently
 * dropped (`marketplace-catalog.md` §"MVP operating constraint").
 *
 * Cart identity: an authenticated customer's reference when present,
 * otherwise the server-side session id (`carts.session_ref`). A cart id is
 * never trusted from the request — the cart is resolved from the session,
 * never by client input.
 *
 * The changed-price state (design-system §6.2/§6.3): each line freezes
 * `unit_price_minor`/`price_version` at add time; when `hasStalePricing()`
 * is true the view renders the "Harga berubah" alert and checkout stays
 * disabled until the customer explicitly reconfirms via `reconfirmPricing`.
 */
final class Cart extends Component
{
    public ?bool $conflictOpen = false;

    /**
     * The flattened `CartConflict` for the view.
     *
     * @var array{existingVendorId: string, existingVendorName: string, incomingVendorId: string, incomingVendorName: string, existingItemCount: int}|null
     */
    public ?array $conflict = null;

    public ?int $pendingListingId = null;

    public ?int $pendingQuantity = null;

    public ?int $pendingVariantId = null;

    public function addListing(int $listingId, int $quantity, ?int $variantId = null): void
    {
        $listing = VendorListing::query()->find($listingId);

        if (! $listing instanceof VendorListing) {
            return;
        }

        $result = (new AddToCart)->handle($this->cart(), $listing, $quantity, $variantId);

        if ($result instanceof CartConflict) {
            $this->conflict = [
                'existingVendorId' => $result->existingVendorId,
                'existingVendorName' => $result->existingVendorName,
                'incomingVendorId' => $result->incomingVendorId,
                'incomingVendorName' => $result->incomingVendorName,
                'existingItemCount' => $result->existingItemCount,
            ];
            $this->conflictOpen = true;
            $this->pendingListingId = $listingId;
            $this->pendingQuantity = $quantity;
            $this->pendingVariantId = $variantId;

            return;
        }

        // A clean add clears any leftover conflict state.
        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;
        $this->pendingQuantity = null;
        $this->pendingVariantId = null;
    }

    /**
     * The explicit resolution the customer chose in the conflict modal:
     * clears the current cart and re-locks it to the incoming vendor. The
     * only action that may clear a cart.
     */
    public function resolveConflictByReplacing(): void
    {
        if ($this->pendingListingId === null) {
            return;
        }

        $listing = VendorListing::query()->find($this->pendingListingId);

        if (! $listing instanceof VendorListing) {
            return;
        }

        (new ReplaceCartWithVendor)->handle(
            $this->cart(),
            $listing,
            $this->pendingQuantity ?? 1,
            $this->pendingVariantId,
        );

        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;
        $this->pendingQuantity = null;
        $this->pendingVariantId = null;
    }

    /** Closes the modal WITHOUT touching the cart — the existing items stay. */
    public function dismissConflict(): void
    {
        $this->conflict = null;
        $this->conflictOpen = false;
        $this->pendingListingId = null;
        $this->pendingQuantity = null;
        $this->pendingVariantId = null;
    }

    public function updateQuantity(int $itemId, int $quantity): void
    {
        $item = CartItem::query()->find($itemId);

        if ($item instanceof CartItem) {
            (new UpdateCartItem)->handle($item, $quantity);
        }
    }

    public function removeItem(int $itemId): void
    {
        $item = CartItem::query()->find($itemId);

        if ($item instanceof CartItem) {
            (new RemoveCartItem)->handle($item);
        }
    }

    /** The customer's explicit accept of the new prices — refreshes the frozen pair from each listing. */
    public function reconfirmPricing(): void
    {
        foreach ($this->cart()->items()->with('listing')->get() as $item) {
            $item->update([
                'unit_price_minor' => $item->listing->price_minor,
                'price_version' => $item->listing->price_version,
            ]);
        }
    }

    public function render(): View
    {
        $cart = $this->cart();
        $items = $cart->items()->with(['listing.product', 'listing.vendor'])->get();

        return view('livewire.public.marketplace.cart', [
            'cart' => $cart,
            'items' => $items,
            'hasStalePricing' => $cart->hasStalePricing(),
        ])->layout('layouts.app', [
            'title' => 'Keranjang - Layanan Pemakaman - Makam.co.id',
            'active' => 'layanan',
        ]);
    }

    private function cart(): CartModel
    {
        $authenticated = auth()->check();

        return CartModel::query()->firstOrCreate([
            'customer_ref' => $authenticated ? (string) auth()->id() : null,
            'session_ref' => $authenticated ? null : session()->getId(),
        ]);
    }
}
