<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\OrderWorkflow\Exceptions\OrderStatusEventIsAppendOnlyException;
use Illuminate\Database\Eloquent\Builder;
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
 * operation.
 *
 * ---------------------------------------------------------------------------
 * Append-only: what the overrides below do and do NOT stop, and why
 * `create()` is deliberately excluded
 * ---------------------------------------------------------------------------
 * Same shape as `App\Platform\Payment\Models\PaymentIntent` and
 * `App\Platform\DocumentVault\Models\DocumentScan`: `update()`,
 * `performUpdate()`, and `delete()` throw unconditionally.
 *
 * `create()` is NOT guarded, and that is load-bearing rather than an
 * oversight. `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTest.php::
 * test_at_most_one_paid_event_can_exist_per_order` deliberately inserts a
 * second `DIBAYAR` row directly via `OrderStatusEvent::query()->create([...])`
 * to prove the DATABASE — not the application — rejects it. A model-level
 * refusal on `create()` would make that test pass for the wrong reason and
 * would convert this lane's load-bearing database assertion into an
 * assertion about a PHP `if` (`task-2-brief.md` ambiguity 3).
 *
 * These overrides stop `$event->update([...])`, `$event->reason = ...;
 * $event->save()` on an already-persisted instance, and `$event->delete()` —
 * i.e. every path that could revise or erase recorded evidence, which is the
 * half `create()` does not cover.
 *
 * They do NOT stop `OrderStatusEvent::query()->update([...])`,
 * `DB::table('order_status_events')->delete()`, raw SQL, or any process with
 * direct database credentials — those never pass through this class. Stated
 * plainly rather than assumed closed.
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

    /**
     * Always throws — see the class-level doc block.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw OrderStatusEventIsAppendOnlyException::forOperation('update');
    }

    /**
     * Always throws. Blocks `$event->reason = ...; $event->save();` on an
     * already-persisted instance, which routes here rather than through
     * `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw OrderStatusEventIsAppendOnlyException::forOperation('performUpdate');
    }

    /**
     * Always throws — see the class-level doc block. Deleting a `DIBAYAR`
     * row would also release `order_status_events_paid_once` for that
     * order.
     */
    public function delete(): ?bool
    {
        throw OrderStatusEventIsAppendOnlyException::forOperation('delete');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
