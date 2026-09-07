<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\Actions\ScanProductPhoto;
use App\Domain\Marketplace\Exceptions\ProductMustHavePhotoToActivateException;
use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\ProductCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `products` — see the migration
 * (`2026_07_26_180000_create_products_table.php`) for full schema reasoning,
 * including the resolution of the OPEN category-code question
 * (`App\Domain\Marketplace\MarketplaceProductCategory`) and the deliberate
 * absence of stock/schedule/service-area/etc. columns in this batch (still
 * genuinely absent — those remain future vendor-listing-table concerns).
 *
 * `vendor_name` and `photo_path` were added later by
 * `2026_07_26_200100_add_dummy_vendor_pricing_and_photo_to_products.php`,
 * which also backfilled `base_price_idr`. Read that migration's own doc
 * block before treating either column as real: both are explicitly
 * user-authorized PLACEHOLDER data for public dev display, a single
 * denormalized string each (no `vendors` table exists), not a real
 * multi-vendor model — a future real-vendor batch is expected to replace
 * both wholesale.
 *
 * One of exactly nine rows, seeded by
 * `2026_07_26_180200_seed_marketplace_products_and_variants.php` from
 * `docs/product/marketplace-catalog.md` — this batch builds no admin
 * create/delete action for the same reason `FaqCategory` builds none: the
 * catalogue is master data owned by a product decision, not free-form
 * admin-editable content, at this stage of the build.
 *
 * The `saving` hook also refuses `is_active = true` with a blank
 * `photo_path` (`ProductMustHavePhotoToActivateException`) — a go-live
 * photo gate, closing the latent gap a competitive scan of makamia.id found
 * live there: real, priced, purchasable listings with placeholder/missing
 * photos. `photo_path` being a hand-typed free-text field with no relation
 * to `is_active` meant nothing here prevented the same thing.
 *
 * ---------------------------------------------------------------------------
 * VAULT-02 — the `saving` hook is also this field's ONE malware-scan gate
 * ---------------------------------------------------------------------------
 * `photo_path` uploads bypass the platform document vault entirely (public,
 * unauthenticated content — see `ProductForm`'s doc block), which used to
 * mean an admin/vendor-uploaded photo reached the public disk with NO
 * malware scan at all — the only upload path in this codebase that skipped
 * one. Every write to `photo_path` (a NEW upload; the field is
 * `dehydrated()` only when a file actually changed) now runs through
 * `Actions\ScanProductPhoto` here, before save: a non-CLEAN verdict deletes
 * the file from the public disk and throws
 * `Exceptions\ProductPhotoFailedScanException`, so a bad file is never left
 * reachable and the row is never saved pointing at it. See ADR-0023's
 * VAULT-02 amendment for why this stays "scanned but not quarantined"
 * rather than routed through the vault.
 */
final class Product extends Model
{
    protected $table = 'products';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'category',
        'vendor_name',
        'name',
        'description',
        'photo_path',
        'base_price_idr',
        'price_version',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'base_price_idr' => 'integer',
            'price_version' => 'integer',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $product): void {
            ProductCode::assertKnown($product->code);
            MarketplaceProductCategory::assertKnown($product->category);

            if ($product->isDirty('photo_path') && self::hasPhoto($product)) {
                app(ScanProductPhoto::class)->scan((string) $product->photo_path);
            }

            if ($product->is_active && ! self::hasPhoto($product)) {
                throw ProductMustHavePhotoToActivateException::forProduct($product->code);
            }
        });
    }

    private static function hasPhoto(self $product): bool
    {
        return $product->photo_path !== null && trim($product->photo_path) !== '';
    }

    /**
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class, 'product_id')->orderBy('sort_order');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeInCategory(Builder $query, string $category): void
    {
        $query->where('category', $category);
    }

    /**
     * The stable public/admin presentation order — the catalogue's own
     * listed order, mirrored into `sort_order` at seed time.
     */
    public function scopeOrderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order');
    }

    public static function findByCode(string $code): ?self
    {
        return self::query()->where('code', $code)->first();
    }

    /**
     * Whether this product's code is expected to carry `product_variants`
     * rows — the three Batu Nisan codes only. See
     * `App\Domain\Marketplace\ProductCode::GRAVESTONE_CODES`'s own doc
     * block.
     */
    public function hasVariantAxes(): bool
    {
        return ProductCode::requiresVariants($this->code);
    }

    public function categoryLabel(): string
    {
        return MarketplaceProductCategory::label($this->category);
    }
}
