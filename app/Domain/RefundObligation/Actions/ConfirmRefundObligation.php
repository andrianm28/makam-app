<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Actions;

use App\Domain\RefundObligation\Exceptions\RefundObligationTransitionNotAllowedException;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationAuditActions;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Records that the customer's receipt of a refund has been confirmed —
 * `DIEKSEKUSI` → `TERKONFIRMASI`, the terminal transition. Stage R2 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * ---------------------------------------------------------------------------
 * Why "we sent it" and "they got it" are two states and not one
 * ---------------------------------------------------------------------------
 * `RefundObligationStatus`'s own doc block gives the reason: collapsing these
 * would let *"we sent it"* stand in for *"they got it"*. A manual bank
 * transfer can be sent to a mistyped account, bounce back days later, or sit
 * unposted over a weekend. `DIEKSEKUSI` is the operator's claim;
 * `TERKONFIRMASI` is the answer to it. Only the second one means a grieving
 * family actually has their money.
 *
 * That is also why the deadline stops applying at `DIEKSEKUSI`
 * ({@see RefundObligationStatus::isOutstanding()}) while the obligation does
 * not: the operator has done what the deadline measures, and what remains is
 * the bank's and the customer's.
 *
 * ---------------------------------------------------------------------------
 * What this action deliberately does NOT do
 * ---------------------------------------------------------------------------
 * It does not take evidence of its own. The plan requires evidence for the
 * execution — the event the system cannot see — and confirmation is a
 * different kind of fact: an operator has spoken to the family, or watched the
 * debit clear. Demanding a second upload here would invite an operator to
 * re-upload the same transfer receipt, which would make the evidence trail
 * *less* honest, not more. The mandatory audit reason is where a confirmer
 * says how they know.
 *
 * It also posts no journal entry. Stage R3 attaches the reversal to the
 * EXECUTION, not to the confirmation and not to the decision — see the plan's
 * §Tahap R3. Nothing in this file touches `payment_reversals`, `Journal`, or
 * `RecordRefund`.
 *
 * Same lock-then-branch discipline as {@see ExecuteRefundObligation}, for the
 * same reason: two confirmations of one obligation must not both write.
 */
final readonly class ConfirmRefundObligation
{
    /**
     * @param  RefundObligation  $obligation  Re-read under a row lock inside
     *                                        the transaction; the instance passed here may be stale.
     * @param  CarbonImmutable  $confirmedAt  When receipt was confirmed. May
     *                                        not be in the future, and may not precede the execution it
     *                                        confirms — a confirmation dated before the transfer describes
     *                                        something that did not happen.
     * @param  string  $reason  Mandatory and non-blank —
     *                          `REFUND_OBLIGATION_CONFIRMED` is on `SensitiveActions::ACTIONS`. This
     *                          is where the confirmer records HOW they know the money landed.
     *
     * @throws RefundObligationTransitionNotAllowedException when the
     *                                                       obligation is not `DIEKSEKUSI` at the moment the row is locked —
     *                                                       including the case this most protects against, an obligation still
     *                                                       `TERUTANG` being confirmed straight to terminal with no execution
     *                                                       and no evidence beneath it.
     * @throws InvalidArgumentException when the reason is blank or the
     *                                  confirmation date is in the future or precedes `executed_at`.
     */
    public function handle(
        RefundObligation $obligation,
        CarbonImmutable $confirmedAt,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): RefundObligation {
        // ASCII-blank pre-check only; `Audit::record()`'s Unicode-aware
        // `reasonIsBlank()` inside the transaction is the authoritative gate.
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'Confirming receipt of a refund must state how it is known the money landed. It is the '
                .'last thing anybody records about this debt.'
            );
        }

        if ($confirmedAt->greaterThan(CarbonImmutable::now())) {
            throw new InvalidArgumentException(
                'A refund cannot be confirmed as received in the future.'
            );
        }

        $referenceNumber = (string) $obligation->loadMissing('order')->order?->reference;

        return Audit::wrap(
            mutation: function () use ($obligation, $confirmedAt, $actorRef): RefundObligation {
                $locked = RefundObligation::query()
                    ->whereKey($obligation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->status->canTransitionTo(RefundObligationStatus::TERKONFIRMASI)) {
                    throw RefundObligationTransitionNotAllowedException::from(
                        (string) $locked->getKey(),
                        $locked->status,
                        RefundObligationStatus::TERKONFIRMASI,
                    );
                }

                // `executed_at` is guaranteed non-null here: the only status
                // that may transition to `TERKONFIRMASI` is `DIEKSEKUSI`, and
                // both the model guard and the Postgres stamp CHECK refuse
                // that status without the stamp. Read from the locked row.
                $executedAt = $locked->executed_at;

                if ($executedAt !== null && $confirmedAt->lessThan($executedAt)) {
                    throw new InvalidArgumentException(
                        'A refund cannot be confirmed as received before it was sent. The confirmation '
                        .'date precedes this obligation\'s recorded execution.'
                    );
                }

                $locked->forceFill([
                    'status' => RefundObligationStatus::TERKONFIRMASI,
                    'confirmed_at' => $confirmedAt,
                    'confirmed_by_actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                ]);

                $locked->save();

                return $locked;
            },
            action: RefundObligationAuditActions::CONFIRMED,
            subject: new AuditSubject('refund_obligation', (string) $obligation->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            reason: $reason,
            // Same three keys and the same reasoning as
            // `ExecuteRefundObligation` — the order reference an operator
            // searches by, and the transition itself. Nothing about the
            // transfer, the amount, or the evidence.
            metadata: [
                'reference_number' => $referenceNumber,
                'previous_state' => RefundObligationStatus::DIEKSEKUSI->value,
                'new_state' => RefundObligationStatus::TERKONFIRMASI->value,
            ],
        );
    }
}
