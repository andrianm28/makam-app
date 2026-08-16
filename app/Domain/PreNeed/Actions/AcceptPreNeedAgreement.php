<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use InvalidArgumentException;

/**
 * The paid Pre-Need flow, step 4: `quoted -> agreed` — the case-level
 * acceptance (AC2: "bind the acceptance to the exact agreement and quote
 * versions"; AC5 in the plan's Task 3: "binds the exact agreement + quote
 * versions").
 *
 * ---------------------------------------------------------------------------
 * Lane reconciliation — why this takes a plain agreement reference
 * ---------------------------------------------------------------------------
 * The plan's signature-pinned shape is
 * `AcceptPreNeedAgreement(PreNeedCase $case, Agreement $agreement, string
 * $actorRef, ...)` calling Lane 1's `AcceptAgreement` (which binds
 * `accepted_by_ref`/`accepted_quote_id`/`accepted_agreement_version_id` on
 * the `agreements` row and emits `agreement.accepted.v1`). Lane 1's
 * `Agreement` class does NOT exist on this branch (parallel lane, not yet
 * merged), and referencing it here is forbidden (a phpstan class-not-found
 * on merge). This lane therefore records the acceptance ON THE CASE — the
 * case is the source of truth until the merge: `agreement_id` (an opaque
 * reference string; the `agreements` table is Lane 1's), `accepted_by_ref`,
 * and `accepted_quote_id` (both columns added by
 * `2026_08_16_120000_create_pre_need_cases_table.php` for exactly this).
 *
 * The `agreement.accepted.v1` outbox event is emitted here with the
 * exact-version payload (agreement reference + quote id + agreement
 * version id) so consumers have the same binding Lane 1's event carries;
 * after the merge the event keeps ONE producer (Lane 1's `AcceptAgreement`
 * on its own rows) while the case-level record and this event remain for
 * the case's own acceptance history. Task 4's resource renders the case's
 * acceptance.
 *
 * ---------------------------------------------------------------------------
 * Sequence
 * ---------------------------------------------------------------------------
 * Gate first (`PreNeedGate::assertOpen()` — denial audited, then the
 * uniform `PreNeedGateClosedException`). Then, under the case-row lock:
 * the status chain is asserted, the acceptance is bound on the case, the
 * audit row is written, and the outbox event is emitted — all in the same
 * transaction (`Audit::wrap()`; `Outbox::record()` reads the trace
 * context itself).
 */
final readonly class AcceptPreNeedAgreement
{
    public function __invoke(
        PreNeedCase $case,
        string $agreementId,
        string $actorRef,
        string $actorRole,
        ?string $quoteId = null,
        ?string $agreementVersionId = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        if (trim($agreementId) === '') {
            throw new InvalidArgumentException('An agreement acceptance requires a non-blank agreement reference.');
        }

        PreNeedGate::assertOpen($actorRef, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $agreementId, $actorRef, $quoteId, $agreementVersionId),
            action: PreNeedAuditActions::PRENEED_AGREEMENT_ACCEPTED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function apply(
        PreNeedCase $case,
        string $agreementId,
        string $actorRef,
        ?string $quoteId,
        ?string $agreementVersionId,
    ): PreNeedCase {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::AGREED);

        $current->forceFill([
            'status' => PreNeedCaseStatus::AGREED->value,
            'agreement_id' => $agreementId,
            'accepted_by_ref' => $actorRef,
            'accepted_quote_id' => $quoteId,
        ])->save();

        $this->emitAgreementAccepted($current, $quoteId, $agreementVersionId);

        return $current;
    }

    /**
     * `docs/contracts/event-catalog.md:21` — `agreement.accepted.v1`,
     * producer now "Agreement, PreNeed". References only, no restricted
     * data: the agreement reference and the exact quote/agreement versions
     * (AC2), plus the accepting subject's own reference. The idempotency
     * key is case-scoped, so one case cannot emit this event twice even on
     * a redelivered transition.
     */
    private function emitAgreementAccepted(PreNeedCase $case, ?string $quoteId, ?string $agreementVersionId): void
    {
        Outbox::record(
            eventName: 'agreement.accepted.v1',
            eventVersion: 1,
            aggregateType: 'agreement',
            aggregateId: (string) $case->agreement_id,
            data: [
                'agreement_id' => $case->agreement_id,
                'quote_id' => $quoteId,
                'agreement_version_id' => $agreementVersionId,
                'subject_type' => 'pre_need_case',
                'subject_id' => (string) $case->getKey(),
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "pre_need_agreement_accepted:{$case->getKey()}",
        );
    }
}
