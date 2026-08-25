<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `cart_items` — see the migration
 * (`2026_08_12_100060_create_cart_items_table.php`) for why
 * `unit_price_minor`/`price_version` are frozen at add time.
 */
final class CartItem extends Model
{
    protected $table = 'cart_items';

    /** @var list<string> */
    protected $fillable = [
        'cart_id',
        'vendor_listing_id',
        'product_variant_id',
        'quantity',
        'unit_price_minor',
        'price_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'price_version' => 'integer',
        ];
    }

    /** @return BelongsTo<Cart, $this> */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class, 'cart_id');
    }

    /** @return BelongsTo<VendorListing, $this> */
    public function listing(): BelongsTo
    {
        return $this->belongsTo(VendorListing::class, 'vendor_listing_id');
    }

    /** @return BelongsTo<ProductVariant, $this> */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
    }

    public function lineTotal(): Money
    {
        return new Money((int) $this->unit_price_minor * (int) $this->quantity);
    }
}
