<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Models;

use App\Domain\Booking\Models\BookingDraft;
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
 * `2026_08_16_100020_create_plot_reservations_table.php` for the schema.
 * "One active hold per plot" is enforced by the plot-row lock +
 * `plot_state` aggregate (see `Actions\ReservePlot`'s class doc block);
 * the former `plot_reservations_active_hold` partial unique index was
 * removed by `2026_08_16_100030_drop_plot_reservations_active_hold_
 * index.php` because append-only rows never release it (a plot could
 * only ever be held once).
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
 * `create()` is NOT guarded, and that is deliberate: the append-only
 * guarantee's application-layer refusal on updates/deletes stands alone
 * as the evidence rationale, and lifecycle tests insert chain rows
 * directly via `PlotReservation::query()->create([...])` to build
 * fixtures. A model-level refusal on `create()` would make every chain
 * impossible, not just the guarded cases (the `OrderStatusEvent` class
 * doc block records the identical reasoning for its lane).
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
 *
 * @property-read GravePlot|null $plot
 * @property-read Order|null $order
 * @property-read BookingDraft|null $bookingDraft
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
        'booking_draft_id',
        'state',
        'reserved_by_ref',
        'reason',
        'reserved_at',
        'confirmed_at',
        'released_at',
        'expired_at',
        'expires_at',
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
            'expires_at' => 'immutable_datetime',
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
     * row would erase the evidence of the hold.
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

    public function bookingDraft(): BelongsTo
    {
        return $this->belongsTo(BookingDraft::class, 'booking_draft_id');
    }

    /**
     * The incumbent reservation for an order — used by `ReservePlot`'s
     * idempotency pre-check (an order with an active reservation is never
     * handed a second one) and by the booking-integration UI (Lane 3,
     * `ViewBookingOrder`) to decide which lifecycle actions are offered.
     *
     * The INCUMBENT is the LATEST row of the order's append-only chain:
     * the chain's head row is returned when its state is `held` or
     * `confirmed` (both count as active — a confirmed reservation is
     * still the authoritative claim on the plot), and null otherwise.
     * The latest-row check is deliberately done AFTER reading the head
     * row rather than as a query filter: a superseded `held` row remains
     * in the chain forever (append-only), so filtering by state first
     * would resurrect exactly the row the chain's later hops superseded
     * — an order whose reservation was released/expired would still
     * "have an active reservation".
     *
     * `id` is a UUIDv7 (`HasUuids`), so its leading timestamp bits are
     * millisecond-monotonic: `created_at DESC, id DESC` is insertion
     * order with no NULL involvement, portable across PostgreSQL and
     * SQLite, and the same tiebreak the lifecycle actions' own re-read
     * uses. Do NOT add per-state stamp columns (e.g. `confirmed_at`) as
     * tiebreakers: PostgreSQL sorts NULLs FIRST under `DESC`, so the
     * unstamped head of a re-opened chain would sort before the stamped
     * rows that precede it, and SQLite sorts NULLs LAST, so a stamped
     * older row would outrank a fresh unstamped head — each engine would
     * resolve the head differently.
     *
     * @param  Order  $order  read for its key only — never content.
     */
    public static function activeForOrder(Order $order): ?self
    {
        return self::incumbentOf(
            self::query()
                ->where('order_id', $order->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()
        );
    }

    /**
     * The incumbent hold for a booking draft — the draft-scoped mirror of
     * `activeForOrder()`. Same head-row-then-filter reasoning: a
     * superseded `held` row remains in the chain forever, so filtering by
     * state first would resurrect a row a later hop already superseded.
     *
     * @param  BookingDraft  $draft  read for its key only — never content.
     */
    public static function activeForDraft(BookingDraft $draft): ?self
    {
        return self::incumbentOf(
            self::query()
                ->where('booking_draft_id', $draft->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()
        );
    }

    /**
     * The incumbency rule of `activeForOrder()`, applied to a head row the
     * caller already has. A table listing eager-loads whole chains through
     * `Order::plotReservations()` (ordered newest-first) and calls this on
     * `->first()`, which is the same head row `activeForOrder()` would
     * select — one query for the page instead of one per row, with the
     * rule itself not duplicated.
     */
    public static function incumbentOf(?self $head): ?self
    {
        if ($head === null || ! in_array($head->state, PlotReservationState::ACTIVE_STATES, true)) {
            return null;
        }

        return $head;
    }

    /**
     * The incumbent reservation for a plot — the plot-level mirror of
     * `activeForOrder()`. Currently unused by the module (the delete
     * guard and the lifecycle actions re-read the chain directly); kept
     * for symmetry with `activeForOrder()` and pinned to the same head-
     * row semantics so a future consumer cannot reintroduce the
     * filter-before-order bug.
     *
     * @param  GravePlot  $plot  read for its key only — never content.
     */
    public static function activeForPlot(GravePlot $plot): ?self
    {
        $latest = self::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        if ($latest === null || ! in_array($latest->state, PlotReservationState::ACTIVE_STATES, true)) {
            return null;
        }

        return $latest;
    }
}
