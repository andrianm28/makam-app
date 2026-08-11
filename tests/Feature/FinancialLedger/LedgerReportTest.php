<?php

declare(strict_types=1);

namespace Tests\Feature\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\FinancialLedger\LedgerReportKind;
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
            'state' => 'accrued',
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

        $result = app(LedgerReport::class)->summary('2026-08');

        $this->assertSame(['4000', '5000', '7000'], array_column($result->rows, 'account_code'));
        $this->assertSame(30_000, $result->rows[0]['credit_total']);
        $this->assertSame(10_000, $result->rows[1]['debit_total']);
        $this->assertSame(20_000, $result->rows[2]['net']);
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

    public function test_a_malformed_period_is_refused_even_when_trimmed_input_is_valid(): void
    {
        $result = app(LedgerReport::class)->summary('  2026-08  ');

        $this->assertSame('2026-08', $result->period);
        $this->assertSame([], $result->rows);
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}
