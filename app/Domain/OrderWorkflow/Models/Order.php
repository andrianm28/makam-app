<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Exceptions\OrderIsGuardedException;
use App\Domain\OrderWorkflow\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
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
 *
 * ---------------------------------------------------------------------------
 * The write guard: what the overrides below do and do NOT stop
 * ---------------------------------------------------------------------------
 * The paragraph above used to be the ONLY thing enforcing it. It is now a
 * structural guard, because `orders.status` is the one column in this lane
 * whose exactly-once guarantee has no database backstop of its own:
 * `order_status_events_paid_once` is a partial unique index on
 * `order_status_events`, so `$order->update(['status' => 'DIBAYAR'])` never
 * meets it. An order at `DIBAYAR` with no event row, no audit row, and no
 * outbox row is a money bug.
 *
 * Same shape as `App\Platform\Payment\Models\PaymentIntent` (`update()`,
 * `performUpdate()`, `delete()` overridden; `create()` deliberately left
 * alone), with one difference that model does not need: `orders` rows DO
 * legitimately change, so `performUpdate()` is not an unconditional throw —
 * it admits exactly one caller, `applyStatus()`, via the private
 * authorization flag `App\Platform\DocumentVault\Models\Document`'s
 * `writeState()`/`promote()` pair already uses for the same purpose (set
 * the flag, write, clear it in `finally`).
 *
 * These overrides stop `$order->update([...])`, `$order->status = ...;
 * $order->save()` on an already-persisted instance, and `$order->delete()`.
 *
 * They do NOT stop `Order::query()->update([...])`,
 * `DB::table('orders')->update(...)`, raw SQL, or any process with direct
 * database credentials — those never pass through this class. Stated
 * plainly rather than assumed closed, exactly as `PaymentIntent`'s own doc
 * block states it. Closing the bulk-update path would need a PostgreSQL
 * trigger; that is not this task's scope and is recorded here so the gap is
 * a known one rather than an assumed-absent one.
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

    /**
     * Set only for the duration of `applyStatus()`. `performUpdate()` is an
     * unconditional refusal for every other caller.
     */
    private bool $statusWriteAuthorized = false;

    public function status(): OrderStatus
    {
        return OrderStatus::from($this->status);
    }

    /**
     * The ONE door through which `orders.status` moves.
     *
     * Called only by
     * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange`, inside the
     * transaction `Audit::wrap()` opens, and only AFTER
     * `OrderTransition::assertAllowed()` has passed. This method deliberately
     * does NOT re-check the transition graph: doing so here would put a
     * second copy of the rule in a second place, and the Action is the only
     * caller that can also write the paired event, audit, and outbox rows.
     */
    public function applyStatus(OrderStatus $to): void
    {
        $this->statusWriteAuthorized = true;

        try {
            $this->forceFill(['status' => $to->value]);
            $this->save();
        } finally {
            $this->statusWriteAuthorized = false;
        }
    }

    /**
     * Always throws — see the class-level doc block.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw OrderIsGuardedException::forOperation('update');
    }

    /**
     * Throws for every caller except `applyStatus()`. Blocks
     * `$order->status = ...; $order->save();` on an already-persisted
     * instance, which routes here rather than through `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        if (! $this->statusWriteAuthorized) {
            throw OrderIsGuardedException::forOperation('performUpdate');
        }

        return parent::performUpdate($query);
    }

    /**
     * Always throws — see the class-level doc block. An order with status
     * history must not be deletable; `order_status_events.order_id` is
     * `restrictOnDelete()` for the same reason.
     */
    public function delete(): ?bool
    {
        throw OrderIsGuardedException::forOperation('delete');
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
