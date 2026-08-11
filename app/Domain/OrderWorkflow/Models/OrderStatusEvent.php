<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `order_status_events` — see
 * `2026_08_12_100010_create_order_status_events_table.php` for the schema
 * and, in particular, for the `order_status_events_paid_once` partial
 * unique index that is this lane's load-bearing invariant.
 *
 * Rows are written ONLY by
 * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` in normal
 * operation. This model itself does not enforce that (unlike
 * `PaymentVerification`'s `saving()` hook) because
 * `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTest.php::
 * test_at_most_one_paid_event_can_exist_per_order` deliberately inserts a
 * second row directly via `OrderStatusEvent::query()->create([...])` to
 * prove the DATABASE — not the application — rejects it. Blocking that
 * path at the model would test something else entirely (`task-2-brief.md`
 * ambiguity 3).
 */
final class OrderStatusEvent extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $table = 'order_status_events';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'from_status',
        'to_status',
        'actor_ref',
        'actor_role',
        'reason',
        'metadata',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    protected $attributes = [
        'metadata' => '{}',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
