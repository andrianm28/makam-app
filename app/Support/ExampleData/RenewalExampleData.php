<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

/**
 * Three renewals spanning the states this subsystem demonstrates —
 * `MENUNGGU_PEMBAYARAN` (opened, unpaid), `DIBAYAR` (settled off-platform),
 * `KEDALUWARSA` (window closed without payment) — each opened through the
 * real `OpenRenewal` write path, never a direct model write, then carried
 * to its terminal state through the matching Action.
 *
 * `$graveRecords` is supplied by the caller rather than resolved here: a
 * qualifying grave record needs a non-null `due_date` and a fully-priced
 * parent cemetery (`QuoteRenewal`'s own requirement), and finding or
 * creating three such records is the orchestration command's job, not
 * this generator's — see Task 10.
 */
final class RenewalExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @param  list<GraveRecord>  $graveRecords  three distinct, already-qualifying grave records
     * @return list<Renewal>
     */
    public static function seed(string $batchId, array $graveRecords): array
    {
        [$pending, $paid, $expired] = $graveRecords;

        $renewals = [];

        $renewal = (new OpenRenewal)($pending);
        TaggedAsDemoData::tag($renewal, $batchId);
        $renewals[] = $renewal;

        $renewal = (new OpenRenewal)($paid);
        TaggedAsDemoData::tag($renewal, $batchId);
        (new MarkRenewalPaidExternally)(
            $renewal,
            evidence: 'DEMO-BUKTI-TRANSFER-001',
            reason: 'Pembayaran perpanjangan demo diverifikasi manual.',
            actorRef: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
        );
        $renewals[] = $renewal->fresh();

        $renewal = (new OpenRenewal)($expired);
        TaggedAsDemoData::tag($renewal, $batchId);
        (new ExpireRenewal)($renewal, self::ACTOR_REF, self::ACTOR_ROLE, 'Batas waktu pembayaran demo terlewati.');
        $renewals[] = $renewal->fresh();

        return $renewals;
    }
}
