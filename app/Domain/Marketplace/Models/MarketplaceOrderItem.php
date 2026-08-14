<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `marketplace_order_items` — a frozen line snapshot at
 * placement time. See the migration
 * (`2026_08_12_100080_create_marketplace_order_items_table.php`).
 */
final class MarketplaceOrderItem extends Model
{
    protected $table = 'marketplace_order_items';

    /** @var list<string> */
    protected $fillable = [
        'marketplace_order_id',
        'vendor_listing_id',
        'product_id',
        'product_variant_id',
        'quantity',
        'unit_price_minor',
        'line_total_minor',
        'price_version',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'line_total_minor' => 'integer',
            'price_version' => 'integer',
        ];
    }

    /** @return BelongsTo<MarketplaceOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(MarketplaceOrder::class, 'marketplace_order_id');
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
        return new Money((int) $this->line_total_minor);
    }
}
