<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\RefundObligation\Actions\OpenRefundObligation;
use App\Platform\Audit\AuditSource;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * The single door by which a paid order is refused — Stage R1 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`, which is Tahap 2 of
 * `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`.
 *
 * The owner reversed the order of money and confirmation: the customer pays
 * in full, THEN an admin decides. This Action is what happens when the answer
 * is no while the money is already in hand.
 *
 * ---------------------------------------------------------------------------
 * Two steps, in this order, and the order is the whole design
 * ---------------------------------------------------------------------------
 * 1. `App\Domain\RefundObligation\Actions\OpenRefundObligation` — the debt.
 * 2. `Actions\RecordOrderStatusChange` to `DITOLAK_SETELAH_BAYAR`.
 *
 * Step 2 REFUSES TO RUN unless step 1 has already happened — it checks the
 * database for a `refund_obligations` row on this order inside the same
 * transaction and throws `RefusalWithoutRefundObligationException` otherwise.
 * So the sequence here is not a convention this class is trusted to follow;
 * swapping the two lines does not produce a subtly wrong audit trail, it
 * produces an exception and a rolled-back transaction. That is the point:
 * *"utang yang tidak dicatat adalah utang yang dilupakan"*, and the person
 * forgotten is a family that has already paid.
 *
 * ---------------------------------------------------------------------------
 * `DB::transaction()` here, not `Audit::wrap()` — and what that costs
 * ---------------------------------------------------------------------------
 * Both steps write their own audit row inside their own transaction, so this
 * refusal already produces exactly two audit events, which is right: opening
 * a debt and refusing an order are two different things that happened, and a
 * reader of the trail needs both.
 *
 *   - `REFUND_OBLIGATION_OPENED` (subject: the obligation) — the debt, its
 *     amount and deadline on the row it points at.
 *   - `DITOLAK_SETELAH_BAYAR` (subject: the order) — the admin's decision,
 *     carrying the mandatory reason. Both statuses are on
 *     `SensitiveActions::ACTIONS`, so neither can be recorded without one.
 *
 * A third `Audit::wrap()` around them would need a third action name that
 * could only restate what those two already say — the duplication
 * `AGENTS.md` §Documentation forbids, in an append-only table where it can
 * never be cleaned up. `DB::transaction()` buys the one property that IS
 * needed and is not otherwise guaranteed: the debt and the refusal commit
 * together or not at all. Laravel treats the two inner transactions as
 * savepoints of this one.
 *
 * The `reason` is mandatory here rather than merely forwarded. Both callees
 * would reject a blank one on their own, but they would reject it at
 * different depths with different messages; failing here says plainly that a
 * refusal after payment is not a thing this system will record without a
 * stated cause. It is the only answer available to a customer asking why.
 */
final readonly class RefusePaidOrder
{
    /**
     * @param  int  $refundAmountMinor  Minor units, never a float — the
     *                                  amount owed back to the customer.
     *                                  Sourced from what they actually paid
     *                                  (the order's invoice), never guessed:
     *                                  an invented refund amount is a money
     *                                  bug.
     * @param  string|null  $paymentSessionId  The session the money arrived
     *                                         through, when the caller knows
     *                                         it. Its absence must never stop
     *                                         the debt being recorded.
     * @param  array<string, mixed>  $metadata  Forwarded to the status
     *                                          change; subject to
     *                                          `MetadataAllowlist`.
     *
     * @throws InvalidArgumentException when no reason is stated.
     */
    public function handle(
        Order $order,
        int $refundAmountMinor,
        string $currency,
        ?string $paymentSessionId,
        string $reason,
        int|string $actorRef,
        string $actorRole,
        AuditSource $source = AuditSource::Panel,
        array $metadata = [],
    ): OrderStatusEvent {
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Refusing an order whose money has already arrived requires a stated reason. It is the '
                .'only answer available to the customer whose payment is being sent back.'
            );
        }

        return DB::transaction(function () use (
            $order,
            $refundAmountMinor,
            $currency,
            $paymentSessionId,
            $reason,
            $actorRef,
            $actorRole,
            $source,
            $metadata,
        ): OrderStatusEvent {
            // The debt FIRST. `OpenRefundObligation` throws
            // `RefundObligationAlreadyOpenException` when this order already
            // carries one (`refund_obligations.order_id` is UNIQUE) — that
            // exception is deliberately NOT caught and softened here: an
            // order with an existing debt has already been refused, and
            // quietly proceeding would write a second refusal against a
            // customer whose money is already accounted for.
            app(OpenRefundObligation::class)->handle(
                order: $order,
                amountMinor: $refundAmountMinor,
                currency: $currency,
                paymentSessionId: $paymentSessionId,
                reason: $reason,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
            );

            return app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::DITOLAK_SETELAH_BAYAR,
                (string) $actorRef,
                $actorRole,
                $reason,
                $metadata,
            );
        });
    }
}
