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
use InvalidArgumentException;

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
 * it admits exactly TWO callers, each behind its own private authorization
 * flag, in the shape `App\Platform\DocumentVault\Models\Document`'s
 * `writeState()`/`promote()` pair already uses for the same purpose (set
 * the flag, write, clear it in `finally`):
 *
 *   - `applyStatus()` — `status` alone (Task 2).
 *   - `stampPaidSource()` — `paid_via` + `paid_source_ref` alone (Task 7),
 *     and only for a caller holding a persisted `DIBAYAR` event.
 *
 * The flags are separate on purpose: collapsing them into one would let a
 * status write also move the money-source columns.
 *
 * `applyStatus()` is public, so — unlike `Document`, whose authorized
 * writers are all private — it cannot rely on visibility to keep callers
 * out. It relies on a token instead: the persisted `OrderStatusEvent` row
 * itself. See that method's doc block.
 *
 * These overrides stop `$order->update([...])`, `$order->status = ...;
 * $order->save()` on an already-persisted instance, and `$order->delete()`.
 *
 * They do NOT stop `Order::query()->update([...])`,
 * `Order::query()->upsert([...])`, `DB::table('orders')->update(...)`, raw
 * SQL, or any process with direct database credentials — those never pass
 * through this class. Stated plainly rather than assumed closed, exactly as
 * `PaymentIntent`'s own doc block states it. Closing the bulk-update path
 * would need a PostgreSQL trigger; that is not this task's scope and is
 * recorded here so the gap is a known one rather than an assumed-absent
 * one.
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
        // Task 3. Set once, at `create()` — the one write path this model
        // leaves open — and never updated, because every update path throws.
        // Its uniqueness is a database constraint
        // (`orders_idempotency_key_unq`), not a property of this list; see
        // `Actions\SubmitBookingDraft`.
        'idempotency_key',
    ];

    /**
     * Set only for the duration of `applyStatus()`. `performUpdate()` is an
     * unconditional refusal for every other caller.
     */
    private bool $statusWriteAuthorized = false;

    /**
     * Set only for the duration of `stampPaidSource()`. Deliberately a
     * SECOND flag rather than a reuse of `$statusWriteAuthorized`: the two
     * doors authorize different columns, and one shared flag would mean a
     * status write could also move the money-source columns (and vice
     * versa) with no caller ever asking for it.
     */
    private bool $paidSourceWriteAuthorized = false;

    public function status(): OrderStatus
    {
        return OrderStatus::from($this->status);
    }

    /**
     * The ONE door through which `orders.status` moves, and it opens only
     * for a caller holding the already-persisted `order_status_events` row
     * that records the move.
     *
     * The argument is that row, NOT a bare `OrderStatus`. A bare enum made
     * this method a second, unvalidated way to reach `DIBAYAR` with no event
     * row, no audit row and no outbox row — the very thing the write guard
     * above exists to stop, one method along. Requiring the event makes the
     * pairing structural: possession of a persisted `OrderStatusEvent` is
     * proof that
     * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` ran, and
     * that Action is what enforces `OrderTransition::assertAllowed()`, the
     * blank-reason rule, the audit row and the outbox row. The applied
     * status is READ FROM the event rather than passed alongside it, so
     * there is no second value that could disagree with the recorded one.
     *
     * Authorization is decided against the DATABASE, and nothing about the
     * instance handed in is taken on trust — not even its own `exists`
     * flag, which is a public, writable property on every Eloquent model
     * and so is a claim rather than evidence. The single `exists()` query
     * requires a row with THAT primary key, THIS order's `order_id`, and
     * that `to_status`. An unsaved `new OrderStatusEvent([...])` has no key
     * and fails it; an event belonging to another order fails it; and so
     * does an in-memory instance whose `to_status` was reassigned after
     * loading — `OrderStatusEvent` is append-only at the model level, so
     * such an instance can never be saved, but it could still be passed
     * here, and its unsaved value must not be what authorizes a
     * money-bearing write. The Action calls this inside the transaction
     * that created the event, so the row is visible to that same
     * connection.
     *
     * This method still deliberately does NOT re-check the transition graph:
     * doing so here would put a second copy of the rule in a second place.
     */
    public function applyStatus(OrderStatusEvent $event): void
    {
        $to = OrderStatus::tryFrom((string) $event->to_status);

        if ($to === null || ! $this->isAuthorizedBy($event, $to)) {
            throw OrderIsGuardedException::forOperation('applyStatus');
        }

        $this->statusWriteAuthorized = true;

        try {
            $this->forceFill(['status' => $to->value]);
            $this->save();
        } finally {
            $this->statusWriteAuthorized = false;
        }
    }

    /**
     * The SECOND door: `orders.paid_via` and `orders.paid_source_ref`, the
     * two columns `2026_08_12_100000_create_orders_table.php` says are "set
     * ONLY by `Actions\ApplyPaidEffects`". Shaped on
     * `App\Platform\DocumentVault\Models\Document`'s `promote()` pair — a
     * public composing door, a private write flag, one `save()`, the flag
     * cleared in a `finally`.
     *
     * Being in `$fillable` buys these columns nothing: every model-level
     * update path on this class throws (see the class doc block), so before
     * this method existed there was no way to set them at all. That is why
     * the door is added rather than the guard loosened.
     *
     * Authorization is the SAME check `applyStatus()` performs, against the
     * DATABASE, with one extra restriction: the token must be a `DIBAYAR`
     * event. That restriction is the whole point — possession of a persisted
     * `DIBAYAR` `order_status_events` row for THIS order is proof that
     * `Actions\RecordOrderStatusChange` ran the paid transition, with its
     * graph assertion, its audit row, its outbox row and the
     * `order_status_events_paid_once` index behind it. A token for any other
     * transition, a token belonging to another order, and an unsaved
     * `new OrderStatusEvent([...])` all fail, so these two columns are
     * unreachable except on the paid path. `$event->exists` is not consulted:
     * it is a public, caller-writable property, so it is a claim rather than
     * evidence — the same reasoning `applyStatus()` documents.
     *
     * `applyStatus()` is deliberately NOT widened to take these columns: it
     * would then be a single call that moves both status and money source,
     * and every one of its thirteen non-paid transitions would gain the
     * ability to write `paid_via`.
     *
     * What this does NOT stop, stated plainly rather than assumed closed —
     * exactly as the class doc block states it for the status guard:
     * `Order::query()->update([...])`, `Order::query()->upsert([...])`,
     * `DB::table('orders')->update(...)`, raw SQL, and any process holding
     * direct database credentials never instantiate this class and so never
     * reach this check. Using any of them on the paid path is forbidden
     * precisely because it bypasses this door — an order carrying
     * `paid_via` with no `DIBAYAR` event behind it is a money bug of the
     * same kind as a `DIBAYAR` status with no event row. Closing the
     * bulk-update path needs a PostgreSQL trigger and is not this task's
     * scope; the gap is recorded, not claimed closed.
     *
     * @throws OrderIsGuardedException when the token does not authorize this
     *                                 order's paid write.
     */
    public function stampPaidSource(OrderStatusEvent $event, string $paidVia, string $paidSourceRef): void
    {
        if (! $this->isAuthorizedBy($event, OrderStatus::DIBAYAR)) {
            throw OrderIsGuardedException::forOperation('stampPaidSource');
        }

        if (trim($paidVia) === '' || trim($paidSourceRef) === '') {
            throw new InvalidArgumentException(
                'Paid source columns must record a non-blank trigger and source reference.'
            );
        }

        $this->paidSourceWriteAuthorized = true;

        try {
            $this->forceFill([
                'paid_via' => $paidVia,
                'paid_source_ref' => $paidSourceRef,
            ]);
            $this->save();
        } finally {
            $this->paidSourceWriteAuthorized = false;
        }
    }

    /**
     * Is there a persisted `order_status_events` row, for THIS order, that
     * records exactly this move? One indexed primary-key lookup.
     */
    private function isAuthorizedBy(OrderStatusEvent $event, OrderStatus $to): bool
    {
        return OrderStatusEvent::query()
            ->whereKey($event->getKey())
            ->where('order_id', $this->getKey())
            ->where('to_status', $to->value)
            ->exists();
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
     * Throws for every caller except `applyStatus()` and
     * `stampPaidSource()`. Blocks `$order->status = ...; $order->save();` on
     * an already-persisted instance, which routes here rather than through
     * `update()`.
     */
    protected function performUpdate(Builder $query): bool
    {
        if (! $this->statusWriteAuthorized && ! $this->paidSourceWriteAuthorized) {
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
