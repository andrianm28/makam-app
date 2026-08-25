<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\VendorProcessingStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class VendorOrder extends Model
{
    protected $table = 'vendor_orders';

    protected $fillable = [
        'uuid',
        'marketplace_order_id',
        'vendor_id',
        'listing_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $order): void {
            VendorProcessingStatus::assertKnown($order->status);
        });
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function listing(): BelongsTo
    {
        return $this->belongsTo(VendorListing::class, 'listing_id');
    }

    public function evidences(): HasMany
    {
        return $this->hasMany(VendorOrderEvidence::class, 'vendor_order_id');
    }

    public function scopeForVendor(Builder $query, string $vendorId): void
    {
        $query->where('vendor_id', $vendorId);
    }

    public function scopeWithStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    public function isPending(): bool
    {
        return $this->status === VendorProcessingStatus::MENUNGGU_VENDOR;
    }

    public function isCompleted(): bool
    {
        return $this->status === VendorProcessingStatus::SELESAI;
    }

    public function isCancelled(): bool
    {
        return $this->status === VendorProcessingStatus::DIBATALKAN;
    }
}
