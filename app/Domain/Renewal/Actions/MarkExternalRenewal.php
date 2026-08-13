<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The AC10 privileged write path — marks a renewal as paid outside the
 * platform ("admin/operator SHALL be able to mark a renewal as paid
 * externally, with evidence").
 *
 * Ruling B (explicit human sign-off, 12 Aug 2026): **admin only.** `operator`
 * is explicitly denied. `RenewalMarkingPolicy` enforces an authenticated
 * actor, the role, AND a privileged cemetery scope grant, and returns the role
 * that matched so the audit record names the real authority rather than a
 * hardcoded constant.
 *
 * ---------------------------------------------------------------------------
 * The renewal is recorded as SETTLED, because that is what was attested
 * ---------------------------------------------------------------------------
 * An admin marking a renewal "paid externally, with evidence" is certifying
 * that the money already changed hands at the cemetery office. The row is
 * therefore written with `RenewalStatus::DIBAYAR` and a `settled_at` stamp. An
 * earlier revision wrote `MENUNGGU_PEMBAYARAN` ("awaiting payment"), which
 * meant the confirmation screen told a family that had already paid in person
 * to go and pay again — and left an admin-certified renewal indistinguishable
 * from an unpaid one in every downstream read.
 *
 * ---------------------------------------------------------------------------
 * Two rows in one transaction, plus the AC11 shared uniqueness domain
 * ---------------------------------------------------------------------------
 * `Audit::wrap()` commits or rolls back the `renewals` row, the
 * `renewal_external_markings` row, and the audit event together.
 *
 * The `renewals` row carries `source = RenewalSource::EXTERNAL` and so
 * participates in the same `(grave_record_id, target_due_period)` unique index
 * as an online renewal — that shared domain IS AC11. A collision therefore has
 * to surface as the same typed failure the online writer raises, which is why
 * the `QueryException` is translated here exactly as `Actions\OpenRenewal`
 * translates it. Left untranslated it escaped as a raw database error, which
 * both leaked the constraint name and gave the admin surface no failure it
 * could handle.
 *
 * The `reason` is passed to `Audit::record()` through `Audit::wrap()`, which
 * enforces the `RENEWAL_EXTERNAL_MARKING` sensitive-action check
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
     * @throws DuplicateRenewalPeriodException when a renewal already claims
     *                                         this grave and period, from
     *                                         either source.
     */
    public function __invoke(
        GraveRecord $grave,
        string $targetDuePeriod,
        string $evidence,
        string $reason,
    ): void {
        $actor = $this->actors->resolve();
        $authorizingRole = $this->policy->allows($actor, $grave);

        try {
            Audit::wrap(
                mutation: function () use ($actor, $grave, $targetDuePeriod, $evidence, $reason): Renewal {
                    $renewal = Renewal::create([
                        'grave_record_id' => $grave->id,
                        'target_due_period' => $targetDuePeriod,
                        'reference' => 'EXT-'.Str::upper(Str::random(8)),
                        'status' => RenewalStatus::DIBAYAR,
                        'source' => RenewalSource::EXTERNAL,
                        'settled_at' => now(),
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
                actorRole: $authorizingRole,
                source: AuditSource::Panel,
                reason: $reason,
            );
        } catch (QueryException $e) {
            if ($e->getCode() === '23000' || $e->getCode() === '23505') {
                throw DuplicateRenewalPeriodException::forGravePeriod(
                    $grave->id,
                    $targetDuePeriod
                );
            }

            throw $e;
        }
    }
}
