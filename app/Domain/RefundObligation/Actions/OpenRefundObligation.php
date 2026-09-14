<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\RefundObligation\Exceptions\RefundObligationAlreadyOpenException;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationAuditActions;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Domain\RefundObligation\Support\WorkingDays;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * Opens a refund obligation against an order — Stage R0 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * The only way a `refund_obligations` row comes into existence. Stage R1 calls
 * it from inside `RecordOrderStatusChange`'s own `Audit::wrap()` mutation
 * closure, so that the debt is created by the same transaction that rejects
 * the order — the plan's binding invariant: *"Kewajiban refund dibuat oleh
 * transaksi yang sama dengan yang menolak pesanan. Bukan sesudahnya, bukan
 * oleh job, bukan oleh admin yang ingat."*
 *
 * Nesting this action's `Audit::wrap()` inside that one is safe and
 * deliberate: Laravel treats a nested `DB::transaction()` as a savepoint, the
 * precedent `RecordOrderStatusChange` already documents for its call to
 * `ReleasePlotReservation`. Two audit events result, which is right — the
 * status change and the opening of a debt are two different things that
 * happened.
 */
final readonly class OpenRefundObligation
{
    /**
     * The owner's decision of 13 Sep 2026: a refund must be executed within 3
     * working days of the obligation being opened.
     *
     * A constant rather than config. This number is the difference between an
     * operator being late and being on time on somebody's refund, so changing
     * it should be a reviewed code change with a reason in its commit — not a
     * value that can drift between environments where nobody would notice
     * production had a different deadline from the one everyone believed.
     *
     * Working days, not hours — see {@see WorkingDays} for what that means
     * and for the national-holiday limitation stated there.
     */
    public const int EXECUTION_DEADLINE_WORKING_DAYS = 3;

    /**
     * @param  int  $amountMinor  Minor units, never a float. Must be positive:
     *                            an obligation of zero is not a debt, and the
     *                            database refuses it too.
     * @param  string|null  $paymentSessionId  The session the money arrived
     *                                         through, when known. Its absence
     *                                         must never stop the debt being
     *                                         recorded.
     *
     * @throws RefundObligationAlreadyOpenException when the order already has one.
     * @throws InvalidArgumentException when the amount or reason is unusable.
     */
    public function handle(
        Order $order,
        int $amountMinor,
        string $currency,
        ?string $paymentSessionId,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
        ?CarbonImmutable $openedAt = null,
    ): RefundObligation {
        if ($amountMinor <= 0) {
            throw new InvalidArgumentException(
                "A refund obligation must be for a positive amount; got [{$amountMinor}] minor units."
            );
        }

        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A refund obligation must state why it exists. It is the only answer available to a '
                .'customer asking why their paid order was refused.'
            );
        }

        $opened = $openedAt ?? CarbonImmutable::now();
        $due = WorkingDays::after($opened, self::EXECUTION_DEADLINE_WORKING_DAYS);

        try {
            return Audit::wrap(
                mutation: function () use ($order, $amountMinor, $currency, $paymentSessionId, $reason, $actorRef, $opened, $due): RefundObligation {
                    $obligation = new RefundObligation;

                    $obligation->fill([
                        'order_id' => $order->getKey(),
                        'payment_session_id' => $paymentSessionId,
                        'amount_minor' => $amountMinor,
                        'currency' => $currency,
                        'opened_reason' => $reason,
                    ]);

                    // Force-filled, not fillable: the status and its stamps
                    // are lifecycle decisions, never mass assignment. The
                    // deadline is computed once here and stored — an
                    // obligation keeps the deadline it was born with, even if
                    // the rule later changes.
                    $obligation->forceFill([
                        'status' => RefundObligationStatus::TERUTANG,
                        'due_at' => $due,
                        'opened_at' => $opened,
                        'opened_by_actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                    ]);

                    $obligation->save();

                    return $obligation;
                },
                action: RefundObligationAuditActions::OPENED,
                subject: fn (RefundObligation $obligation): AuditSubject => new AuditSubject(
                    'refund_obligation',
                    (string) $obligation->getKey(),
                ),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                reason: $reason,
                // Deliberately two already-allowed keys, not an extension
                // of `MetadataAllowlist`. The order link, amount, currency
                // and deadline all live on the obligation row that this
                // event's `subject_id` points at, so copying them here would
                // duplicate canonical data into an append-only table and
                // widen a security allowlist to do it. `reference_number`
                // carries the human-readable order reference an operator
                // actually searches by.
                metadata: [
                    'reference_number' => (string) $order->reference,
                    'new_state' => RefundObligationStatus::TERUTANG->value,
                ],
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateObligation($exception)) {
                throw $exception;
            }

            throw RefundObligationAlreadyOpenException::forOrder((string) $order->getKey());
        }
    }

    /**
     * Whether this query failure is the `order_id` unique-index violation.
     *
     * Same detection shape as `Concerns\DetectsDuplicatePaymentReversal`:
     * SQLSTATE 23505 on Postgres, and the index name for SQLite, whose
     * generic constraint error does not carry that code.
     */
    private function isDuplicateObligation(QueryException $exception): bool
    {
        if ($exception->getCode() === '23505') {
            return true;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'refund_obligations_order_unq')
            || str_contains($message, 'refund_obligations.order_id');
    }
}
