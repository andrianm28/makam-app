<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
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
 *
 * ---------------------------------------------------------------------------
 * The `DIBAYAR` demo row does NOT go through `MarkRenewalPaidExternally`
 * ---------------------------------------------------------------------------
 * AUTHZ-04 moved that action's authorization onto `RenewalMarkingPolicy`,
 * which requires a real authenticated actor holding `admin` AND a
 * privileged cemetery-scope grant — this console-triggered generator has
 * neither an HTTP session nor any `scope_assignments` row to grant, and
 * manufacturing a throwaway logged-in admin purely to satisfy that policy
 * would be a bigger, riskier change than this generator warrants. So this
 * settle write is inlined here instead, at the same level `OpenRenewal`
 * (create) and `ExpireRenewal` (expire, also never actor-checked) already
 * write demo rows — `self::ACTOR_REF`/`self::ACTOR_ROLE` are trusted
 * strings for demo data exactly as `ExpireRenewal`'s call two lines below
 * already trusts them, not a real authorization decision.
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
        self::settleExternallyForDemo(
            $renewal,
            evidence: 'DEMO-BUKTI-TRANSFER-001',
            reason: 'Pembayaran perpanjangan demo diverifikasi manual.',
        );
        $renewals[] = $renewal->fresh();

        $renewal = (new OpenRenewal)($expired);
        TaggedAsDemoData::tag($renewal, $batchId);
        (new ExpireRenewal)($renewal, self::ACTOR_REF, self::ACTOR_ROLE, 'Batas waktu pembayaran demo terlewati.');
        $renewals[] = $renewal->fresh();

        return $renewals;
    }

    /**
     * The same settle mutation `MarkRenewalPaidExternally` performs, minus
     * the `RenewalMarkingPolicy` check — see the class doc block for why
     * this demo generator does not (and should not) go through the real
     * actor-checked action.
     */
    private static function settleExternallyForDemo(Renewal $renewal, string $evidence, string $reason): void
    {
        Audit::wrap(
            mutation: function () use ($renewal, $evidence, $reason): void {
                $renewal->update([
                    'status' => RenewalStatus::DIBAYAR,
                    'settled_at' => now(),
                ]);

                RenewalExternalMarking::query()->create([
                    'renewal_id' => $renewal->getKey(),
                    'marked_by_actor_ref' => self::ACTOR_REF,
                    'evidence_reference' => $evidence,
                    'reason' => $reason,
                    'marked_at' => now(),
                ]);
            },
            action: 'RENEWAL_EXTERNAL_MARKING',
            subject: fn (): AuditSubject => new AuditSubject('renewal', (string) $renewal->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
            source: AuditSource::Panel,
            reason: $reason,
        );
    }
}
