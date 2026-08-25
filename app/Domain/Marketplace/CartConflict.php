<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * Describes a single-vendor checkout clash (requirement 4) WITHOUT resolving
 * it. `AddToCart` returns this instead of throwing or auto-replacing, because
 * `marketplace-catalog.md` §"MVP operating constraint" requires the UI to
 * offer separate checkout or an explicit split and forbids silently losing
 * items. Resolution is the caller's decision, never this layer's.
 */
final readonly class CartConflict
{
    public function __construct(
        public string $existingVendorId,
        public string $existingVendorName,
        public string $incomingVendorId,
        public string $incomingVendorName,
        public int $existingItemCount,
    ) {}
}
