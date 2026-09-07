<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalMarkingPolicy;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The AC10 privileged write path for an ALREADY-OPEN renewal row — marks a
 * renewal that was opened online (`source = RenewalSource::ONLINE`) as
 * settled by money that changed hands outside the platform. Distinct from
 * `MarkExternalRenewal`, which CREATES the renewal row itself with
 * `source = RenewalSource::EXTERNAL`; this action transitions an existing
 * `MENUNGGU_PEMBAYARAN` row to `DIBAYAR` and records the evidence trail.
 *
 * ---------------------------------------------------------------------------
 * AUTHZ-04: authorization lives HERE now, not only at the Filament button
 * ---------------------------------------------------------------------------
 * Before this fix, this action trusted whatever `$actorRef`/`$actorRole`
 * its caller passed in, and the CREATE path's sibling
 * (`MarkExternalRenewal`) was the only one of the two that actually
 * re-checked `RenewalMarkingPolicy` (role AND a privileged cemetery-scope
 * grant) at the point of mutation — the Filament action's `->authorize()`
 * gate (`OrderTransitionAuthorizerContract`, role-only for the
 * `record_external_renewal_payment` money transition) was the only check
 * this path had. That let a `finance` actor with ZERO cemetery grant settle
 * a renewal for any cemetery, while the same actor creating an external
 * renewal from scratch would be correctly refused by the scoped policy.
 *
 * This action now resolves the acting `ActorContext` itself and calls
 * `RenewalMarkingPolicy::allows()` — the exact check `MarkExternalRenewal`
 * already made — before `Audit::wrap()` runs, and uses the ROLE THE POLICY
 * MATCHED for the audit row instead of trusting a caller-supplied string.
 * The Filament `RecordExternalRenewalPaymentAction::authorize()` callback
 * stays as the button/mount-level gate only; it is no longer the source of
 * truth for whether the settlement is actually permitted.
 *
 * `RenewalMarkingPolicy::PERMITTED_ROLES` is `admin` only today (Ruling B,
 * 12 Aug 2026, for the CREATE path). Moving this SETTLE path onto the same
 * policy means a `finance` actor — previously able to settle via the
 * role-only `OrderTransitionAuthorizer` check — can no longer complete this
 * action at all, even with a cemetery grant. See this batch's plan doc and
 * PR description for the explicit call-out: if admitting `finance` to this
 * settle path (with a cemetery-scope requirement) was actually an
 * intentional, documented carve-out, that is a decision for a human to make
 * and record in `docs/security/rbac-matrix.md` — not something this fix
 * silently reintroduces.
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
    public function __construct(
        private ActorContextResolver $actors,
        private RenewalMarkingPolicy $policy,
    ) {}

    /**
     * @throws AuthorizationException when the actor is not authorized.
     */
    public function __invoke(
        Renewal $renewal,
        string $evidence,
        string $reason,
    ): void {
        $actor = $this->actors->resolve();

        $grave = $renewal->graveRecord ?? GraveRecord::query()->findOrFail($renewal->grave_record_id);

        $authorizingRole = $this->policy->allows($actor, $grave);
        $actorRef = (string) $actor->identityReference;

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
            actorRole: $authorizingRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
