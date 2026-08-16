<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate\Actions;

use App\Domain\AgreementCertificate\AgreementCertificateAuditActions;
use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\AgreementType;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Task 1 — the only writer that opens a new `agreements` version row.
 * Creates version 1 in the `draft` state for a subject (order or, from
 * Lane 2, pre-need case), audited `AGREEMENT_CREATED`.
 *
 * No outbox event is emitted: the catalog has no agreement-creation
 * event, and `AGENTS.md`/plan discipline forbids inventing one — the
 * first catalogued agreement event is `agreement.accepted.v1`, emitted
 * by `AcceptAgreement`.
 *
 * The AC4 display fields are accepted as a single `$displayFields`
 * array but only the plan's six approved keys are ever written — a
 * caller-supplied key outside the list is dropped, never mass-assigned
 * onto the model.
 *
 * Reference collisions (a random `'AGR-'.Str::upper(Str::random(8))`
 * repeat) surface as the raw `QueryException` from
 * `agreements_reference_unique`: the plan's duplicate-classifier is
 * specified for CERTIFICATE issuance only, and unlike the certificate
 * INSERT this one carries no caller-supplied free text, so there is no
 * log-content hazard in letting the database backstop speak for itself.
 */
final readonly class CreateAgreement
{
    /**
     * The AC4 display fields — the only keys from `$displayFields` that
     * may reach the row.
     *
     * @var list<string>
     */
    private const array DISPLAY_FIELDS = [
        'price_guarantee',
        'cancellation_refund',
        'transferability',
        'term',
        'included_services',
        'responsible_entity',
    ];

    /**
     * @param  array<string, string>  $displayFields  the AC4 display
     *                                                values; only the six
     *                                                approved keys are written
     */
    public function __invoke(
        AgreementType $type,
        Model $subject,
        int|string $actorReference,
        string $actorRole,
        array $displayFields = [],
        AuditSource $auditSource = AuditSource::Panel,
    ): Agreement {
        return Audit::wrap(
            mutation: function () use ($type, $subject, $displayFields): Agreement {
                return Agreement::query()->create([
                    'reference' => 'AGR-'.Str::upper(Str::random(8)),
                    'type' => $type->value,
                    'version_number' => 1,
                    'status' => AgreementStatus::Draft->value,
                    'subject_type' => $subject->getMorphClass(),
                    'subject_id' => (string) $subject->getKey(),
                    ...array_intersect_key($displayFields, array_flip(self::DISPLAY_FIELDS)),
                ]);
            },
            action: AgreementCertificateAuditActions::AGREEMENT_CREATED,
            subject: fn (Agreement $agreement): AuditSubject => new AuditSubject(
                'agreement',
                $agreement->getKey(),
                $agreement->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
