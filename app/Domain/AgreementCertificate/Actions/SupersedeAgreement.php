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
use Illuminate\Support\Str;

/**
 * Task 1 — AC5's agreement half: supersede the incumbent version and
 * insert the NEXT version row (version_number + 1, back to `draft` for a
 * fresh AC2 acceptance), preserving the old row untouched as history.
 * Audited `AGREEMENT_SUPERSEDED` against the incumbent.
 *
 * No outbox event is emitted: the catalog's only agreement event is
 * `agreement.accepted.v1` — no agreement-superseded event exists, and
 * none is invented.
 *
 * The next version carries the incumbent's type and subject (the
 * agreement lineage does not change on supersession) and copies the
 * incumbent's AC4 display fields as the starting point; `$overrides`
 * may replace any of the six approved keys. The new row starts at
 * `draft` because AC2 binds acceptance to an exact version — a
 * superseded agreement's replacement must be re-accepted.
 *
 * Reference collisions on the new row surface as the raw
 * `QueryException` from `agreements_reference_unique` — same reasoning
 * as `CreateAgreement`'s doc block.
 *
 * @param  array<string, string>  $overrides  optional AC4 display-field
 *                                            replacements for the next version
 */
final readonly class SupersedeAgreement
{
    /**
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

    public function __invoke(
        Agreement $agreement,
        int|string $actorReference,
        string $actorRole,
        array $overrides = [],
        AuditSource $auditSource = AuditSource::Panel,
    ): Agreement {
        return Audit::wrap(
            mutation: function () use ($agreement, $overrides): Agreement {
                $current = Agreement::query()->lockForUpdate()->findOrFail($agreement->getKey());

                $current->supersede();

                if ($agreement !== $current) {
                    $agreement->setRawAttributes($current->getAttributes(), true);
                }

                $next = Agreement::query()->create([
                    'reference' => 'AGR-'.Str::upper(Str::random(8)),
                    'type' => AgreementType::from($current->type)->value,
                    'version_number' => $current->version_number + 1,
                    'status' => AgreementStatus::Draft->value,
                    'subject_type' => $current->subject_type,
                    'subject_id' => $current->subject_id,
                    ...array_intersect_key(
                        array_merge(
                            array_intersect_key(
                                $current->getAttributes(),
                                array_flip(self::DISPLAY_FIELDS),
                            ),
                            $overrides,
                        ),
                        array_flip(self::DISPLAY_FIELDS),
                    ),
                ]);

                return $next;
            },
            action: AgreementCertificateAuditActions::AGREEMENT_SUPERSEDED,
            subject: fn (): AuditSubject => new AuditSubject(
                'agreement',
                (string) $agreement->getKey(),
                (string) $agreement->version_number,
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
