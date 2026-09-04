<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\Actions\RevokeCertificate;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Models\Order;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

/**
 * Certificates only — agreement seeding is deliberately out of scope for
 * this plan. `AgreementType` has exactly one case, `PreNeedAgreement`,
 * whose eligibility is a SETTLED `PreNeedCase` reachable only through a
 * distinct 7-8-action pre-need lifecycle
 * (`RegisterPreNeedInterest -> ... -> ActivatePreNeed`) that this plan's
 * booking-order generator (`BookingOrderExampleData`, which only ever
 * produces `BookingServiceType::NEW_GRAVE` orders) never touches. Rather
 * than hand-roll an unverified chain, this generator seeds certificates
 * only; a narrower follow-up plan can add pre-need agreement seeding
 * later if a demo specifically needs that journey (Task 12 records this
 * limitation explicitly).
 */
final class CertificateExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    /**
     * @param  Order  $dibayarOrder  MUST be at exactly OrderStatus::DIBAYAR —
     *                               CertificateEligibilityPolicy's OrderSettlement
     *                               rule requires it, confirmed by reading the
     *                               policy class directly.
     * @return list<Certificate>
     */
    public static function seed(string $batchId, Order $dibayarOrder): array
    {
        $issued = (new IssueCertificate)(
            CertificateType::OrderSettlement,
            $dibayarOrder,
            self::ACTOR_REF,
            ActorRole::ADMIN,
            documentId: null,
        );
        TaggedAsDemoData::tag($issued, $batchId);

        $revoked = (new IssueCertificate)(
            CertificateType::OrderSettlement,
            $dibayarOrder,
            self::ACTOR_REF,
            ActorRole::ADMIN,
            documentId: null,
        );
        TaggedAsDemoData::tag($revoked, $batchId);
        (new RevokeCertificate)($revoked, self::ACTOR_REF, ActorRole::ADMIN, 'Sertifikat demo diganti dengan versi terbaru.');

        return [$issued->fresh(), $revoked->fresh()];
    }
}
