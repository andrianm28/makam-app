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
use InvalidArgumentException;

/**
 * The paid Pre-Need flow, step 4: `quoted -> agreed` — the case-level
 * acceptance (AC2: "bind the acceptance to the exact agreement and quote
 * versions"; AC5 in the plan's Task 3: "binds the exact agreement + quote
 * versions").
 *
 * ---------------------------------------------------------------------------
 * One producer for `agreement.accepted.v1` (post-merge reality)
 * ---------------------------------------------------------------------------
 * The plan's signature-pinned shape is
 * `AcceptPreNeedAgreement(PreNeedCase $case, Agreement $agreement, string
 * $actorRef, ...)` calling Lane 1's `AcceptAgreement` (which binds
 * `accepted_by_ref`/`accepted_quote_id`/`accepted_agreement_version_id` on
 * the `agreements` row and emits `agreement.accepted.v1`). With both lanes
 * merged this action takes the plain agreement REFERENCE (the panel
 * composition hands it the created row's `reference`) and records the
 * acceptance ON THE CASE — the case is bound to the Lane-1 row:
 * `agreement_id` (an opaque reference string; the `agreements` table is
 * Lane 1's), `accepted_by_ref`, and `accepted_quote_id` (both columns
 * added by `2026_08_16_120000_create_pre_need_cases_table.php` for
 * exactly this).
 *
 * This action does NOT emit `agreement.accepted.v1`: the catalogued event
 * has exactly ONE producer — Lane 1's `AcceptAgreement`, which emits it
 * on the `agreements` row it accepts (the panel composition runs that
 * producer first; whole-branch review finding). This action records only
 * the case-level binding; a direct-domain caller that never ran Lane 1's
 * producer leaves the outbox silent. Task 4's resource renders the case's
 * acceptance.
 *
 * ---------------------------------------------------------------------------
 * Sequence
 * ---------------------------------------------------------------------------
 * Gate first (`PreNeedGate::assertOpen()` — denial audited, then the
 * uniform `PreNeedGateClosedException`). Then, under the case-row lock:
 * the status chain is asserted, the acceptance is bound on the case, and
 * the audit row is written — all in one transaction (`Audit::wrap()`).
 */
final readonly class AcceptPreNeedAgreement
{
    public function __invoke(
        PreNeedCase $case,
        string $agreementId,
        string $actorRef,
        string $actorRole,
        ?string $quoteId = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        if (trim($agreementId) === '') {
            throw new InvalidArgumentException('An agreement acceptance requires a non-blank agreement reference.');
        }

        PreNeedGate::assertOpen($actorRef, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $agreementId, $actorRef, $quoteId),
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
    ): PreNeedCase {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::AGREED);

        $current->forceFill([
            'status' => PreNeedCaseStatus::AGREED->value,
            'agreement_id' => $agreementId,
            'accepted_by_ref' => $actorRef,
            'accepted_quote_id' => $quoteId,
        ])->save();

        return $current;
    }
}
