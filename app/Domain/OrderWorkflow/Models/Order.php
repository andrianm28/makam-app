<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\OrderStatus;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `orders` — see
 * `2026_08_12_100000_create_orders_table.php` for the schema.
 *
 * `status` is changed ONLY by
 * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` — never by a
 * direct `$order->status = ...; $order->save()` or
 * `Order::query()->update(['status' => ...])` anywhere else in this
 * codebase. That Action is what enforces `OrderTransition::assertAllowed()`,
 * the audit pairing, and the outbox event; writing `status` any other way
 * skips all three silently.
 */
final class Order extends Model
{
    use HasUuids;

    protected $table = 'orders';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'reference',
        'product_type',
        'status',
        'booking_draft_id',
        'funeral_case_id',
        'pre_need_case_id',
        'paid_via',
        'paid_source_ref',
        'correlation_id',
    ];

    public function status(): OrderStatus
    {
        return OrderStatus::from($this->status);
    }

    public function bookingDraft(): BelongsTo
    {
        return $this->belongsTo(BookingDraft::class, 'booking_draft_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class, 'order_id');
    }
}
