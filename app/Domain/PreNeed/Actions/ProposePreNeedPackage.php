<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * The paid Pre-Need flow, step 1: `interest -> proposal`, binding the
 * proposed cemetery and (optional) package to the case.
 *
 * The G-LEGAL-01 gate check comes FIRST — `PreNeedGate::assertOpen()`
 * audits the denial (`PRENEED_GATE_DENIED`, outcome `denied`) and throws
 * the uniform `PreNeedGateClosedException` before any read or write that
 * could change state. Only with the gate open does the transition run:
 * the case row is re-read under `lockForUpdate()` (serializing concurrent
 * transitions on the same case, the same row-lock discipline
 * `RecordOrderStatusChange` and `ReservePlot` use), the status chain is
 * asserted against the LOCKED row, and the state change + its
 * `PRENEED_PROPOSED` audit row commit together (`Audit::wrap()` — AC4's
 * "mutation and audit record can never be committed separately").
 */
final readonly class ProposePreNeedPackage
{
    public function __invoke(
        PreNeedCase $case,
        Cemetery $cemetery,
        ?CemeteryPackage $package,
        int|string $actorReference,
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $cemetery, $package),
            action: PreNeedAuditActions::PRENEED_PROPOSED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    private function apply(PreNeedCase $case, Cemetery $cemetery, ?CemeteryPackage $package): PreNeedCase
    {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::PROPOSAL);

        $current->forceFill([
            'status' => PreNeedCaseStatus::PROPOSAL->value,
            'cemetery_id' => $cemetery->getKey(),
            'cemetery_package_id' => $package?->getKey(),
        ])->save();

        return $current;
    }
}
