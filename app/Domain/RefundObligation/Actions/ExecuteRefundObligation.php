<?php

declare(strict_types=1);

namespace App\Domain\RefundObligation\Actions;

use App\Domain\RefundObligation\Exceptions\RefundObligationAmountMismatchException;
use App\Domain\RefundObligation\Exceptions\RefundObligationEvidenceRequiredException;
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
 * Records that an operator has transferred a refund by hand, with evidence —
 * `TERUTANG` → `DIEKSEKUSI`, Stage R2 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md`.
 *
 * ---------------------------------------------------------------------------
 * This action does not move money, and that is the whole shape of it
 * ---------------------------------------------------------------------------
 * The plan is explicit: *"Karena SumoPod tidak mendukung refund, eksekusi hari
 * ini adalah transfer bank manual oleh operator. Sistem tidak memindahkan
 * uangnya; sistem menagih operator dan menyimpan buktinya."*
 *
 * So nothing here calls a provider. Stage R5 adds a `refund()` seam to
 * `PaymentCheckoutClient`; it does not exist yet, and inventing a call to it
 * would be a fake capability on a money path. What this action does is take an
 * operator's word for a transfer that happened outside this system, demand the
 * evidence that makes that word checkable later, and write both down inside
 * the transaction that changes the status.
 *
 * That framing is why every refusal below is strict. When the system cannot
 * observe the fact, the record of the fact is all there is.
 *
 * ---------------------------------------------------------------------------
 * Lock first, branch second
 * ---------------------------------------------------------------------------
 * The obligation is re-read `lockForUpdate()` inside `Audit::wrap()`'s
 * transaction and every decision is taken against THAT row, never against the
 * instance the caller handed in. Two operators working the overdue queue at
 * the same time is the ordinary case, not the exotic one: without the lock,
 * both read `TERUTANG`, both pass the transition check, and the second
 * overwrites the first's transfer reference and evidence path — leaving one
 * real bank transfer with no record at all, and a customer who was paid twice.
 *
 * `lockForUpdate()` compiles to nothing on SQLite, so the test suite's default
 * driver does not exercise it. The serialisation guarantee is a PostgreSQL
 * one; see this lane's report for what was and was not proven about it.
 *
 * ---------------------------------------------------------------------------
 * `$evidencePath` is a storage path, NOT a document-vault reference
 * ---------------------------------------------------------------------------
 * A deliberate, flagged deviation. `Platform\FinancialLedger\PayoutProof` is
 * this repository's other manual-transfer-evidence type and it carries a vault
 * reference — the shape `AGENTS.md` §Authentication and uploads asks for
 * ("every untrusted file enters private quarantine ... malware scan"). Three
 * facts pushed Stage R2 the other way, and all three are reported rather than
 * buried:
 *
 *  1. R0's column is `execution_evidence_path`, a single string the migration
 *     describes as "the stored proof". A vault reference is a (kind,
 *     identifier) pair, and adding a column means editing a migration under
 *     human review, which this stage is forbidden to do.
 *  2. The vault's pipeline is asynchronous — quarantine, then a scan job, then
 *     promotion. "Catat eksekusi" is one operator action that must either
 *     record the execution or refuse it; it cannot leave an obligation in a
 *     fourth, undeclared state waiting for a scanner.
 *  3. The vault is not usable on beta today. `config/document-vault.php`
 *     resolves `object_storage`/`malware_scanner` to `null` outside
 *     development, and `DocumentVaultServiceProvider` then binds a closure
 *     that throws. Routing evidence through it would make recording a refund
 *     impossible on beta — a debt to a grieving family that no operator could
 *     close. (The same config gap already fails the storage-cleanup job there
 *     100% of the time.)
 *
 * The consequence, stated plainly: **the evidence file is NOT malware-scanned
 * and NOT quarantined.** It is written to a private disk by the Filament
 * action, which applies the type/size hardening
 * `Filament\Admin\Resources\Reconciliations\Actions\UploadProviderStatementAction`
 * uses, and this Action stores only the resulting path. Migrating these files
 * into the vault once it is configured is a follow-up with a human gate on it.
 *
 * This Action never reads the file. It takes a path, checks it is not empty,
 * and stores it — the contents are restricted data that must not reach a log,
 * an exception message, or an audit payload (`AGENTS.md` §Observability).
 */
final readonly class ExecuteRefundObligation
{
    /**
     * @param  RefundObligation  $obligation  Re-read under a row lock inside
     *                                        the transaction; the instance passed here is only used for
     *                                        its key, and may be stale.
     * @param  int  $amountMinor  What the operator actually transferred, in
     *                            minor units. Checked against the debt and never stored — see
     *                            {@see RefundObligationAmountMismatchException} for why the action asks
     *                            for a figure it does not persist.
     * @param  CarbonImmutable  $executedAt  When the transfer really happened,
     *                                       which is usually earlier than now: an operator records the
     *                                       execution after returning from their banking app. May not be in
     *                                       the future — a deadline cannot be met with a transfer that has
     *                                       not occurred.
     * @param  string  $executionReference  The operator's bank transfer
     *                                      reference. An opaque tracking string, never an account number,
     *                                      account holder name, or statement line.
     * @param  string  $evidencePath  Private-disk path to the stored proof.
     *                                Never its contents; see the class doc block.
     * @param  string  $reason  Mandatory and non-blank —
     *                          `REFUND_OBLIGATION_EXECUTED` is on `SensitiveActions::ACTIONS`.
     *
     * @throws RefundObligationEvidenceRequiredException when the transfer
     *                                                   reference or the evidence path is blank. The plan's binding
     *                                                   invariant.
     * @throws RefundObligationAmountMismatchException when the transferred
     *                                                 amount is not the amount owed.
     * @throws RefundObligationTransitionNotAllowedException when the
     *                                                       obligation is not `TERUTANG` at the moment the row is locked.
     * @throws InvalidArgumentException when the reason is blank or the
     *                                  execution date is in the future.
     */
    public function handle(
        RefundObligation $obligation,
        int $amountMinor,
        CarbonImmutable $executedAt,
        string $executionReference,
        string $evidencePath,
        string $reason,
        int|string|null $actorRef,
        string $actorRole,
        AuditSource $source,
    ): RefundObligation {
        $executionReference = trim($executionReference);
        $evidencePath = trim($evidencePath);

        // The invariant first, before anything else is even considered. An
        // execution with no evidence is not a badly-formed execution, it is
        // the thing this whole table exists to make impossible.
        if ($executionReference === '') {
            throw RefundObligationEvidenceRequiredException::forMissing('transfer reference');
        }

        if ($evidencePath === '') {
            throw RefundObligationEvidenceRequiredException::forMissing('stored evidence path');
        }

        // An ASCII-blank pre-check only, deliberately. `Audit::record()`'s
        // own Unicode-aware `reasonIsBlank()` is the authoritative gate and
        // runs inside the transaction below; this one just refuses the
        // obvious case before a row is locked, the same division of labour
        // `Platform\FinancialLedger\Actions\ManualPayout` documents.
        if (trim($reason) === '') {
            throw new InvalidArgumentException(
                'A refund execution must state its justification. Nothing in this system observes the '
                .'transfer, so the operator\'s stated reason is part of the evidence, not a formality.'
            );
        }

        if ($executedAt->greaterThan(CarbonImmutable::now())) {
            throw new InvalidArgumentException(
                'A refund cannot be recorded as executed in the future. Record the execution after the '
                .'transfer has actually been sent, with the date it was sent.'
            );
        }

        // Resolved before the transaction because `Audit::wrap()`'s
        // `$metadata` is an argument, evaluated at call time rather than
        // inside the mutation closure.
        $referenceNumber = (string) $obligation->loadMissing('order')->order?->reference;

        return Audit::wrap(
            mutation: function () use (
                $obligation,
                $amountMinor,
                $executedAt,
                $executionReference,
                $evidencePath,
                $actorRef,
            ): RefundObligation {
                $locked = RefundObligation::query()
                    ->whereKey($obligation->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $locked->status->canTransitionTo(RefundObligationStatus::DIEKSEKUSI)) {
                    throw RefundObligationTransitionNotAllowedException::from(
                        (string) $locked->getKey(),
                        $locked->status,
                        RefundObligationStatus::DIEKSEKUSI,
                    );
                }

                // Read from the locked row, never from the caller's instance:
                // the amount is the thing being checked, so a stale copy of it
                // would make the check meaningless.
                if ($locked->amount_minor !== $amountMinor) {
                    throw RefundObligationAmountMismatchException::for(
                        (string) $locked->getKey(),
                        $locked->amount_minor,
                        $amountMinor,
                    );
                }

                // Force-filled, not fillable. The status and its stamps are
                // lifecycle decisions and `Models\RefundObligation::$fillable`
                // deliberately excludes every one of them.
                $locked->forceFill([
                    'status' => RefundObligationStatus::DIEKSEKUSI,
                    'executed_at' => $executedAt,
                    'executed_by_actor_ref' => $actorRef !== null ? (string) $actorRef : null,
                    'execution_reference' => $executionReference,
                    'execution_evidence_path' => $evidencePath,
                ]);

                $locked->save();

                return $locked;
            },
            action: RefundObligationAuditActions::EXECUTED,
            subject: new AuditSubject('refund_obligation', (string) $obligation->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
            reason: $reason,
            // `reference_number` is the ORDER reference, exactly as
            // `OpenRefundObligation` records it — so the three events in a
            // debt's life are findable by the one string an operator actually
            // searches by. Deliberately NOT the bank transfer reference: that
            // is transfer data, it lives on the row this event's `subject_id`
            // points at, and putting it here would both duplicate canonical
            // data (`AGENTS.md` §Documentation) and widen what reports and
            // exports reading audit payloads can see (§Observability).
            //
            // The amount, the evidence path and the execution date are absent
            // for the same reason, the evidence path most sharply: it points
            // at restricted material and must not travel into an append-only
            // payload. `previous_state` is a constant rather than a read
            // because the transition check above makes `TERUTANG` the only
            // state from which this event can be written at all.
            metadata: [
                'reference_number' => $referenceNumber,
                'previous_state' => RefundObligationStatus::TERUTANG->value,
                'new_state' => RefundObligationStatus::DIEKSEKUSI->value,
            ],
        );
    }
}
