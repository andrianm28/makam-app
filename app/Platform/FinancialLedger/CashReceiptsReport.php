<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "receipts" report AC7 (`.kiro/specs/admin-operations/requirements.md`)
 * asks for: money the business actually received in a period, journal-derived
 * — never inferred from a mutable order/payment status row, per this spec's
 * own §Reporting: "Financial totals must reconcile to shared journal
 * references, not derive only from mutable order status."
 *
 * ---------------------------------------------------------------------------
 * Why a receipt is "the DR leg on the cash/bank account", not a new table
 * ---------------------------------------------------------------------------
 * There is no `receipts` table in this repository, and inventing one would
 * duplicate money already recorded in `journal_entries` — exactly the
 * "financial totals must reconcile to shared journal references" rule this
 * class exists to satisfy. A receipt is definitionally the moment cash/bank
 * (`ChartOfAccounts::CASH_BANK_ACCOUNT`, code `7000`) is debited: `Journal`
 * posts a balanced batch for every settled payment, manual verification, and
 * renewal, each crediting a revenue/AR account and debiting `7000` for the
 * amount received (see `FinanceReportPanelTest::seedCurrentMonthLedger()` for
 * the shape). Filtering `journal_entries` to that one leg, joined to its
 * batch, is therefore the report — not an approximation of one.
 *
 * Deliberately a LIST of receipts (one row per batch), not an account-code
 * summary like `LedgerReport::summary()`: `LedgerReport` answers "what does
 * each account total", which is the right shape for a trial-balance-style
 * view; a "receipts" report answers "what money came in, and from what", so
 * the useful unit is one row per batch, not per account.
 *
 * Same scoping discipline as `LedgerReport`: `$entityRef` accepts a single
 * reference, the set an authorized `Contracts\LedgerReadAuthorizer` caller
 * passes, or `null` for an unscoped read (available because this is a plain
 * query class with no authorization responsibility of its own — see that
 * class's own doc block for why). Every mounted caller in this module passes
 * a scope.
 */
final class CashReceiptsReport
{
    /**
     * @param  string|list<string>|null  $entityRef
     *
     * @throws InvalidLedgerReportException on a malformed period or an empty
     *                                      entity-reference list.
     */
    public function summary(string $period, string|array|null $entityRef = null): CashReceiptsReportResult
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

        [$start, $endExclusive] = LedgerPeriod::boundsFor($period);

        $rows = DB::table('journal_entries')
            ->select([
                'journal_batches.business_key AS business_key',
                'journal_batches.source_type AS source_type',
                'journal_batches.source_id AS source_id',
                'journal_batches.entity_ref AS entity_ref',
                'journal_batches.occurred_at AS occurred_at',
                'journal_entries.amount_minor AS amount_minor',
            ])
            ->join('journal_batches', 'journal_batches.id', '=', 'journal_entries.batch_id')
            ->where('journal_entries.account_code', ChartOfAccounts::CASH_BANK_ACCOUNT['code'])
            ->where('journal_entries.direction', 'DR')
            ->where('journal_batches.occurred_at', '>=', $start)
            ->where('journal_batches.occurred_at', '<', $endExclusive)
            ->when($entityRefs !== null, static fn ($query) => $query->whereIn('journal_batches.entity_ref', $entityRefs))
            ->orderBy('journal_batches.occurred_at')
            ->get()
            ->map(static fn ($row): array => [
                'business_key' => (string) $row->business_key,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'entity_ref' => (string) $row->entity_ref,
                'occurred_at' => (string) $row->occurred_at,
                'amount_minor' => (int) $row->amount_minor,
            ])
            ->all();

        $rows = $this->sortRowsDeterministically($rows);

        return new CashReceiptsReportResult(
            period: $period,
            entityRef: $entityRef,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
            totalMinor: array_sum(array_column($rows, 'amount_minor')),
        );
    }

    /**
     * @throws InvalidLedgerReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (! LedgerPeriod::matches($period)) {
            throw InvalidLedgerReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order, not the SQL `ORDER BY` — the same reasoning
     * `LedgerReport::sortRowsByAccountCode()` argues in full for account
     * codes: the server's collation is locale-aware on PostgreSQL and
     * byte-wise on SQLite, and this report's rows feed a byte-exact CSV.
     * `occurred_at` is an ISO-8601-shaped string here (cast on the way out of
     * `DB::table()`, not through an Eloquent date cast), so a byte-wise
     * `strcmp` on it already orders chronologically; `business_key` is the
     * tie-break for two batches sharing a timestamp.
     *
     * @param  list<array{business_key: string, source_type: string, source_id: string, entity_ref: string, occurred_at: string, amount_minor: int}>  $rows
     * @return list<array{business_key: string, source_type: string, source_id: string, entity_ref: string, occurred_at: string, amount_minor: int}>
     */
    private function sortRowsDeterministically(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['occurred_at'], $b['occurred_at'])
                ?: strcmp($a['business_key'], $b['business_key']),
        );

        return $rows;
    }
}
