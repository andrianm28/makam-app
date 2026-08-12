<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
 * The denial tests assert `QueryException`, not a specific SQLSTATE — SQLite
 * (the default local/CI-independent connection, `phpunit.xml`) and
 * PostgreSQL 18 (production,
 * `docs/superpowers/plans/2026-08-11-platform-identity-seam.md` Task 6's
 * precedent) report a unique-constraint violation through the same Laravel
 * exception type but different driver codes. The PostgreSQL-specific
 * `SQLSTATE 23505` claim is verified separately against a real PostgreSQL 18
 * container, per this task's report, not asserted here.
 *
 * ---------------------------------------------------------------------------
 * Why a THIRD, permissive test exists (fix round 1, F2)
 * ---------------------------------------------------------------------------
 * The two denial tests above would both still pass if
 * `renewals_grave_period_unique` were narrowed to `unique('grave_record_id')`
 * alone — every insert in both tests reuses the same `target_due_period`, so
 * neither test can tell "the pair is unique" apart from "the grave is unique
 * forever". `test_the_same_grave_may_have_a_renewal_for_a_different_period()`
 * is the assertion that pins the composite key down: it is the exact
 * scenario `2026_08_12_100000_create_renewals_table.php`'s own doc block
 * warns a narrower index would wrongly forbid ("a grave legitimately accrues
 * a new renewal every period"). This task's report records the mutation
 * check that confirms this test — not the two denial tests — is what
 * detects that specific over-broad narrowing.
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

    /**
     * The permissive counterpart to the two denial tests above — see this
     * class's own doc block (fix round 1, F2) for why it is required to pin
     * the constraint to the composite pair rather than `grave_record_id`
     * alone. Both inserts here MUST succeed.
     */
    public function test_the_same_grave_may_have_a_renewal_for_a_different_period(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $first = Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0005',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $second = Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2028-03-01',
            'reference' => 'PPJ-0006',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertTrue($second->wasRecentlyCreated);
        $this->assertSame(2, Renewal::query()->where('grave_record_id', $grave->id)->count());
    }

    /**
     * Fix round 1, F3 — `renewal_external_markings_renewal_id_unique`. Not
     * the AC11 grave-period guard; a separate, narrower rule so
     * `Renewal::externalMarking()`'s `HasOne` cardinality is real rather
     * than an unenforced assumption. See
     * `2026_08_12_100020_create_renewal_external_markings_table.php`'s own
     * doc block for why the two constraints must not be conflated.
     */
    public function test_a_renewal_cannot_have_two_external_markings(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

        $renewal = Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0007',
            'status' => RenewalStatus::DIBAYAR,
            'source' => RenewalSource::EXTERNAL,
        ]);

        RenewalExternalMarking::create([
            'renewal_id' => $renewal->id,
            'marked_by_actor_ref' => 'actor-1',
            'evidence_reference' => 'BUKTI-001',
            'reason' => 'Dibayar langsung di kantor TPU',
            'marked_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        RenewalExternalMarking::create([
            'renewal_id' => $renewal->id,
            'marked_by_actor_ref' => 'actor-2',
            'evidence_reference' => 'BUKTI-002',
            'reason' => 'Percobaan penandaan kedua untuk renewal yang sama',
            'marked_at' => now(),
        ]);
    }

    /**
     * Fix round 1, F1 — the non-negative CHECK constraints on
     * `renewal_quotes.amount_minor` and `late_fine_minor`. `unsignedBigInteger`
     * maps to a plain `bigint` on PostgreSQL, so "unsigned" is enforced by
     * neither engine; the CHECK added in
     * `2026_08_12_100010_create_renewal_quotes_table.php` is what actually
     * rejects a negative figure. Mirrors `JournalSchemaTest::
     * test_amount_minor_cannot_be_negative` exactly: the authoritative run is
     * against PostgreSQL 18 (CI/production), and SQLite — which has no
     * `ALTER TABLE ADD CONSTRAINT` path in the migration — skips rather than
     * passing vacuously.
     */
    public function test_quote_amount_minor_cannot_be_negative(): void
    {
        $this->assertQuoteMoneyColumnsNonNegative(['amount_minor' => -1]);
    }

    /**
     * Fix round 1, F1 — `late_fine_minor` must reject a negative figure the
     * same way `amount_minor` does. Its CHECK differs from the amount one
     * (`IS NULL OR >= 0`) because the column is nullable — AC7's "no invented
     * fine" means a null fine is legitimate.
     */
    public function test_quote_late_fine_minor_cannot_be_negative(): void
    {
        $this->assertQuoteMoneyColumnsNonNegative([
            'amount_minor' => 1_000_000,
            'late_fine_minor' => -1,
        ]);
    }

    /**
     * @param  array{amount_minor: int, late_fine_minor?: int}  $overrides
     */
    private function assertQuoteMoneyColumnsNonNegative(array $overrides): void
    {
        if (! $this->isPostgres()) {
            // SQLite has no ALTER TABLE ADD CONSTRAINT path in the
            // migration. The production PostgreSQL CHECK is covered on the
            // authoritative CI/production connection — the same precedent
            // JournalSchemaTest::test_amount_minor_cannot_be_negative sets.
            $this->markTestSkipped(
                'The renewal_quotes non-negative CHECK constraints are PostgreSQL-only; run this test with DB_CONNECTION=pgsql.'
            );
        }

        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $renewal = Renewal::create([
            'grave_record_id' => $grave->id,
            'target_due_period' => '2027-03-01',
            'reference' => 'PPJ-0008',
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $attributes = array_merge([
            'renewal_id' => $renewal->id,
            'currency' => 'IDR',
            'tariff_source' => 'tarif-2026',
            'tariff_effective_at' => now(),
        ], $overrides);

        $this->assertConstraintViolation(function () use ($attributes): void {
            RenewalQuote::create($attributes);
        });
    }

    private function assertConstraintViolation(Closure $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Expected a database constraint violation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function isPostgres(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}
