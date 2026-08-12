<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `vendor_listings` — see the migration
 * (`2026_08_12_100020_create_vendor_listings_table.php`) for full schema
 * reasoning. One vendor's offer of one catalogue product, carrying every
 * field requirement 2 names that `products` does not: availability mode,
 * stock, production lead time, cancellation policy, and evidence
 * requirement.
 */
final class VendorListing extends Model
{
    protected $table = 'vendor_listings';

    /** @var list<string> */
    protected $fillable = [
        'vendor_id',
        'product_id',
        'price_minor',
        'price_version',
        'availability_mode',
        'stock_quantity',
        'production_lead_time_days',
        'cancellation_policy',
        'evidence_requirement',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_minor' => 'integer',
            'price_version' => 'integer',
            'stock_quantity' => 'integer',
            'production_lead_time_days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $listing): void {
            AvailabilityMode::assertKnown($listing->availability_mode);
            EvidenceRequirement::assertKnown($listing->evidence_requirement);
        });
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'product_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function scopeForProduct(Builder $query, int $productId): void
    {
        $query->where('product_id', $productId);
    }

    public function priceMoney(): Money
    {
        return new Money((int) $this->price_minor);
    }
}
