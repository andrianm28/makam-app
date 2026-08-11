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
 * `generated_at`. Amounts are integers in minor units (never floats), and the
 * same ledger state for the same period always produces the same rows — so the
 * same input always produces the same exported CSV.
 *
 * Row order is owned by `sortRowsByAccountCode()`, a byte-wise PHP sort, NOT by
 * the SQL `ORDER BY` (which is retained as an optimisation). That distinction
 * is load-bearing rather than pedantic and is argued in full on that method:
 * the database sorts in the server's collation, which differs between drivers
 * and between deployments, and this report feeds a byte-exact CSV.
 *
 * The period window comes from `LedgerPeriod`, shared with `RunReconciliation`
 * so the report and the reconciliation of the same period can never disagree
 * about which batches are in it.
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

    /**
     * Per-account debit/credit totals for one `YYYY-MM` period, scoped to one
     * or more `badan usaha`.
     *
     * `$entityRef` accepts a single reference or a list of them. The list form
     * is what an authorized caller passes: `Contracts\LedgerReadAuthorizer`
     * returns the set of badan usaha the actor actually holds a grant for, and
     * that set — never a wildcard — is the filter. `null` is an UNSCOPED read
     * across every badan usaha; it remains available because this is a plain
     * query class with no authorization responsibility of its own (Filament
     * resources and Blade views must not take authorization decisions, per
     * `AGENTS.md`), but every mounted caller in this module passes a scope. An
     * empty list is refused rather than silently treated as "no filter" — that
     * degradation is precisely how an unscoped cross-entity export happens.
     *
     * @param  string|list<string>|null  $entityRef
     * @return LedgerReportResult Rows sorted by `account_code` ascending;
     *                            an empty `rows` list for a period with no
     *                            journal activity is a valid, honest result.
     *
     * @throws InvalidLedgerReportException on a malformed period or an empty
     *                                      entity-reference list.
     */
    public function summary(string $period, string|array|null $entityRef = null): LedgerReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        if (is_array($entityRef) && $entityRef === []) {
            throw InvalidLedgerReportException::forEmptyEntityScope();
        }

        $entityRefs = match (true) {
            $entityRef === null => null,
            is_array($entityRef) => array_values($entityRef),
            default => [$entityRef],
        };

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
            ->when($entityRefs !== null, static fn ($query) => $query->whereIn('journal_batches.entity_ref', $entityRefs))
            ->groupBy('journal_entries.account_code')
            // Retained deliberately, and NOT the owner of the guarantee — see
            // `sortRowsByAccountCode()` immediately below for why, and for what
            // this line is and is not doing.
            ->orderBy('journal_entries.account_code')
            ->get()
            ->map(static fn ($row): array => [
                'account_code' => (string) $row->account_code,
                'debit_total' => (int) $row->debit_total,
                'credit_total' => (int) $row->credit_total,
                'net' => (int) $row->debit_total - (int) $row->credit_total,
            ])
            ->all();

        $rows = $this->sortRowsByAccountCode($rows);

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
        if (! LedgerPeriod::matches($period)) {
            throw InvalidLedgerReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order of this report. The database does not.
     *
     * ---------------------------------------------------------------------
     * Why the SQL `ORDER BY` is not enough on its own
     * ---------------------------------------------------------------------
     * `ORDER BY account_code` IS honoured — but it sorts using the SERVER's
     * collation, which is locale-aware on PostgreSQL (`en_US.utf8` on this
     * project's containers) and byte-wise on SQLite. Those disagree the moment
     * a code contains anything but same-length digits. Measured on
     * `postgres:18`: `COLLATE "C"` orders `4000-A` before `40001`, while
     * `en_US.utf8` orders `40001` before `4000-A`. `coa_accounts.code` is
     * `varchar(16)` with no format CHECK and the chart is documented as
     * finance-extensible without a deployment, so "every code is four digits"
     * is a fact about today, not a constraint.
     *
     * That matters because `BulkFinancialExport` turns these rows into a
     * byte-exact CSV. AC12 requires the same ledger state to produce the same
     * artifact; leaving that to the server's locale means the same ledger
     * produces different bytes on two deployments that differ only in
     * `lc_collate`. `FinanceLedgerReadAuthorizer` reached the identical
     * conclusion for the grant list it digests into an audit reference (finding
     * N5); this is the same property one layer up.
     *
     * `strcmp()` — not `sort()`, and not `SORT_REGULAR`. PHP's default
     * comparison treats two NUMERIC STRINGS as numbers, so `'40001'` and
     * `'4000-A'` would be compared as `40001` against `4000` rather than
     * byte-wise. With today's uniform four-digit codes the two agree exactly,
     * which is precisely how this would have hidden.
     *
     * ---------------------------------------------------------------------
     * The `ORDER BY` is kept, and what that costs the tests
     * ---------------------------------------------------------------------
     * Keeping it means the database returns near-sorted rows and the PHP sort
     * is cheap; removing it would gain nothing. But it also means NO test can
     * detect the `ORDER BY` being deleted, because this sort produces the same
     * output either way — the same trap N5 sprang. That is stated here, and
     * again in `LedgerReportTest`, rather than left for someone to discover:
     * the SQL clause is an optimisation, this method is the guarantee.
     *
     * @param  list<array{account_code: string, debit_total: int, credit_total: int, net: int}>  $rows
     * @return list<array{account_code: string, debit_total: int, credit_total: int, net: int}>
     */
    private function sortRowsByAccountCode(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['account_code'], $b['account_code']),
        );

        return $rows;
    }

    /**
     * @return array{CarbonImmutable, CarbonImmutable} `[start, endExclusive]`
     *                                                 covering the whole period month.
     */
    private function periodBounds(string $period): array
    {
        return LedgerPeriod::boundsFor($period);
    }
}
