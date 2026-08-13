<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `service_areas` — see the migration
 * (`2026_08_12_100030_create_service_areas_table.php`) for full schema
 * reasoning, including why `area_code` is a vendor-supplied free string and
 * not a platform region taxonomy.
 */
final class ServiceArea extends Model
{
    protected $table = 'service_areas';

    /** @var list<string> */
    protected $fillable = [
        'vendor_id',
        'area_code',
        'area_label',
        'delivery_fee_minor',
        'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'delivery_fee_minor' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    public function deliveryFeeMoney(): Money
    {
        return new Money((int) $this->delivery_fee_minor);
    }
}
