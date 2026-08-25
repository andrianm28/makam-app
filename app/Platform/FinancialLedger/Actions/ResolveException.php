<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\SensitiveActions;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Contracts\ReconciliationAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Exceptions\ReconciliationExceptionAlreadyResolvedException;
use App\Platform\FinancialLedger\Exceptions\ReconciliationNotAuthorisedException;
use App\Platform\FinancialLedger\FinanceReconciliationAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\ReconciliationException as ReconciliationExceptionModel;
use App\Platform\FinancialLedger\ReconciliationCorrection;
use App\Platform\FinancialLedger\ReconciliationDecision;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationStatus;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC10 + AC12: an authorised human decides one reconciliation exception.
 *
 * ---------------------------------------------------------------------------
 * The ONLY way an exception ever reaches `resolved`
 * ---------------------------------------------------------------------------
 * AC10 is explicit: no exception resolves by period closure. Closing, ending or
 * rolling over a period must never flip an `open` exception to `resolved` as a
 * side effect, and neither must re-running reconciliation, archiving a report,
 * or anything else. An exception is resolved only by an authorised human
 * decision recorded here.
 *
 * That is enforced structurally rather than by convention: no model method
 * flips the status (see `Models\ReconciliationException`), `RunReconciliation`
 * updates only open evidence or inserts a new version after a resolved finding, and
 * `tests/Feature/FinancialLedger/ResolveReconciliationExceptionTest.php`
 * asserts over the whole module tree that this file is the only writer of
 * `resolved`.
 *
 * ---------------------------------------------------------------------------
 * All three requirements, or nothing is written
 * ---------------------------------------------------------------------------
 *  1. a non-blank reason — `RECONCILIATION_EXCEPTION_RESOLVED` is on
 *     `SensitiveActions::ACTIONS` (Wave 1c ruling, user-approved 10 Aug 2026);
 *  2. a decision from `ReconciliationDecision`'s closed list;
 *  3. an actor holding finance authority for THIS badan usaha, derived from
 *     the server-side `ActorContext`.
 *
 * The first three checks run BEFORE the transaction opens, so a refused
 * resolution leaves the exception `open`, writes no audit row, and posts no
 * journal batch. Ordering the mandatory-reason guard at the top follows the
 * precedent set when `RecordServiceDefinitionPriceVersion` was corrected and
 * that `ManualPayout` already follows: the guard belongs at the signature, not
 * as a runtime throw from inside `DB::transaction()`.
 *
 * ---------------------------------------------------------------------------
 * `post_correction` posts a NEW batch. It never edits one
 * ---------------------------------------------------------------------------
 * When the decision is `post_correction`, the correction is a new reversing or
 * adjusting batch posted through the Task 3 `Journal` write API, carried by
 * `ReconciliationCorrection` and posted from inside THIS Action's transaction —
 * so the resolution and the correction commit or roll back together, and a
 * resolved exception can never end up with no correction behind it.
 * `Journal::post()` opens no transaction of its own by design, which is exactly
 * why it is called from in here; `Journal::postReversal()` does open one
 * (deliberately different since Task 4), and nesting it becomes a savepoint
 * inside this transaction, so an outer failure still unwinds it.
 *
 * `journal_batches` and `journal_entries` take ZERO `UPDATE` and ZERO `DELETE`,
 * ever. Task 6 revokes both from the application role at the database level.
 *
 * A correction supplied with any other decision is refused, and
 * `post_correction` without one is refused too: recording the decision now and
 * posting the correction afterwards is precisely the gap this pairing closes.
 *
 * ---------------------------------------------------------------------------
 * Authorisation: server-side actor, same discipline as payout
 * ---------------------------------------------------------------------------
 * The deciding actor comes from the authenticated server-side `ActorContext`.
 * Caller-supplied references or roles never select an actor or grant a role, and
 * an empty role list is not permission. The policy itself is a separate seam
 * from the payout one on purpose — see `Contracts\ReconciliationAuthorizer` for
 * why merging them would silently grant payout rights to anyone who can accept
 * a variance.
 */
final class ResolveException
{
    /**
     * Added to `SensitiveActions::ACTIONS` in this same commit, under the
     * user-approved Wave 1c ruling recorded in
     * `docs/superpowers/plans/2026-08-10-wave1b-financial-decisions.md`. A
     * resolution that changes a reconciliation outcome without a recorded
     * reason is exactly the control that list exists to enforce.
     */
    public const string AUDIT_ACTION = 'RECONCILIATION_EXCEPTION_RESOLVED';

    /**
     * Dependencies are explicit seams so the Action fails closed until the
     * sibling identity module provides an authoritative role source.
     */
    public function __construct(
        private readonly ActorContext $actorContext,
        private readonly ReconciliationAuthorizer $authorizer = new FinanceReconciliationAuthorizer,
        private readonly JournalContract $journal = new Journal,
    ) {}

    /**
     * Decide one reconciliation exception.
     *
     * @param  string  $decision  One of `ReconciliationDecision::KNOWN_DECISIONS`.
     * @param  string  $reason  Mandatory and non-blank. Must be free of
     *                          restricted data, the same discipline `Audit::record()`'s own
     *                          `$reason` carries — it is written to the audit event verbatim.
     * @param  ReconciliationCorrection|null  $correction  Required when, and
     *                                                     only when, `$decision` is `post_correction`.
     *
     * @throws AuditReasonRequiredException on a blank reason.
     * @throws InvalidReconciliationException on an unknown decision or a
     *                                        correction that does not match the decision or exception.
     * @throws ReconciliationNotAuthorisedException when the actor lacks finance
     *                                              authority or the exception is unavailable to this actor.
     * @throws ReconciliationExceptionAlreadyResolvedException on a second
     *                                                         decision for an exception a human already decided.
     */
    public function resolve(
        string $exceptionId,
        string $decision,
        string $reason,
        ?ReconciliationCorrection $correction = null,
        ?string $correlationId = null,
        ?CarbonImmutable $decidedAt = null,
    ): ReconciliationExceptionModel {
        if (trim($reason) === '') {
            throw AuditReasonRequiredException::forAction(self::AUDIT_ACTION);
        }

        ReconciliationDecision::assertKnown($decision);

        if ($correction !== null && $decision !== ReconciliationDecision::POST_CORRECTION) {
            throw InvalidReconciliationException::forCorrectionWithoutPostCorrection($decision);
        }

        if ($correction === null && $decision === ReconciliationDecision::POST_CORRECTION) {
            throw InvalidReconciliationException::forPostCorrectionWithoutCorrection();
        }

        if (! Str::isUuid($exceptionId)) {
            throw ReconciliationNotAuthorisedException::forUnavailableException();
        }

        $exception = ReconciliationExceptionModel::query()->find($exceptionId);

        if (! $exception instanceof ReconciliationExceptionModel) {
            throw ReconciliationNotAuthorisedException::forUnavailableException();
        }

        $actorRef = $this->actorReference();

        try {
            $actorRole = $this->authorizer->authorize($this->actorContext, (string) $exception->entity_ref);
        } catch (ReconciliationNotAuthorisedException) {
            throw ReconciliationNotAuthorisedException::forUnavailableException();
        }

        $decidedAt ??= CarbonImmutable::now();
        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        $reconciliationId = (string) $exception->reconciliation_id;

        return DB::transaction(function () use (
            $exceptionId,
            $reconciliationId,
            $decision,
            $reason,
            $correction,
            $actorRef,
            $actorRole,
            $correlationId,
            $decidedAt,
        ): ReconciliationExceptionModel {
            // Lock the parent first. Reconciliation runs lock it before touching
            // findings too, so resolution and a concurrent first run have one
            // lock order and cannot race their version/status decisions.
            $reconciliation = DB::table('reconciliations')
                ->where('id', $reconciliationId)
                ->lockForUpdate()
                ->first();

            if ($reconciliation === null) {
                throw ReconciliationNotAuthorisedException::forUnavailableException();
            }

            // Re-read under a row lock rather than trusting the instance loaded
            // before the guards ran: between those two points another request
            // may have decided this exception. On PostgreSQL the lock serialises
            // the two attempts; on SQLite it is a no-op, which is why the status
            // check below is re-made HERE, against freshly read state, rather
            // than reusing the earlier read.
            $locked = DB::table('reconciliation_exceptions')
                ->where('id', $exceptionId)
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                throw ReconciliationNotAuthorisedException::forUnavailableException();
            }

            if ($locked->status !== ReconciliationExceptionStatus::OPEN) {
                throw ReconciliationExceptionAlreadyResolvedException::forException(
                    $exceptionId,
                    (string) $locked->decision,
                );
            }

            $correction?->assertMatches(
                entityRef: (string) $locked->entity_ref,
                subjectRef: (string) $locked->subject_ref,
            );
            $this->assertCorrectionJournalEntity($correction, (string) $locked->entity_ref, (string) $locked->subject_ref);

            // Posted BEFORE the status flip on purpose: a correction that
            // collides on its business key throws, and this whole transaction —
            // the resolution included — rolls back. AC5's idempotency is the
            // database's, not a check in here.
            $correction?->post($this->journal, $reason, $correlationId);

            DB::table('reconciliation_exceptions')
                ->where('id', $exceptionId)
                ->where('status', ReconciliationExceptionStatus::OPEN)
                ->update([
                    'status' => ReconciliationExceptionStatus::RESOLVED,
                    'decision' => $decision,
                    'decided_by' => $actorRef,
                    'decided_at' => $decidedAt,
                    'correlation_id' => $correlationId,
                    'updated_at' => $decidedAt,
                ]);

            $openCount = DB::table('reconciliation_exceptions')
                ->where('entity_ref', (string) $locked->entity_ref)
                ->where('period', (string) $locked->period)
                ->where('status', ReconciliationExceptionStatus::OPEN)
                ->count();

            DB::table('reconciliations')
                ->where('id', $reconciliationId)
                ->update([
                    'status' => $reconciliation->status === ReconciliationStatus::STATEMENT_MISSING
                        ? ReconciliationStatus::STATEMENT_MISSING
                        : ($openCount > 0
                            ? ReconciliationStatus::EXCEPTIONS_OPEN
                            : ReconciliationStatus::MATCHED),
                    'updated_at' => $decidedAt,
                ]);

            Audit::record(
                action: self::AUDIT_ACTION,
                subject: new AuditSubject('reconciliation_exception', $exceptionId),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: $actorRole,
                source: AuditSource::Panel,
                reason: $reason,
                correlationId: $correlationId,
                // Every key is already on `MetadataAllowlist::ALLOWED_KEYS`, so
                // no allowlist edit is needed — that file is a shared privacy
                // guardrail and adding to it is a separate, reviewed change.
                // All three values are state names and a closed-list decision
                // value: no amount, no statement reference, no identity.
                metadata: [
                    'previous_state' => ReconciliationExceptionStatus::OPEN,
                    'new_state' => ReconciliationExceptionStatus::RESOLVED,
                    'note' => $decision,
                ],
            );

            return ReconciliationExceptionModel::query()->findOrFail($exceptionId);
        });
    }

    private function actorReference(): string
    {
        if ($this->actorContext->identityReference === null) {
            throw ReconciliationNotAuthorisedException::forUnavailableException();
        }

        return (string) $this->actorContext->identityReference;
    }

    private function assertCorrectionJournalEntity(
        ?ReconciliationCorrection $correction,
        string $entityRef,
        string $subjectRef,
    ): void {
        if ($correction === null || ! $correction->isReversal()) {
            return;
        }

        $journalEntityRef = DB::table('journal_batches')
            ->where('business_key', $subjectRef)
            ->value('entity_ref');

        if ($journalEntityRef !== null && (string) $journalEntityRef !== $entityRef) {
            throw InvalidReconciliationException::forCorrectionJournalEntityMismatch(
                expected: $entityRef,
                actual: (string) $journalEntityRef,
            );
        }
    }

    /**
     * Kept so a reader checking "is `RECONCILIATION_EXCEPTION_RESOLVED` really
     * on the sensitive list" has an answer in this file rather than a
     * cross-reference, without this module restating the list itself.
     */
    public static function requiresAuditReason(): bool
    {
        return SensitiveActions::requiresReason(self::AUDIT_ACTION);
    }
}
