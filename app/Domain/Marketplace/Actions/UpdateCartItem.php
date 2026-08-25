<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Models\CartItem;

/**
 * Mutates one cart line's quantity. A quantity below 1 removes the line and
 * releases the cart's vendor lock when the cart becomes empty — so an
 * emptied cart is immediately addable from another vendor without a
 * conflict (PUB-022 quantity stepper).
 */
final class UpdateCartItem
{
    public function handle(CartItem $item, int $quantity): void
    {
        if ($quantity < 1) {
            $cart = $item->cart;
            $item->delete();
            $cart->releaseVendorLockIfEmpty();

            return;
        }

        $item->update(['quantity' => $quantity]);
    }
}
