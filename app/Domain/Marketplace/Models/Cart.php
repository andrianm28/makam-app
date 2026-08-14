<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `carts` — see the migration
 * (`2026_08_12_100050_create_carts_table.php`) for why `vendor_id` is the
 * single-vendor lock and why that lock must never become a set without the
 * requirement-14 prerequisites.
 */
final class Cart extends Model
{
    use HasUuids;

    protected $table = 'carts';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['customer_ref', 'session_ref', 'vendor_id'];

    /** @return HasMany<CartItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class, 'cart_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function subtotal(): Money
    {
        $total = 0;
        foreach ($this->items as $item) {
            $total += (int) $item->unit_price_minor * (int) $item->quantity;
        }

        return new Money($total);
    }

    /** True when any line's frozen price no longer matches its listing (PUB-022 reconfirmation). */
    public function hasStalePricing(): bool
    {
        foreach ($this->items()->with('listing')->get() as $item) {
            if ((int) $item->price_version !== (int) $item->listing->price_version
                || (int) $item->unit_price_minor !== (int) $item->listing->price_minor) {
                return true;
            }
        }

        return false;
    }

    public function releaseVendorLockIfEmpty(): void
    {
        if ($this->items()->count() === 0 && $this->vendor_id !== null) {
            $this->update(['vendor_id' => null]);
        }
    }
}
