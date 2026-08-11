<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The AC11 duplicate-period guard — `.kiro/specs/renewal-and-grave-registry/
 * design.md`'s "one renewal settlement per grave period" invariant, enforced
 * by `renewals_grave_period_unique` on `(grave_record_id, target_due_period)`
 * rather than by application-level check-then-insert, which two concurrent
 * requests could both pass. `docs/superpowers/plans/
 * 2026-08-12-platform-renewal-completion.md` Task 1 is the origin of this
 * file; both tests below were written and observed to fail (table missing,
 * then the index missing under a deliberate mutation check) before the
 * migration existed. See that task's report for the exact failure output.
 *
 * Both tests assert `QueryException`, not a specific SQLSTATE — SQLite (the
 * default local/CI-independent connection, `phpunit.xml`) and PostgreSQL 18
 * (production, `docs/superpowers/plans/2026-08-11-platform-identity-seam.md`
 * Task 6's precedent) report a unique-constraint violation through the same
 * Laravel exception type but different driver codes. The PostgreSQL-specific
 * `SQLSTATE 23505` claim is verified separately against a real PostgreSQL 18
 * container, per this task's report, not asserted here.
 */
final class RenewalSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_renewal_for_the_same_grave_and_period_is_rejected_by_the_database(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0001',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $this->expectException(QueryException::class);

        Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0002',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);
    }

    /**
     * The assertion that would catch a two-table design regressing the
     * invariant: an external marking and an online renewal must be unable
     * to both claim the same grave period, because `source` is the only
     * thing distinguishing them inside ONE uniqueness domain.
     */
    public function test_an_external_marking_and_an_online_renewal_cannot_both_claim_one_period(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0003',
            'status' => RenewalStatus::DIBAYAR,
            'source' => RenewalSource::EXTERNAL,
        ]);

        $this->expectException(QueryException::class);

        Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0004',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);
    }
}
