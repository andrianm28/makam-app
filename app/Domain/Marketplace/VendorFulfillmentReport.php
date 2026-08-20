<?php

declare(strict_types=1);

namespace App\Domain\Marketplace;

use App\Domain\Marketplace\Exceptions\InvalidVendorFulfillmentReportException;
use App\Domain\Marketplace\Models\Vendor;
use App\Platform\FinancialLedger\LedgerPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The "vendor performance" report `.kiro/specs/admin-operations/
 * requirements.md` AC7 asks for: per-vendor fulfilment counts for a period —
 * total orders, completed (`VendorProcessingStatus::SELESAI`), cancelled
 * (`DIBATALKAN`), and complaints (`KOMPLAIN`), plus a completion rate.
 *
 * `vendor_orders` has no rating/score column (verified against
 * `2026_08_12_110000_create_vendor_orders_table.php` and
 * `Models\VendorOrder` — neither carries one), so "performance" here is
 * fulfilment outcome counts derived from the same closed
 * `VendorProcessingStatus` list `Filament\Vendor\...\VendorOrderStatus...`
 * pages already transition through — not an invented rating this batch has
 * no data to back.
 *
 * Read-only query class, matching `LedgerReport`'s and `OrderPeriodReport`'s
 * "plain query class, not embedded in a Filament page" precedent.
 * `LedgerPeriod` reuse follows `OrderPeriodReport`'s own doc block reasoning
 * for why a non-ledger domain reusing the one canonical `YYYY-MM` window
 * definition is the intended cross-module reuse, not a boundary violation.
 *
 * ---------------------------------------------------------------------------
 * No business-entity scoping — same structural reason as `OrderPeriodReport`
 * ---------------------------------------------------------------------------
 * `vendor_orders` carries no `entity_ref` (only the customer-facing
 * `marketplace_orders` row it may optionally link to does, frozen there for
 * ledger-posting purposes — see that migration's doc block). `Filament\Admin\
 * Resources\Vendors\VendorResource` itself is gated by
 * `MasterDataAdminAuthorizerContract` alone with no entity scope, so a
 * vendor-performance report scoped the same way as the vendor resource it
 * reports on is the consistent choice, not a gap introduced here.
 */
final class VendorFulfillmentReport
{
    /**
     * @throws InvalidVendorFulfillmentReportException on a malformed period.
     */
    public function summary(string $period): VendorFulfillmentReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        [$start, $endExclusive] = LedgerPeriod::boundsFor($period);

        $counts = DB::table('vendor_orders')
            ->selectRaw('vendor_orders.vendor_id AS vendor_id, vendor_orders.status AS status, COUNT(*) AS total')
            ->where('vendor_orders.created_at', '>=', $start)
            ->where('vendor_orders.created_at', '<', $endExclusive)
            ->groupBy('vendor_orders.vendor_id', 'vendor_orders.status')
            ->get();

        /** @var array<string, array{total: int, completed: int, cancelled: int, complaints: int}> $byVendor */
        $byVendor = [];

        foreach ($counts as $row) {
            $vendorId = (string) $row->vendor_id;
            $status = (string) $row->status;
            $total = (int) $row->total;

            $byVendor[$vendorId] ??= ['total' => 0, 'completed' => 0, 'cancelled' => 0, 'complaints' => 0];
            $byVendor[$vendorId]['total'] += $total;

            match ($status) {
                VendorProcessingStatus::SELESAI => $byVendor[$vendorId]['completed'] += $total,
                VendorProcessingStatus::DIBATALKAN => $byVendor[$vendorId]['cancelled'] += $total,
                VendorProcessingStatus::KOMPLAIN => $byVendor[$vendorId]['complaints'] += $total,
                default => null,
            };
        }

        $vendorNames = Vendor::query()
            ->whereIn('id', array_keys($byVendor))
            ->pluck('name', 'id')
            ->map(static fn ($name): string => (string) $name)
            ->all();

        $rows = [];

        foreach ($byVendor as $vendorId => $stats) {
            $rows[] = [
                'vendor_id' => $vendorId,
                'vendor_name' => $vendorNames[$vendorId] ?? $vendorId,
                'total' => $stats['total'],
                'completed' => $stats['completed'],
                'cancelled' => $stats['cancelled'],
                'complaints' => $stats['complaints'],
                'completion_rate' => $stats['total'] > 0 ? $stats['completed'] / $stats['total'] : 0.0,
            ];
        }

        $rows = $this->sortRowsByVendorName($rows);

        return new VendorFulfillmentReportResult(
            period: $period,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
        );
    }

    /**
     * @throws InvalidVendorFulfillmentReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (! LedgerPeriod::matches($period)) {
            throw InvalidVendorFulfillmentReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order — same collation reasoning as
     * `LedgerReport::sortRowsByAccountCode()`.
     *
     * @param  list<array{vendor_id: string, vendor_name: string, total: int, completed: int, cancelled: int, complaints: int, completion_rate: float}>  $rows
     * @return list<array{vendor_id: string, vendor_name: string, total: int, completed: int, cancelled: int, complaints: int, completion_rate: float}>
     */
    private function sortRowsByVendorName(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['vendor_name'], $b['vendor_name'])
                ?: strcmp($a['vendor_id'], $b['vendor_id']),
        );

        return $rows;
    }
}
