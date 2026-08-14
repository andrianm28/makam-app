<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Models\Cart;
use App\Domain\Marketplace\Models\CartItem;
use App\Domain\Marketplace\Models\VendorListing;
use Illuminate\Support\Facades\DB;

/**
 * The EXPLICIT single-vendor resolution the user chose in the §3.4 conflict
 * modal: clear the current cart and re-lock it to the incoming vendor's
 * listing. This is the only action that may clear a cart — nothing else
 * drops items, and `AddToCart` never auto-replaces (requirement 4,
 * `marketplace-catalog.md` §"MVP operating constraint").
 */
final class ReplaceCartWithVendor
{
    public function handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartItem
    {
        return DB::transaction(function () use ($cart, $listing, $quantity, $variantId): CartItem {
            $cart->items()->delete();
            $cart->update(['vendor_id' => null]);

            $result = (new AddToCart)->handle($cart, $listing, $quantity, $variantId);

            if (! $result instanceof CartItem) {
                // Unreachable: the lock was just released, so the add cannot
                // conflict. Kept loud rather than silent.
                throw new \LogicException('ReplaceCartWithVendor re-encountered a vendor conflict after clearing the cart.');
            }

            return $result;
        });
    }
}
