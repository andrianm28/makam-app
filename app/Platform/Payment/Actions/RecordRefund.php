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
 * Task 6's `RecordRefund` — the `Refund` half of the safe slice (Wave 1d
 * Append-Correction,
 * `.superpowers/sdd/2026-08-09-platform-payment-adapter/task-6-brief.md`).
 * `App\Platform\Payment\ReversalService` is its only caller.
 *
 * Records a `PaymentReversal` row (`reversal_type = REFUND`) and writes the
 * mandatory-reason audit `PaymentAuditActions::REFUND`
 * (`SensitiveActions::PAYMENT_REFUND`) inside `Audit::wrap()`'s single
 * transaction.
 *
 * ---------------------------------------------------------------------------
 * Deliberately does NOT call `Journal::postReversal()` or
 * `PaymentProvider::refund()`
 * ---------------------------------------------------------------------------
 * The original plan's Task 6 text asked a refund to "post a journal
 * reversal batch referencing the original `business_key`" and to call
 * `PaymentProvider::refund()` "when the sandbox supports it." The Wave 1d
 * ruling found neither has anything real to call: `Contracts/
 * PaymentProvider` does not exist anywhere in this branch, and
 * `Journal::post()`/`Journal::postReversal()` have zero real call sites —
 * authoring either now would be new-contract scope creep, the same
 * discipline Task 7 was held to for not authoring a rival `Journal`. This
 * class writes only its own `payment_reversals` row and its own audit
 * event.
 *
 * ---------------------------------------------------------------------------
 * The `(reversal_type, reference)` unique-constraint violation is caught
 * and translated, mirroring `Actions\UploadDocument::
 * isDuplicateClientUploadId()`'s detection pattern
 * ---------------------------------------------------------------------------
 * A duplicate `(REFUND, $reference)` pair raises a raw `QueryException`
 * from inside `Audit::wrap()`'s transaction (which has already rolled the
 * transaction back by the time it propagates out of `DB::transaction()`).
 * This is caught here and re-thrown as
 * `PaymentReversalAlreadyRecordedException` — a clear domain exception, not
 * a raw SQL error leaking to the caller. Detection logic is shared with
 * `RecordChargeback` via `Concerns\DetectsDuplicatePaymentReversal`.
 */
final readonly class RecordRefund
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
                    'reversal_type' => PaymentReversalType::Refund->value,
                    'reference' => $reference,
                    'amount_minor' => $amountMinor,
                    'reason' => $reason,
                    'recorded_by_actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                ]),
                action: PaymentAuditActions::REFUND,
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

            throw PaymentReversalAlreadyRecordedException::forReference(PaymentReversalType::Refund, $reference);
        }
    }
}
