<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The read side of the ledger: deterministic, journal-only financial reports.
 *
 * This class establishes the report precedent the Task 6 brief calls for — a
 * plain query class returning read-only, deterministic data, not a query
 * embedded in a controller, Livewire component, or Filament resource. Domain
 * logic stays here; `BulkFinancialExport` and the admin page only mount it.
 *
 * ---------------------------------------------------------------------------
 * AC6: totals come from journal references, never from mutable order state
 * ---------------------------------------------------------------------------
 * Every query in this class joins `journal_entries` to `journal_batches` and
 * to nothing else. There is no `orders` table in this repo (verified 11 Aug
 * 2026 — only `booking_drafts` exists), and the report must not start
 * reading one when it lands. The property is load-bearing, not incidental:
 * `tests/Feature/FinancialLedger/LedgerReportTest` plants a divergent amount
 * in a non-journal financial table and asserts the report total is unchanged.
 *
 * ---------------------------------------------------------------------------
 * AC12: period, source, generation time, reproducible from the ledger
 * ---------------------------------------------------------------------------
 * `summary()` returns a `LedgerReportResult` carrying `period`, `source`
 * (`LedgerReport::SOURCE`, a single constant value — the report declares
 * itself journal-derived and cannot be configured to read anything else), and
 * `generated_at`. Ordering is explicit (`ORDER BY account_code`), amounts are
 * integers in minor units (never floats), and the same ledger state for the
 * same period always produces the same rows — so the same input always
 * produces the same exported CSV.
 *
 * Reversing batches are ordinary `posted` batches linked via
 * `reverses_batch_id` (Wave 1b ruling 1: derived, never a stored status), so
 * this report needs no reversal-aware filtering: a reversal nets against the
 * original because both are present in the period they occurred in. Nothing
 * here is ever mutated — this class performs reads only.
 */
final class LedgerReport
{
    /**
     * The single source declaration every report in this module carries
     * (AC12). Kept a constant rather than a config value so a report can
     * never be configured into silently reading a non-journal source of
     * truth.
     */
    public const string SOURCE = 'journal';

    private const string PERIOD_PATTERN = '/\A\d{4}-(0[1-9]|1[0-2])\z/D';

    /**
     * Per-account debit/credit totals for one `YYYY-MM` period, optionally
     * scoped to a single `badan usaha`.
     *
     * @return LedgerReportResult Rows sorted by `account_code` ascending;
     *                            an empty `rows` list for a period with no
     *                            journal activity is a valid, honest result.
     *
     * @throws InvalidLedgerReportException on a malformed period.
     */
    public function summary(string $period, ?string $entityRef = null): LedgerReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        [$start, $endExclusive] = $this->periodBounds($period);

        $rows = DB::table('journal_entries')
            ->selectRaw(
                'journal_entries.account_code AS account_code, '
                ."COALESCE(SUM(CASE WHEN journal_entries.direction = 'DR' THEN journal_entries.amount_minor ELSE 0 END), 0) AS debit_total, "
                ."COALESCE(SUM(CASE WHEN journal_entries.direction = 'CR' THEN journal_entries.amount_minor ELSE 0 END), 0) AS credit_total"
            )
            ->join('journal_batches', 'journal_batches.id', '=', 'journal_entries.batch_id')
            ->where('journal_batches.occurred_at', '>=', $start)
            ->where('journal_batches.occurred_at', '<', $endExclusive)
            ->when($entityRef !== null, static fn ($query) => $query->where('journal_batches.entity_ref', $entityRef))
            ->groupBy('journal_entries.account_code')
            ->orderBy('journal_entries.account_code')
            ->get()
            ->map(static fn ($row): array => [
                'account_code' => (string) $row->account_code,
                'debit_total' => (int) $row->debit_total,
                'credit_total' => (int) $row->credit_total,
                'net' => (int) $row->debit_total - (int) $row->credit_total,
            ])
            ->all();

        return new LedgerReportResult(
            kind: LedgerReportKind::SUMMARY,
            period: $period,
            entityRef: $entityRef,
            source: self::SOURCE,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
        );
    }

    /**
     * Validates a report period without touching the database, so a caller
     * (e.g. `BulkFinancialExport`) can reject malformed input before the
     * re-authentication gate or any query runs.
     *
     * @throws InvalidLedgerReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (preg_match(self::PERIOD_PATTERN, $period) !== 1) {
            throw InvalidLedgerReportException::forMalformedPeriod($period);
        }
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable} `[start, endExclusive]`
     *                                                 covering the whole period month.
     */
    private function periodBounds(string $period): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $period, 'Asia/Jakarta')->startOfMonth();

        return [$start, $start->addMonth()];
    }
}
