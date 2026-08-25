<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Eloquent model for `product_variants` — see the migration
 * (`2026_07_26_180100_create_product_variants_table.php`) for the full
 * scoping reasoning (Batu Nisan / `GRAVESTONE_*` products only, and why
 * `FLOWER_*`/`GRAVE_CARE_*` deliberately carry zero variant rows in this
 * batch).
 *
 * ---------------------------------------------------------------------------
 * THE GUARANTEE — a variant can never attach to a non-gravestone product
 * ---------------------------------------------------------------------------
 * `booted()` below enforces, on every save, that the owning `Product`'s code
 * is one of `App\Domain\Marketplace\ProductCode::GRAVESTONE_CODES`. This
 * mirrors the rigor `App\Domain\Faq\Models\FaqArticle`'s AC6 guarantee
 * applies to publish-state leakage: the catalogue-text-driven scoping
 * decision this batch made is not just a seed-time convention a future
 * caller could accidentally violate — it is a structural invariant checked
 * on every write, including from a future admin action this batch does not
 * itself build.
 */
final class ProductVariant extends Model
{
    protected $table = 'product_variants';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'product_id',
        'size',
        'material',
        'color',
        'calligraphy_style',
        'inscription_text_example',
        'preview_image_path',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'product_id' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $variant): void {
            $product = $variant->relationLoaded('product')
                ? $variant->product
                : Product::query()->find($variant->product_id);

            if ($product === null) {
                throw new InvalidArgumentException(
                    "Cannot save a product_variants row without a resolvable product_id [{$variant->product_id}]."
                );
            }

            if (! $product->hasVariantAxes()) {
                throw new InvalidArgumentException(
                    "Product [{$product->code}] does not carry variant axes in this batch — only ".
                    'the Batu Nisan (GRAVESTONE_*) products do. See ProductVariant\'s class-level doc block.'
                );
            }
        });
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeOrderedForDisplay(Builder $query): void
    {
        $query->orderBy('sort_order');
    }
}
