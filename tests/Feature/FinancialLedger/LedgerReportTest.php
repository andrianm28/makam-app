<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\FinancialLedger\LedgerReportKind;
use App\Platform\FinancialLedger\VendorPayableState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AC12 (`LedgerReport` declares `period`, `source`, `generated_at` and is
 * reproducible from the ledger) and AC6 (totals come from journal references,
 * never from mutable order state). The report is deliberately journal-only —
 * this test plants a divergent amount in a non-journal financial table
 * (`vendor_payables`) and asserts the report total is unchanged, so AC6 is
 * pinned by evidence rather than by doc-block intent.
 */
final class LedgerReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_summary_declares_period_source_and_generation_time(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-report-1',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-1',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 100_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 100_000],
            ],
            correlationId: 'trace-report-1',
            occurredAt: '2026-08-10T09:00:00+07:00',
        );

        $before = CarbonImmutable::now();
        $result = app(LedgerReport::class)->summary('2026-08');
        $after = CarbonImmutable::now();

        $this->assertSame(LedgerReportKind::SUMMARY, $result->kind);
        $this->assertSame('2026-08', $result->period);
        $this->assertSame('journal', $result->source);
        $this->assertTrue($result->generatedAt->between($before, $after));
        $this->assertSame(
            ['4000', '7000'],
            array_column($result->rows, 'account_code'),
        );
        $this->assertSame(0, $result->rows[0]['debit_total']);
        $this->assertSame(100_000, $result->rows[0]['credit_total']);
        $this->assertSame(-100_000, $result->rows[0]['net']);
        $this->assertSame(100_000, $result->rows[1]['debit_total']);
    }

    /**
     * AC6's load-bearing property: totals come from `journal_entries` joined
     * only to `journal_batches`. A divergent amount sitting in a non-journal
     * financial table must not move the report.
     */
    public function test_totals_ignore_non_journal_financial_tables(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-report-2',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-2',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 200_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 200_000],
            ],
            correlationId: 'trace-report-2',
            occurredAt: '2026-08-12T09:00:00+07:00',
        );

        DB::table('vendor_payables')->insert([
            'id' => (string) Str::uuid(),
            'vendor_id' => 'vendor-1',
            'entity_ref' => 'badan-usaha-1',
            'source_type' => 'payment',
            'source_id' => 'provider-event-report-2',
            'amount_minor' => 999_999,
            // `VendorPayableState::KNOWN_STATES` is the closed list the
            // PostgreSQL `vendor_payables_state_check` constraint is built
            // from, and `payable` is the only member consistent with a
            // non-null `eligible_at` and a null `paid_at` (see that table's
            // `eligible_at`/`paid_at` CHECKs). Written as the constant, not a
            // literal, so the fixture cannot drift from the constraint again.
            'state' => VendorPayableState::PAYABLE,
            'eligible_at' => '2026-08-12 09:00:00',
            'paid_at' => null,
            'correlation_id' => 'trace-report-2',
        ]);

        $result = app(LedgerReport::class)->summary('2026-08');

        // The divergence is real: the planted amount sits in the non-journal
        // table, untouched. AC6's property is that the report never reads it.
        $this->assertSame(999_999, (int) DB::table('vendor_payables')->where('vendor_id', 'vendor-1')->value('amount_minor'));
        $this->assertSame(200_000, array_sum(array_column($result->rows, 'debit_total')));
        $this->assertSame(200_000, array_sum(array_column($result->rows, 'credit_total')));
    }

    /**
     * AC12's deterministic row order — the property that makes "same ledger +
     * same period => same exported bytes" true.
     *
     * ---------------------------------------------------------------------
     * The fixture is four codes INCLUDING `2000`, and that is not arbitrary
     * ---------------------------------------------------------------------
     * The three-code fixture this test used to carry (`4000, 5000, 7000`) came
     * back already sorted from PostgreSQL's HashAggregate BY LUCK, so it could
     * not detect the ordering being removed at all. A reviewer measured the
     * real hash-bucket output on `postgres:18`: `2000, 4000, 5000, 7000`
     * grouped WITHOUT any ordering comes back as `4000, 2000, 5000, 7000` —
     * scrambled. `2000` is in this fixture because it lands in a different
     * bucket, not for accounting reasons.
     *
     * ---------------------------------------------------------------------
     * What this test can and cannot detect — stated, not implied
     * ---------------------------------------------------------------------
     * Ordering is now owned by `LedgerReport::sortRowsByAccountCode()`, a PHP
     * `strcmp` sort. The SQL `ORDER BY` is retained as an optimisation.
     *
     *  - Deleting BOTH: this test goes RED (verified by executing exactly that
     *    mutation on real PostgreSQL 18).
     *  - Deleting the PHP sort alone: this test stays GREEN, because the SQL
     *    clause still returns these four codes in this order. The test that
     *    covers that mutation is
     *    `test_row_order_is_byte_wise_and_not_the_servers_collation()` below.
     *  - Deleting the SQL `ORDER BY` alone: NO test detects it, and none can —
     *    the PHP sort produces identical output either way. This is precisely
     *    the trap finding N5 sprang in `FinanceLedgerReadAuthorizer`, recorded
     *    here in writing rather than left for the next reader to rediscover.
     *    The consequence is bounded: the clause is not the guarantee, so losing
     *    it costs efficiency, not correctness.
     */
    public function test_rows_are_sorted_deterministically_by_account_code(): void
    {
        $this->journal()->post(
            businessKey: 'payment:provider-event-report-3',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-3',
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => 30_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 30_000],
            ],
            correlationId: 'trace-report-3',
            occurredAt: '2026-08-13T09:00:00+07:00',
        );

        $this->journal()->post(
            businessKey: 'payment:provider-event-report-4',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-4',
            entries: [
                ['account' => '5000', 'direction' => 'DR', 'amountMinor' => 10_000],
                ['account' => '7000', 'direction' => 'CR', 'amountMinor' => 10_000],
            ],
            correlationId: 'trace-report-4',
            occurredAt: '2026-08-14T09:00:00+07:00',
        );

        $this->journal()->post(
            businessKey: 'payment:provider-event-report-4b',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-report-4b',
            entries: [
                ['account' => '2000', 'direction' => 'DR', 'amountMinor' => 7_000],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => 7_000],
            ],
            correlationId: 'trace-report-4b',
            occurredAt: '2026-08-14T10:00:00+07:00',
        );

        $result = app(LedgerReport::class)->summary('2026-08');

        $this->assertSame(['2000', '4000', '5000', '7000'], array_column($result->rows, 'account_code'));
        $this->assertSame(7_000, $result->rows[0]['debit_total']);
        $this->assertSame(37_000, $result->rows[1]['credit_total']);
        $this->assertSame(10_000, $result->rows[2]['debit_total']);
        $this->assertSame(20_000, $result->rows[3]['net']);
    }

    /**
     * The one test that goes red if the PHP sort is removed.
     *
     * Row order must be BYTE-WISE, not whatever the database server's locale
     * says. Those differ: measured on this project's `postgres:18` image
     * (`datcollate = en_US.utf8`), `ORDER BY code` returns `40001` BEFORE
     * `4000-A`, while byte order puts `4000-A` first (`-` is 0x2D, `1` is
     * 0x31). SQLite sorts byte-wise, so the two drivers disagree on the same
     * data — and so would two PostgreSQL deployments that differ only in
     * `lc_collate`.
     *
     * Why that matters rather than being trivia: `BulkFinancialExport` renders
     * these rows into a byte-exact CSV, and AC12 requires the same ledger state
     * to reproduce the same artifact. Leaving the order to the server means the
     * artifact depends on a locale nobody in this repository chose.
     *
     * The account codes are synthetic. Every code in `ChartOfAccounts` today is
     * four digits, and all collations agree on equal-length digit strings —
     * which is exactly why this defect would stay invisible until the day
     * finance extends the chart, since `coa_accounts.code` is `varchar(16)`
     * with no format CHECK.
     *
     * MUTATION RESISTANCE: with `sortRowsByAccountCode()` removed, PostgreSQL
     * returns `40001` before `4000-A` and this test fails on the very first
     * assertion. Verified by executing that mutation, not by reasoning about
     * it. It is PostgreSQL-only because SQLite's byte-wise ordering agrees with
     * `strcmp` and therefore cannot express the divergence.
     */
    public function test_row_order_is_byte_wise_and_not_the_servers_collation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Only PostgreSQL has a locale-aware collation that can disagree with byte order; '
                .'SQLite sorts byte-wise and cannot express this divergence.'
            );
        }

        // Two extra chart accounts whose codes order differently under
        // `en_US.utf8` than they do byte-wise.
        foreach (['4000-A', '40001'] as $code) {
            DB::table('coa_accounts')->insert([
                'code' => $code,
                'name' => 'Test — collation probe '.$code,
                'normal_balance' => 'DR',
            ]);
        }

        $this->journal()->post(
            businessKey: 'payment:provider-event-collation',
            entityRef: 'badan-usaha-1',
            sourceType: 'payment',
            sourceId: 'provider-event-collation',
            entries: [
                ['account' => '4000-A', 'direction' => 'DR', 'amountMinor' => 11_000],
                ['account' => '40001', 'direction' => 'CR', 'amountMinor' => 11_000],
            ],
            correlationId: 'trace-report-collation',
            occurredAt: '2026-08-16T09:00:00+07:00',
        );

        $result = app(LedgerReport::class)->summary('2026-08');

        $this->assertSame(['4000-A', '40001'], array_column($result->rows, 'account_code'));

        // And the server really would have disagreed — asserted rather than
        // assumed, so this test cannot quietly become vacuous if a future image
        // ships a byte-wise default collation. If this assertion ever fails,
        // the test above stopped proving anything and must be revisited.
        $this->assertSame(
            ['40001', '4000-A'],
            DB::table('coa_accounts')
                ->whereIn('code', ['4000-A', '40001'])
                ->orderBy('code')
                ->pluck('code')
                ->all(),
            'The server collation no longer disagrees with byte order, so this test no longer '
            .'distinguishes the PHP sort from the SQL ORDER BY.',
        );
    }

    public function test_period_and_entity_filters_exclude_out_of_scope_rows(): void
    {
        foreach ([
            ['occurredAt' => '2026-07-31T23:59:00+07:00', 'entity' => 'badan-usaha-1', 'amount' => 111_111],
            ['occurredAt' => '2026-08-01T00:00:00+07:00', 'entity' => 'badan-usaha-1', 'amount' => 222_222],
            ['occurredAt' => '2026-08-15T09:00:00+07:00', 'entity' => 'badan-usaha-2', 'amount' => 333_333],
            ['occurredAt' => '2026-09-01T00:00:00+07:00', 'entity' => 'badan-usaha-1', 'amount' => 444_444],
        ] as $i => $spec) {
            $this->journal()->post(
                businessKey: "payment:provider-event-report-{$i}",
                entityRef: $spec['entity'],
                sourceType: 'payment',
                sourceId: "provider-event-report-{$i}",
                entries: [
                    ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $spec['amount']],
                    ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $spec['amount']],
                ],
                correlationId: "trace-report-{$i}",
                occurredAt: $spec['occurredAt'],
            );
        }

        $all = app(LedgerReport::class)->summary('2026-08');
        $this->assertSame(555_555, array_sum(array_column($all->rows, 'debit_total')));

        $entity = app(LedgerReport::class)->summary('2026-08', 'badan-usaha-1');
        $this->assertSame(222_222, array_sum(array_column($entity->rows, 'debit_total')));
        $this->assertSame('badan-usaha-1', $entity->entityRef);
    }

    public function test_an_empty_period_is_a_valid_honest_result(): void
    {
        $result = app(LedgerReport::class)->summary('2026-08');

        $this->assertSame('2026-08', $result->period);
        $this->assertSame([], $result->rows);
        $this->assertSame(0, array_sum(array_column($result->rows, 'debit_total')));
    }

    public function test_a_malformed_period_is_refused_without_touching_the_database(): void
    {
        $this->expectException(InvalidLedgerReportException::class);

        app(LedgerReport::class)->summary('2026-8');
    }

    /**
     * The old name claimed a refusal while every assertion below proves an
     * acceptance. Padding is normalised, not rejected — and
     * `BulkFinancialExport::export()` now trims before it validates, so the
     * two entry points agree instead of one accepting what the other refuses.
     */
    public function test_a_padded_period_is_trimmed_and_accepted(): void
    {
        $result = app(LedgerReport::class)->summary('  2026-08  ');

        $this->assertSame('2026-08', $result->period);
        $this->assertSame([], $result->rows);
    }

    public function test_an_empty_entity_scope_is_refused_rather_than_treated_as_unscoped(): void
    {
        $this->expectException(InvalidLedgerReportException::class);

        app(LedgerReport::class)->summary('2026-08', []);
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
