<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Exceptions;

use RuntimeException;

/**
 * Thrown by `PlaceMarketplaceOrder` when a cart line's frozen
 * `unit_price_minor`/`price_version` no longer matches its listing
 * (PUB-022's changed-price state). The customer must explicitly reconfirm —
 * checkout never silently recharges at the new price.
 */
final class CartPricingChangedException extends RuntimeException {}
