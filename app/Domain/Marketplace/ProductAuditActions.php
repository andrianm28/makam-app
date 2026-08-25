<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

/**
 * The audit action names for the admin panel's writes to `products` —
 * written by `App\Filament\Admin\Resources\ProductResource\Pages\*` via
 * `Audit::record()` (through `Audit::wrap()`), the only write paths the
 * ProductResource exposes.
 *
 * ---------------------------------------------------------------------------
 * One of the two is on `SensitiveActions::ACTIONS` — deliberately
 * ---------------------------------------------------------------------------
 * `UPDATED` (`PRODUCT_UPDATED`) is sensitive-listed, `CREATED`
 * (`PRODUCT_CREATED`) is not. The line is the same one the ServiceCatalog
 * lane draws with `PRICE_VERSION_RECORDED`/`SERVICE_DEFINITION_PRICE_
 * VERSION_RECORDED`: editing an existing product's definition — including
 * its base price, which bumps `price_version` to a new cut of that
 * definition — is a money write over a published state, so a recorded
 * justification is mandatory. Creating a brand-new row is the first cut
 * (version 1), the same non-sensitive category as FAQ article creation.
 * See `SensitiveActions::ACTIONS`'s own entry for the full reasoning.
 */
final class ProductAuditActions
{
    public const string CREATED = 'PRODUCT_CREATED';

    public const string UPDATED = 'PRODUCT_UPDATED';
}
