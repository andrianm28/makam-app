<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\CartConflict;
use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\VendorListing;
use Illuminate\Support\Facades\DB;

/**
 * Adds a listing to a cart, or reports a single-vendor conflict.
 *
 * Returns `CartConflict` — it does NOT throw and does NOT mutate — when the
 * cart is already locked to a different vendor. Requirement 4 requires the
 * constraint be made explicit to the user, and the catalogue forbids silently
 * losing items, so the decision belongs to the caller.
 */
final class AddToCart
{
    public function handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartConflict|CartItem
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('Quantity must be at least 1.');
        }

        if ($cart->vendor_id !== null && $cart->vendor_id !== $listing->vendor_id) {
            return new CartConflict(
                existingVendorId: $cart->vendor_id,
                existingVendorName: $cart->vendor->name,
                incomingVendorId: $listing->vendor_id,
                incomingVendorName: $listing->vendor->name,
                existingItemCount: $cart->items()->count(),
            );
        }

        return DB::transaction(function () use ($cart, $listing, $quantity, $variantId): CartItem {
            if ($cart->vendor_id === null) {
                $cart->update(['vendor_id' => $listing->vendor_id]);
            }

            $existing = $cart->items()
                ->where('vendor_listing_id', $listing->id)
                ->where('product_variant_id', $variantId)
                ->first();

            if ($existing !== null) {
                $existing->update(['quantity' => (int) $existing->quantity + $quantity]);

                return $existing;
            }

            return $cart->items()->create([
                'vendor_listing_id' => $listing->id,
                'product_variant_id' => $variantId,
                'quantity' => $quantity,
                'unit_price_minor' => $listing->price_minor,
                'price_version' => $listing->price_version,
            ]);
        });
    }
}
