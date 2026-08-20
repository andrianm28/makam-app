<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Exceptions\InvalidOrderPeriodReportException;
use App\Platform\FinancialLedger\LedgerPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "orders" report `.kiro/specs/admin-operations/requirements.md` AC7
 * asks for: how many orders fell into each `OrderStatus` in a given period.
 *
 * A plain, read-only query class over `orders.created_at` — not embedded in
 * a Filament page, matching the read-model precedent
 * `App\Platform\FinancialLedger\LedgerReport`'s own doc block establishes
 * ("a plain query class returning read-only, deterministic data, not a
 * query embedded in a controller, Livewire component, or Filament
 * resource").
 *
 * ---------------------------------------------------------------------------
 * Reusing `LedgerPeriod` from a non-ledger domain
 * ---------------------------------------------------------------------------
 * `LedgerPeriod` is the one definition this codebase settled on for "which
 * rows belong to `YYYY-MM`" (its own doc block: two independent expressions
 * of that window had already drifted once). An order report needs the exact
 * same window semantics — a calendar month, in the same canonical
 * `Asia/Jakarta` timezone this is an Indonesian business — so reusing it
 * here is the intended cross-module reuse the class documents itself as
 * existing for, not a namespace-boundary violation: `Platform\*` modules
 * are shared infrastructure every domain may depend on, the same direction
 * `App\Filament\Admin\*` already depends on `Platform\IdentityAccess`.
 *
 * ---------------------------------------------------------------------------
 * No business-entity scoping — AC10 read plainly against this table
 * ---------------------------------------------------------------------------
 * AC10 requires scoping "to the requesting admin's role and business-entity
 * permissions." `orders` carries no `entity_ref`/`badan_usaha` column (see
 * `2026_08_12_100000_create_orders_table.php` — no such column exists), so
 * there is no business-entity dimension to scope this report by. This
 * mirrors the established pattern for every existing admin resource over
 * this same table family: `BookingOrderResource`, `MarketplaceOrderResource`
 * and `RenewalOrderResource` are all gated by
 * `MasterDataAdminAuthorizerContract` alone — a four-role check with
 * explicitly "no record scope to check" (that authorizer's own doc block) —
 * because master data/order data in this codebase is platform-wide, not
 * partitioned by badan usaha at the row level. `App\Filament\Admin\Pages\
 * OrdersReport` therefore satisfies AC10's role half via the same
 * authorizer and satisfies the business-entity half vacuously, for the same
 * structural reason those three resources already do.
 */
final class OrderPeriodReport
{
    /**
     * @throws InvalidOrderPeriodReportException on a malformed period.
     */
    public function summary(string $period): OrderPeriodReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        [$start, $endExclusive] = LedgerPeriod::boundsFor($period);

        $rows = DB::table('orders')
            ->selectRaw('orders.status AS status, COUNT(*) AS total')
            ->where('orders.created_at', '>=', $start)
            ->where('orders.created_at', '<', $endExclusive)
            ->groupBy('orders.status')
            ->get()
            ->map(static fn ($row): array => [
                'status' => (string) $row->status,
                'total' => (int) $row->total,
            ])
            ->all();

        $rows = $this->sortRowsByStatus($rows);

        return new OrderPeriodReportResult(
            period: $period,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
            total: array_sum(array_column($rows, 'total')),
        );
    }

    /**
     * @throws InvalidOrderPeriodReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (! LedgerPeriod::matches($period)) {
            throw InvalidOrderPeriodReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order for the same collation reason
     * `LedgerReport::sortRowsByAccountCode()` documents in full: the
     * database's `GROUP BY` result order is not guaranteed portable across
     * drivers, and this report feeds a deterministic CSV export.
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
