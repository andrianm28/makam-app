<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use App\Domain\Renewal\Exceptions\InvalidRenewalReportException;
use App\Platform\FinancialLedger\LedgerPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "renewal by period" report `.kiro/specs/admin-operations/
 * requirements.md` AC7 asks for: how many renewals fell into each
 * `RenewalStatus` for a given `YYYY-MM`.
 *
 * ---------------------------------------------------------------------------
 * Filtered by `target_due_period`, not `created_at`
 * ---------------------------------------------------------------------------
 * Every other report in this batch buckets by when a row was CREATED.
 * Renewals are different: `target_due_period` is the domain's own period
 * concept — "the grave record's `due_date` AT THE MOMENT this renewal was
 * opened" (`Models\Renewal`'s own doc block) — and "renewal by period" reads
 * naturally as "renewals due in this period", the question an operations
 * admin actually has (which renewal cohort is this month's workload),
 * rather than "renewals opened in this period" (which `created_at` would
 * answer and which conflates an online renewal opened late for an earlier
 * due period with one opened on time).
 *
 * `target_due_period` is a `date` column (`immutable_date` cast on the
 * model), with no time-of-day component, so it is compared against the
 * `Y-m-d` boundary strings `LedgerPeriod::boundsFor()` produces rather than
 * against the full timestamp — comparing a `date` column to a
 * timezone-bearing timestamp string risks a driver-dependent implicit cast,
 * the same class of divergence `LedgerPeriod`'s own doc block was written to
 * close for `occurred_at`.
 *
 * Read-only query class; `LedgerPeriod` reuse and the "no business-entity
 * scoping" reasoning both follow `OrderPeriodReport`'s doc block, which
 * argues both points in full. `renewals` carries no `entity_ref` (see
 * `2026_08_12_100000_create_renewals_table.php`), and
 * `RenewalOrderResource` — the existing admin surface over this same table
 * — is gated by `MasterDataAdminAuthorizerContract` alone, with no entity
 * scope.
 */
final class RenewalReport
{
    /**
     * @throws InvalidRenewalReportException on a malformed period.
     */
    public function summary(string $period): RenewalReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        [$start, $endExclusive] = LedgerPeriod::boundsFor($period);

        $rows = DB::table('renewals')
            ->selectRaw('renewals.status AS status, COUNT(*) AS total')
            ->where('renewals.target_due_period', '>=', $start->toDateString())
            ->where('renewals.target_due_period', '<', $endExclusive->toDateString())
            ->groupBy('renewals.status')
            ->get()
            ->map(static fn ($row): array => [
                'status' => (string) $row->status,
                'total' => (int) $row->total,
            ])
            ->all();

        $rows = $this->sortRowsByStatus($rows);

        return new RenewalReportResult(
            period: $period,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
            total: array_sum(array_column($rows, 'total')),
        );
    }

    /**
     * @throws InvalidRenewalReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (! LedgerPeriod::matches($period)) {
            throw InvalidRenewalReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order — same collation reasoning as
     * `LedgerReport::sortRowsByAccountCode()`.
     *
     * @param  list<array{status: string, total: int}>  $rows
     * @return list<array{status: string, total: int}>
     */
    private function sortRowsByStatus(array $rows): array
    {
        usort($rows, static fn (array $a, array $b): int => strcmp($a['status'], $b['status']));

        return $rows;
    }
}
