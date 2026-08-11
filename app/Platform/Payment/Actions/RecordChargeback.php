<?php

declare(strict_types=1);

namespace App\Platform\Payment\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Payment\Actions\Concerns\DetectsDuplicatePaymentReversal;
use App\Platform\Payment\Exceptions\PaymentReversalAlreadyRecordedException;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentReversalType;
use Illuminate\Database\QueryException;

/**
 * Task 6's `RecordChargeback` — the `Chargeback` half of the safe slice
 * (Wave 1d Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-6-brief.md`).
 * `App\Platform\Payment\ReversalService` is its only caller.
 *
 * Records a `PaymentReversal` row (`reversal_type = CHARGEBACK`) and writes
 * the mandatory-reason audit `PaymentAuditActions::CHARGEBACK`
 * (`SensitiveActions::PAYMENT_CHARGEBACK`) inside `Audit::wrap()`'s single
 * transaction. Structurally identical to `RecordRefund` — see that class's
 * doc block for the full rationale (no `Journal::postReversal()` call, no
 * `PaymentProvider` contract, no customer-balance effect — none of the
 * three has anything real to reach in this branch).
 *
 * The original plan's Task 6 text also describes a "customer-balance
 * effect" for chargebacks specifically. No customer-balance concept exists
 * anywhere in this repository (verified, not assumed — see
 * `task-6-brief.md`'s Wave 1d ruling point 3) — this class does not
 * fabricate one.
 */
final readonly class RecordChargeback
{
    use DetectsDuplicatePaymentReversal;

    public function handle(
        string $reference,
        ?int $amountMinor,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): PaymentReversal {
        $this->assertNotBlank($reference, 'reference');

        try {
            return Audit::wrap(
                mutation: fn (): PaymentReversal => PaymentReversal::createRecorded([
                    'reversal_type' => PaymentReversalType::Chargeback->value,
                    'reference' => $reference,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                    'recorded_by_actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                ]),
                action: PaymentAuditActions::CHARGEBACK,
                subject: fn (PaymentReversal $reversal): AuditSubject => new AuditSubject('payment_reversal', $reversal->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: $source,
                reason: $reason,
                metadata: ['reference_number' => $reference],
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateReversal($exception)) {
                throw $exception;
            }

            throw PaymentReversalAlreadyRecordedException::forReference(PaymentReversalType::Chargeback, $reference);
        }
    }
}
