<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * The audit action names for the admin panel's writes to `product_variants`
 * — written by `App\Filament\Admin\Resources\ProductResource\RelationManagers
 * \VariantsRelationManager` through `Audit::wrap()`, the only write path the
 * admin panel exposes to this table.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT on `SensitiveActions::ACTIONS` — either constant
 * ---------------------------------------------------------------------------
 * The `PRODUCT_UPDATED` sensitive-listing the `products` lane drew (a base
 * price edit is a money write over a published definition, so a recorded
 * justification is mandatory) does not transfer here: a `product_variants`
 * row carries no price and no published state — it is a catalogue
 * presentation attribute (size/material/color/calligraphy axes), the same
 * non-sensitive category as FAQ article editing. If a future batch ever
 * adds price deltas or publish states to variants, revisit this listing.
 * See `SensitiveActions::ACTIONS` for the full reasoning.
 */
final class ProductVariantAuditActions
{
    public const string CREATED = 'PRODUCT_VARIANT_CREATED';

    public const string UPDATED = 'PRODUCT_VARIANT_UPDATED';
}
