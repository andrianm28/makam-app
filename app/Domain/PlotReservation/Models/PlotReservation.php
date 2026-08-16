<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Models;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Exceptions\PlotReservationIsAppendOnlyException;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `plot_reservations` — see
 * `2026_08_16_100020_create_plot_reservations_table.php` for the schema
 * and, in particular, for the `plot_reservations_active_hold` partial
 * unique index that is this lane's load-bearing invariant.
 *
 * Rows are written ONLY by
 * `App\Domain\PlotReservation\Actions\ReservePlot` (a `held` row) and
 * the lifecycle actions (confirmed/released/expired rows) in normal
 * operation — every transition inserts a NEW row; nothing updates or
 * deletes an existing one.
 *
 * ---------------------------------------------------------------------------
 * Append-only: what the overrides below do and do NOT stop, and why
 * `create()` is deliberately excluded
 * ---------------------------------------------------------------------------
 * Same shape as `App\Domain\OrderWorkflow\Models\OrderStatusEvent`:
 * `update()`, `performUpdate()`, and `delete()` throw unconditionally.
 *
 * `create()` is NOT guarded, and that is load-bearing rather than an
 * oversight. `tests/Feature/Domain/PlotReservation/ReservePlotTest.php::
 * test_duplicate_active_hold_is_classified_as_conflict` deliberately
 * inserts a second `held` row directly via
 * `PlotReservation::query()->create([...])` to prove the DATABASE — not
 * the application — rejects it. A model-level refusal on `create()`
 * would make that test pass for the wrong reason and would convert this
 * lane's load-bearing database assertion into an assertion about a PHP
 * `if` (the `OrderStatusEvent` class doc block records the identical
 * reasoning for its lane).
 *
 * These overrides stop `$reservation->update([...])`,
 * `$reservation->state = ...; $reservation->save()` on an
 * already-persisted instance, and `$reservation->delete()` — i.e. every
 * path that could revise or erase recorded evidence, which is the half
 * `create()` does not cover.
 *
 * They do NOT stop `PlotReservation::query()->update([...])`,
 * `DB::table('plot_reservations')->delete()`, raw SQL, or any process
 * with direct database credentials — those never pass through this
 * class. Stated plainly rather than assumed closed.
 */
final class PlotReservation extends Model
{
    use HasUuids;

    protected $table = 'plot_reservations';

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'plot_id',
        'order_id',
        'state',
        'reserved_by_ref',
        'reason',
        'reserved_at',
        'confirmed_at',
        'released_at',
        'expired_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reserved_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'expired_at' => 'immutable_datetime',
        ];
    }

    /**
     * Always throws — see the class-level doc block.
     *
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $options
     */
    public function update(array $attributes = [], array $options = []): bool
    {
        throw PlotReservationIsAppendOnlyException::forOperation('update');
    }

    /**
     * Always throws. Blocks `$reservation->state = ...; $reservation->save();`
     * on an already-persisted instance, which routes here rather than
     * through `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        throw PlotReservationIsAppendOnlyException::forOperation('performUpdate');
    }

    /**
     * Always throws — see the class-level doc block. Deleting a `held`
     * row would also release `plot_reservations_active_hold` for that
     * plot.
     */
    public function delete(): ?bool
    {
        throw PlotReservationIsAppendOnlyException::forOperation('delete');
    }

    public function plot(): BelongsTo
    {
        return $this->belongsTo(GravePlot::class, 'plot_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * The incumbent reservation for an order — used by `ReservePlot`'s
     * idempotency pre-check (an order with an active reservation is never
     * handed a second one). `held` AND `confirmed` count as active: a
     * confirmed reservation is still the authoritative claim on the plot.
     *
     * @param  Order  $order  read for its key only — never content.
     */
    public static function activeForOrder(Order $order): ?self
    {
        return self::query()
            ->where('order_id', $order->getKey())
            ->whereIn('state', [PlotReservationState::HELD, PlotReservationState::CONFIRMED])
            ->latest()
            ->first();
    }

    /**
     * The incumbent reservation for a plot, with the same active-state
     * filter as `activeForOrder()` — the plot-level mirror consumed by
     * `GravePlot`'s delete-blocked guard (Task 1) and the lifecycle
     * actions (Task 4).
     *
     * @param  GravePlot  $plot  read for its key only — never content.
     */
    public static function activeForPlot(GravePlot $plot): ?self
    {
        return self::query()
            ->where('plot_id', $plot->getKey())
            ->whereIn('state', [PlotReservationState::HELD, PlotReservationState::CONFIRMED])
            ->latest()
            ->first();
    }
}
