<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Models;

use App\Domain\Marketplace\PaymentState;
use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `marketplace_orders` — the customer-side order root of
 * a marketplace checkout. See the migration
 * (`2026_08_12_100070_create_marketplace_orders_table.php`) for the schema
 * reasoning, and `2026_08_12_110015` for the link to L10's `vendor_orders`.
 */
final class MarketplaceOrder extends Model
{
    use HasUuids;

    protected $table = 'marketplace_orders';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'order_number',
        'customer_ref',
        'entity_ref',
        'vendor_id',
        'subtotal_minor',
        'delivery_fee_minor',
        'scheduled_for',
        'total_minor',
        'payment_state',
        'idempotency_key',
        'placed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'subtotal_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'scheduled_for' => 'immutable_date',
            'total_minor' => 'integer',
            'placed_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $order): void {
            PaymentState::assertKnown($order->payment_state);
        });
    }

    /** @return HasMany<MarketplaceOrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(MarketplaceOrderItem::class, 'marketplace_order_id');
    }

    /**
     * The vendor-allocation rows on L10's `vendor_orders`, linked via the
     * additive `marketplace_order_id` column (Ruling 1, 14 Aug 2026).
     *
     * @return HasMany<VendorOrder, $this>
     */
    public function vendorOrders(): HasMany
    {
        return $this->hasMany(VendorOrder::class, 'marketplace_order_id');
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'vendor_id');
    }

    public function total(): Money
    {
        return new Money((int) $this->total_minor);
    }

    /**
     * Customer-scoped read (Task 9's `MarketplaceOrderQuery` composes this):
     * an order number belonging to another customer is indistinguishable
     * from one that never existed — no "belongs to another customer" branch
     * may ever be built on top of this scope.
     */
    public function scopeForCustomer(Builder $query, string $customerRef): void
    {
        $query->where('customer_ref', $customerRef);
    }
}
