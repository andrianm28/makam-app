<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\Models\CartItem;

/**
 * Removes one cart line, then releases the cart's vendor lock when the cart
 * becomes empty (PUB-022 line removal).
 */
final class RemoveCartItem
{
    public function handle(CartItem $item): void
    {
        $cart = $item->cart;
        $item->delete();
        $cart->releaseVendorLockIfEmpty();
    }
}
