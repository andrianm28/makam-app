<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalMarkingPolicy;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;

/**
 * The AC10 privileged write path — marks a renewal as paid outside the
 * platform ("admin/operator SHALL be able to mark a renewal as paid
 * externally, with evidence").
 *
 * Ruling B (explicit human sign-off, 12 Aug 2026): **admin only.** `operator`
 * is explicitly denied. The authorizer requires a role AND scope grant,
 * scoped by cemetery. `RenewalMarkingPolicy::allows()` enforces both.
 *
 * Two rows are written in one transaction via `Audit::wrap()`:
 * - `renewals` with `source = RenewalSource::EXTERNAL` — this row also
 *   participates in the AC11 uniqueness guard, so a later online renewal
 *   attempt for the same grave+period will be rejected by the constraint;
 * - `renewal_external_markings` with the evidence and reason.
 *
 * The `reason` is passed to `Audit::record()` as part of `Audit::wrap()`,
 * which enforces the `RENEWAL_EXTERNAL_MARKING` sensitive-action check
 * (blank/Unicode-blank reasons are rejected by `Audit::reasonIsBlank()`).
 */
final readonly class MarkExternalRenewal
{
    public function __construct(
        private ActorContextResolver $actors,
        private RenewalMarkingPolicy $policy,
    ) {}

    /**
     * @throws AuthorizationException when the actor is not authorized.
     */
    public function __invoke(
        GraveRecord $grave,
        string $targetDuePeriod,
        string $evidence,
        string $reason,
    ): void {
        $actor = $this->actors->resolve();
        $this->policy->allows($actor, $grave);

        Audit::wrap(
            mutation: function () use ($actor, $grave, $targetDuePeriod, $evidence, $reason): Renewal {
                $renewal = Renewal::create([
                    'grave_record_id' => $grave->id,
                    'target_due_period' => $targetDuePeriod,
                    'reference' => 'EXT-'.Str::upper(Str::random(8)),
                    'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
                    'source' => RenewalSource::EXTERNAL,
                ]);

                RenewalExternalMarking::create([
                    'renewal_id' => $renewal->id,
                    'marked_by_actor_ref' => $actor->identityReference !== null
                        ? (string) $actor->identityReference
                        : null,
                    'evidence_reference' => $evidence,
                    'reason' => $reason,
                    'marked_at' => now(),
                ]);

                return $renewal;
            },
            action: 'RENEWAL_EXTERNAL_MARKING',
            subject: fn (Renewal $renewal): AuditSubject => new AuditSubject('renewal_external_marking', $renewal->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: ActorRole::ADMIN,
            source: AuditSource::Panel,
            reason: $reason,
        );
    }
}
