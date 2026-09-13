<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Models;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Exceptions\OrderIsGuardedException;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Platform\Payment\Models\PaymentSession;
use Illuminate\Database\Eloquent\Attributes\Scope;
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
 *   - `linkPaymentSession()` — `payment_session_id` alone (H-2), and only
 *     for a caller holding a persisted `payment_sessions` row.
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

    /**
     * Set only for the duration of `linkPaymentSession()`. A THIRD flag, for
     * the same reason the second one exists: `payment_session_id` is written
     * on a customer-triggered path (opening a checkout) that must never be
     * able to move `status` or the money-source columns as a side effect.
     */
    private bool $paymentSessionWriteAuthorized = false;

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
     * The THIRD door: `orders.payment_session_id` alone — the creation-time
     * handle on the checkout currently trying to pay this order. See
     * `2026_09_13_120000_add_payment_session_id_to_orders_table.php` for why
     * the column exists at all and why it lives here rather than on
     * `payment_sessions`.
     *
     * Authorization follows the same principle as the two doors above:
     * decided against the DATABASE, never against the instance handed in.
     * The token is the persisted `payment_sessions` row itself. `$session
     * ->exists` is deliberately not consulted — it is a public,
     * caller-writable property, so it is a claim rather than evidence, the
     * same reasoning `applyStatus()` documents. An unsaved
     * `new PaymentSession([...])` therefore cannot link itself to an order,
     * which matters because the sweep treats a linked live session as a
     * reason NOT to expire an order: a forgeable link would be a way to pin
     * a plot indefinitely without ever paying for it.
     *
     * This is narrower than the other two doors in one way worth stating:
     * it does not require the session to be in any particular state. A
     * session is linked at the instant it is opened, when its state is
     * necessarily `AWAITING_PAYMENT`; asserting that here would duplicate
     * `OpenPaymentSession`'s own invariant in a second place, and would
     * wrongly refuse a future caller that legitimately re-links.
     *
     * DELIBERATELY UNAUDITED, and that is a decision rather than an
     * omission. The authoritative record of a checkout opening is already
     * written, by `Actions\OpenPaymentSession`, as a
     * `PaymentAuditActions::SESSION_OPENED` row in `audit_events`. This
     * column is a DERIVED POINTER at that same event — not an independent
     * fact about the order, and not a money-bearing value: it grants
     * nothing, moves no status, and its only reader
     * (`QuoteExpiryScheduler`) treats it as a reason to do LESS. Auditing it
     * would put a second record of one event into the trail, which is how an
     * audit trail stops being answerable. The two doors above are audited
     * because they write facts (`status`, `paid_via`) that nothing else
     * records; this one is not, because something else already does.
     *
     * Unlike those two it is also written outside any transaction. It has no
     * partner row to stay atomic with — the audit row it would pair with
     * belongs to the session, and was committed before this method is ever
     * reached.
     *
     * @throws OrderIsGuardedException when no such session row exists.
     */
    public function linkPaymentSession(PaymentSession $session): void
    {
        if (! PaymentSession::query()->whereKey($session->getKey())->exists()) {
            throw OrderIsGuardedException::forOperation('linkPaymentSession');
        }

        // `save()` persists EVERY dirty attribute, not just the one filled
        // below — so without this line a caller holding an instance with an
        // in-memory `$order->status = 'DIBAYAR'` could ride that write in
        // through this door, landing a paid status with no
        // `order_status_events` row, no audit row and no outbox row: exactly
        // the money bug the class-level write guard exists to stop, reached
        // one method along. Discarding first makes this door write one
        // column and nothing else, whatever it is handed.
        //
        // `applyStatus()` and `stampPaidSource()` have the same shape and
        // are NOT changed here — they are pre-existing, out of this task's
        // scope, and each is reachable only with a persisted event row as
        // its token. Recorded as a known gap rather than silently matched.
        $this->discardChanges();

        $this->paymentSessionWriteAuthorized = true;

        try {
            $this->forceFill(['payment_session_id' => $session->getKey()]);
            $this->save();
        } finally {
            $this->paymentSessionWriteAuthorized = false;
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
        if (
            ! $this->statusWriteAuthorized
            && ! $this->paidSourceWriteAuthorized
            && ! $this->paymentSessionWriteAuthorized
        ) {
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

    /**
     * @return BelongsTo<BookingDraft, $this>
     */
    public function bookingDraft(): BelongsTo
    {
        return $this->belongsTo(BookingDraft::class, 'booking_draft_id');
    }

    /**
     * The checkout attempt currently associated with this order, or null.
     * Deliberately NOT in `$fillable`: the column is unreachable through
     * mass assignment by design, because at `create()` time — the one write
     * path this model leaves open — no session can exist yet. The only way
     * in is `linkPaymentSession()`.
     *
     * @return BelongsTo<PaymentSession, $this>
     */
    public function paymentSession(): BelongsTo
    {
        return $this->belongsTo(PaymentSession::class, 'payment_session_id');
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class, 'order_id');
    }

    /**
     * The order's full append-only reservation chain, newest row first —
     * the same `created_at DESC, id DESC` order `PlotReservation
     * ::activeForOrder()` selects on, so `->first()` on an eager-loaded
     * chain IS the head row that method would return.
     *
     * Ordering lives in the relation rather than at each call site
     * precisely because eager loading is the point: `->with('plotReservations')`
     * carries the ordering with it, a `->with(['plotReservations' => fn (...)])`
     * closure at each call site would not.
     *
     * @return HasMany<PlotReservation, $this>
     */
    public function plotReservations(): HasMany
    {
        return $this->hasMany(PlotReservation::class, 'order_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function parties(): HasMany
    {
        return $this->hasMany(OrderParty::class, 'order_id');
    }

    /**
     * Every order the given user has a `order_parties` row on, most recent
     * first — the filter behind `/akun/pesanan`, matching `/akun/draft`'s
     * own most-recent-first convention from PR 2.
     *
     * `$userId` is deliberately `int`, not `?int`. Stated rather than
     * assumed closed, same as the write-guard reasoning above: Laravel's
     * query builder silently rewrites `where('user_id', null)` into
     * `whereNull('user_id')`
     * (`vendor/laravel/framework/src/Illuminate/Database/Query/Builder.php`),
     * so if this parameter were ever widened to `?int` and called with
     * `null` — e.g. for a guest — the scope would not throw or return
     * nothing, it would silently become "list every ANONYMOUS order", a
     * real cross-customer-adjacent data-exposure risk this signature closes
     * off at the type level instead.
     */
    #[Scope]
    protected function forUser(Builder $query, int $userId): void
    {
        $query->whereHas('parties', fn (Builder $q) => $q->where('user_id', $userId))
            ->orderByDesc('created_at');
    }
}
