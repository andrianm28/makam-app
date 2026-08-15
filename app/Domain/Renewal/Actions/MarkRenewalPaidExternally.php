<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * The AC10 privileged write path for an ALREADY-OPEN renewal row — marks a
 * renewal that was opened online (`source = RenewalSource::ONLINE`) as
 * settled by money that changed hands outside the platform. Distinct from
 * `MarkExternalRenewal`, which CREATES the renewal row itself with
 * `source = RenewalSource::EXTERNAL`; this action transitions an existing
 * `MENUNGGU_PEMBAYARAN` row to `DIBAYAR` and records the evidence trail.
 *
 * ---------------------------------------------------------------------------
 * The settle guard
 * ---------------------------------------------------------------------------
 * A renewal is settled exactly once. The `renewal_external_markings` row is
 * this path's evidence trail, so a second invocation against the same
 * renewal would forge a second attestation for a payment that already
 * settled — `RenewalAlreadySettledException` is thrown instead, before any
 * state change and therefore before any audit row (the `Audit::wrap()`
 * transaction rolls back everything, so a refused second attempt leaves no
 * trace at all).
 *
 * `RENEWAL_EXTERNAL_MARKING` is on `SensitiveActions::ACTIONS` (L8 Task 7),
 * so `Audit::wrap()` enforces a mandatory, non-blank `$reason` — this
 * action's signature always carries one.
 */
final readonly class MarkRenewalPaidExternally
{
    public function __invoke(
        Renewal $renewal,
        string $evidence,
        string $reason,
        string $actorRef,
        string $actorRole,
    ): void {
        Audit::wrap(
            mutation: function () use ($renewal, $evidence, $reason, $actorRef): void {
                $current = Renewal::query()->lockForUpdate()->findOrFail($renewal->getKey());

                if ($current->status !== RenewalStatus::DIBAYAR && $current->settled_at !== null) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                if ($current->status !== RenewalStatus::MENUNGGU_PEMBAYARAN) {
                    throw RenewalAlreadySettledException::forRenewal((string) $current->getKey());
                }

                $current->update([
                    'status' => RenewalStatus::DIBAYAR,
                    'settled_at' => now(),
                ]);

                if ($renewal !== $current) {
                    $renewal->setRawAttributes($current->getAttributes(), true);
                }

                RenewalExternalMarking::query()->create([
                    'renewal_id' => $current->getKey(),
                    'marked_by_actor_ref' => $actorRef,
                    'evidence_reference' => $evidence,
                    'reason' => $reason,
                    'marked_at' => now(),
                ]);
            },
            action: 'RENEWAL_EXTERNAL_MARKING',
            subject: fn (): AuditSubject => new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
