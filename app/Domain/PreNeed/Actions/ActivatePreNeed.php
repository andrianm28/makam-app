<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\FuneralCase\Actions\OpenFuneralCase;
use App\Domain\FuneralCase\Models\FuneralCase;
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

/**
 * The paid Pre-Need flow, final step: `settled -> activated` (AC8: "WHEN
 * future activation/claim occurs THE SYSTEM SHALL link it to a new At-Need
 * FuneralCase without losing original contract history").
 *
 * The activation opens a brand-new At-Need `FuneralCase` through the P0
 * seam `OpenFuneralCase($activationDraft)` — the same Action a fresh
 * At-Need submission routes to, emitting `funeral_case.created.v1` — and
 * links it to the case as `activated_funeral_case_id`. Only the status and
 * that one link are written: the case's agreement, quote, reservation,
 * acceptance and settlement references stay EXACTLY as the contract left
 * them (AC8's "without losing original contract history"; the test suite
 * asserts each link survives).
 *
 * The `pre_need_case.activated.v1` outbox event (references only: case id
 * + the new funeral case id) is emitted in the same transaction as the
 * case creation, the status write, and the `PRENEED_ACTIVATED` audit row.
 * The event name is appended to `docs/contracts/event-catalog.md` by this
 * task — it was not catalogued before.
 *
 * Gate first (`PreNeedGate::assertOpen()` — denial audited, then the
 * uniform `PreNeedGateClosedException`), then the case-row lock + status
 * assertion, then `OpenFuneralCase` (which opens no transaction of its own
 * — the case and this mutation commit together).
 */
final readonly class ActivatePreNeed
{
    public function __construct(
        private OpenFuneralCase $openFuneralCase,
    ) {}

    public function __invoke(
        PreNeedCase $case,
        BookingDraft $activationDraft,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $activationDraft),
            action: PreNeedAuditActions::PRENEED_ACTIVATED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function apply(PreNeedCase $case, BookingDraft $activationDraft): PreNeedCase
    {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::ACTIVATED);

        $funeralCase = ($this->openFuneralCase)($activationDraft);

        // AC8: only the status and the new case link move — the contract
        // history (agreement/quote/reservation/acceptance/settlement) is
        // untouched.
        $current->forceFill([
            'status' => PreNeedCaseStatus::ACTIVATED->value,
            'activated_funeral_case_id' => $funeralCase->getKey(),
        ])->save();

        $this->emitActivated($current, $funeralCase);

        return $current;
    }

    /**
     * `event-catalog.md` — appended by this task: `pre_need_case.activated.v1`,
     * producer PreNeed. References only: the case id and the new At-Need
     * funeral case id; no contract content, no restricted data.
     */
    private function emitActivated(PreNeedCase $case, FuneralCase $funeralCase): void
    {
        Outbox::record(
            eventName: 'pre_need_case.activated.v1',
            eventVersion: 1,
            aggregateType: 'pre_need_case',
            aggregateId: (string) $case->getKey(),
            data: [
                'pre_need_case_id' => (string) $case->getKey(),
                'funeral_case_id' => (string) $funeralCase->getKey(),
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "pre_need_case_activated:{$case->getKey()}",
        );
    }
}
