<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Finds orders whose current quote has silently expired and writes
 * `KEDALUWARSA` for read-model honesty — the second half of Task 4's
 * ratified design (Q4, Q5) in
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md:592`:
 * "expiry is evaluated lazily and authoritatively at guard time... with a
 * scheduled job writing `KEDALUWARSA` only for read-model honesty."
 *
 * The lazy-guard half was already real: `Quote::accept()` and
 * `Quote::isAcceptedAndUnexpired()` (consumed by the Task 6 payment guard)
 * both re-check `expires_at` live, every time, regardless of what
 * `orders.status` currently says. This class is purely cosmetic — it makes
 * the ADMIN- and CUSTOMER-visible status honest — and nothing here is
 * trusted as a source of truth by any guard. Skipping a run, or running it
 * late, changes what a screen displays; it changes no financial decision.
 *
 * `KEDALUWARSA` is reachable from exactly three statuses
 * (`OrderTransition::ALLOWED`): `PENAWARAN_TERKIRIM` (quote issued,
 * awaiting buyer approval), `DISETUJUI_PEMESAN` (quote accepted, payment
 * opening not yet granted), and `MENUNGGU_PEMBAYARAN` (payment window
 * open, not yet paid). In all three, `Quote::currentFor($order)` — the
 * newest non-superseded version — is the version that governs whether the
 * order can still move forward, so "the order's current quote has passed
 * its `expires_at`" is the one condition this class checks, regardless of
 * whether that version is still `ISSUED` or already `ACCEPTED`.
 *
 * ---------------------------------------------------------------------------
 * H-2 — the one case where this class is NOT cosmetic
 * ---------------------------------------------------------------------------
 * Everything above describes this job as read-model honesty that "changes no
 * financial decision". That was wrong, and the correction is the reason this
 * class now reads `payment_sessions` at all.
 *
 * `KEDALUWARSA` is a TERMINAL status, and `RecordOrderStatusChange` releases
 * the order's plot reservation on every terminal, non-completed transition —
 * `ReleasePlotReservation` sets the plot back to `AVAILABLE` in the same
 * transaction, after which any other customer may reserve it. So this job
 * does not merely relabel a screen: it hands a grave plot back to the market.
 *
 * Combined with an open checkout, that produced the incident this guard
 * exists to prevent. The customer is on the provider's hosted payment page;
 * their quote lapses; this job runs; the order goes `KEDALUWARSA` and the
 * plot is released. The customer then pays. The webhook arrives and
 * `ApplyPaidEffects` throws `forMissingAcceptedQuote` (the accepted quote is
 * expired), the settlement transaction rolls back, the job retries twice and
 * dead-letters. Final state: the money is collected by the provider, the
 * order says expired, no invoice and no ledger row exist, and the plot has
 * been sold to somebody else. On a funeral service.
 *
 * There is already a guard for exactly this shape —
 * `ReleasePlotReservation` refuses to release a plot whose order
 * `isPaidOrLater()` without an audited override — but it is inert here,
 * because at the moment of release the money has not landed yet. This path
 * arrives one step early and walks straight past it. The fix is to refuse to
 * take the step at all while a checkout is open.
 */
final readonly class QuoteExpiryScheduler
{
    /** @var list<OrderStatus> */
    private const array EXPIRABLE_STATUSES = [
        OrderStatus::PENAWARAN_TERKIRIM,
        OrderStatus::DISETUJUI_PEMESAN,
        OrderStatus::MENUNGGU_PEMBAYARAN,
    ];

    /**
     * The `SessionState` values that mean "money may still arrive", chosen
     * by reading that enum's own doc block rather than by shape:
     *
     *   - `Created` — the row exists but no provider session is confirmed.
     *     Included: it is the pre-`AwaitingPayment` half of an opening in
     *     progress, and an opening in progress is not a dead session.
     *   - `AwaitingPayment` — a hosted checkout exists and the customer has
     *     not finished it. This is the incident state.
     *
     * Everything else is excluded, and each exclusion is a decision:
     *
     *   - `Paid` — already settled. Such an order is `DIBAYAR` or later and
     *     is not in `EXPIRABLE_STATUSES` anyway, so including it would
     *     protect nothing and would only hide a real inconsistency.
     *   - `Failed`, `Expired` — terminal refusals, written ONLY by
     *     `ApplyPaymentSettlement` from a claimed, validated webhook. The
     *     provider has told us this attempt is over. Treating these as live
     *     would mean a single failed checkout pins a plot forever, which is
     *     the failure this fix must not introduce.
     *   - `Refunded` — post-settlement by definition; the money already
     *     arrived and already went back.
     *
     * @var list<SessionState>
     */
    private const array LIVE_SESSION_STATES = [
        SessionState::Created,
        SessionState::AwaitingPayment,
    ];

    /**
     * How long a session whose `expires_at` is NULL is still treated as live,
     * counted from its `created_at`.
     *
     * PLACEHOLDER — awaiting the project owner's ruling on how long a hosted
     * checkout may plausibly stay open. It is a single named constant
     * precisely so that ruling costs one line and not a query rewrite.
     *
     * A fallback is needed because `payment_sessions.expires_at` is NULLABLE
     * and not always populated: it is copied from the provider's response
     * (`OpenPaymentSession`), and `expires_at` is not among
     * `SumoPodPaymentClient::REQUIRED_RESPONSE_FIELDS` — so a provider that
     * omits it yields a session that never expires by its own clock. Without
     * a bound, such a session would read as live forever and would stop its
     * order from EVER expiring. A sweep that stops expiring things is not a
     * fix, so the bound is not optional.
     *
     * 24 hours is not arbitrary: it is what the provider was observed to do.
     * The single real session in beta's history was created 15 Aug 2026
     * 14:58:37 and carried `expires_at` 16 Aug 2026 14:58:37 — exactly 24
     * hours. n=1, which is why this is a placeholder and says so.
     *
     * Note this bound is a CEILING on protection, never a floor: a session
     * that states its own `expires_at` is judged by that value alone and
     * this constant never applies to it.
     */
    private const int NULL_EXPIRY_FALLBACK_HOURS = 24;

    public function __construct(private ExpireOrder $expireOrder) {}

    /**
     * @return Collection<int, Order> the orders actually transitioned to
     *                                `KEDALUWARSA` by this run.
     */
    public function expireDueOrders(?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        $statusValues = array_map(
            static fn (OrderStatus $status): string => $status->value,
            self::EXPIRABLE_STATUSES,
        );

        // At most one non-superseded quote exists per order at any time
        // (`Actions\IssueQuote` supersedes the incumbent before inserting a
        // newer row) — so "not SUPERSEDED" here already identifies each
        // candidate order's CURRENT version, the same one
        // `Quote::currentFor()` would return.
        $expiredQuotes = Quote::query()
            ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
            ->where('expires_at', '<', $now)
            ->whereHas('order', function ($query) use ($statusValues, $now): void {
                $query->whereIn('status', $statusValues)
                    // H-2: never select an order whose money is in flight.
                    // `whereDoesntHave` on a `belongsTo` is also true when
                    // `payment_session_id` IS NULL, which is the common case
                    // (manual fallback, operator-recorded payment, and every
                    // row that predates the column) — so this narrows the
                    // sweep and never widens it.
                    ->whereDoesntHave('paymentSession', function ($session) use ($now): void {
                        $this->constrainToLiveSessions($session, $now);
                    });
            })
            ->with('order')
            ->get();

        $expired = new Collection;

        foreach ($expiredQuotes as $quote) {
            $order = $quote->order;

            if (! $order instanceof Order) {
                continue;
            }

            // Re-check under the same predicate, immediately before the
            // write. The query above is a SELECT and this loop is a series of
            // writes; a customer who opens a checkout in between would
            // otherwise be expired by a decision taken before their session
            // existed. This is the same time-of-check/time-of-use gap the
            // `IllegalOrderTransitionException` catch below already
            // acknowledges for concurrent status moves — but that one costs a
            // no-op, and this one costs a released plot and an unattributable
            // payment, so it is checked rather than caught.
            //
            // Stated plainly rather than claimed closed: this narrows the
            // window to microseconds, it does not eliminate it.
            // `RecordOrderStatusChange` takes `lockForUpdate()` on the order,
            // but the session-opening path (`OpenBookingOnlinePayment` ->
            // `OpenPaymentSession`) never locks the order row, so the two are
            // not serialised against each other. Closing it completely means
            // taking the order lock on the opening path too — a wider change
            // than this fix, and one that would need its own review.
            if ($this->hasLivePaymentSession($order, $now)) {
                continue;
            }

            try {
                ($this->expireOrder)($order, 'system', 'system');
                $expired->push($order);
            } catch (IllegalOrderTransitionException) {
                // The order moved on between the query above and this
                // write (paid, cancelled, rejected, or re-quoted
                // concurrently) — nothing to do, and not an error. Makes
                // repeated runs safe, which is the whole point of a
                // read-model-honesty job.
                continue;
            }
        }

        return $expired;
    }

    /**
     * Does this order have a checkout that money may still arrive through?
     *
     * Separate from the query above rather than shared with it because the
     * two ask the question of different things — one narrows a `NOT EXISTS`
     * subquery across many candidate orders, the other asks about one known
     * order — while `constrainToLiveSessions()` keeps the definition of
     * "live" in exactly one place. Two copies of that definition is precisely
     * how a guard like this rots.
     */
    private function hasLivePaymentSession(Order $order, CarbonInterface $now): bool
    {
        $sessionId = $order->payment_session_id;

        if ($sessionId === null) {
            return false;
        }

        return PaymentSession::query()
            ->whereKey($sessionId)
            ->where(function ($query) use ($now): void {
                $this->constrainToLiveSessions($query, $now);
            })
            ->exists();
    }

    /**
     * The single definition of a live payment session, applied to whatever
     * `payment_sessions` query builder it is handed.
     *
     * Two conditions, both required:
     *
     *   1. The state means money may still arrive — `LIVE_SESSION_STATES`.
     *   2. The session has not run out of time, where "out of time" is the
     *      session's own `expires_at` when it has one, and
     *      `NULL_EXPIRY_FALLBACK_HOURS` past `created_at` when it does not.
     *
     * The second condition is what keeps this from being a plot-pinning
     * device: beta's single real session has sat `AWAITING_PAYMENT` for a
     * month, a long way past its own stated expiry, and nothing ever
     * reconciled it. Under this predicate that session is NOT live and its
     * order expires normally — which is the correct outcome and the one the
     * "must keep working" test pins.
     *
     * @param  \Illuminate\Contracts\Database\Query\Builder|Builder<PaymentSession>  $query
     */
    private function constrainToLiveSessions($query, CarbonInterface $now): void
    {
        $stateValues = array_map(
            static fn (SessionState $state): string => $state->value,
            self::LIVE_SESSION_STATES,
        );

        $fallbackFloor = CarbonImmutable::instance($now)
            ->subHours(self::NULL_EXPIRY_FALLBACK_HOURS);

        $query->whereIn('state', $stateValues)
            ->where(function ($clock) use ($now, $fallbackFloor): void {
                $clock->where('expires_at', '>', $now)
                    ->orWhere(function ($fallback) use ($fallbackFloor): void {
                        $fallback->whereNull('expires_at')
                            ->where('created_at', '>', $fallbackFloor);
                    });
            });
    }
}
