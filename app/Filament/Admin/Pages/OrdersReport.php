<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\OrderWorkflow\Exceptions\InvalidOrderPeriodReportException;
use App\Domain\OrderWorkflow\OrderPeriodReport;
use App\Filament\Admin\Pages\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Http\Response;

/**
 * ADM-090/AC7 "report on orders ... by period where data exists". Mirrors
 * `FinanceReports`'s architecture (see that page's own doc block for the
 * template this batch follows): a thin Page mounting a plain read-model
 * (`OrderPeriodReport`, not `LedgerReport` — see that class's doc block for
 * why `orders` gets its own query class rather than reusing the ledger's),
 * one `period` input, loading/empty/error/success states, and a CSV export.
 *
 * ---------------------------------------------------------------------------
 * `canAccess()` — role gate only, matching the resources over this table
 * ---------------------------------------------------------------------------
 * `MasterDataAdminAuthorizerContract`, the same four-role
 * (admin/restricted_admin/operator/finance) gate
 * `BookingOrderResource::canAccess()` already uses for this table. AC10's
 * business-entity half does not apply here — see `OrderPeriodReport`'s doc
 * block for why `orders` has no entity dimension to scope by, and why that
 * is a pre-existing structural fact about this table, not a gap introduced
 * by this report.
 *
 * ---------------------------------------------------------------------------
 * The export: in-page CSV of the loaded rows, deliberately NOT
 * `BulkFinancialExport`'s shape
 * ---------------------------------------------------------------------------
 * `FinanceReports`'s export is a separate re-authentication-gated route
 * (`Actions\BulkFinancialExport`) because it can pull a bulk, potentially
 * cross-entity financial extract independent of whatever the page happens to
 * be showing. This report's export cannot do that: `exportCsv()` re-derives
 * the SAME rows `loadReport()` already authorized and rendered, from the
 * SAME period, in the SAME request — it is "save what I am already looking
 * at as a file", not a second, wider read. Building a second
 * `BulkFinancialExportReauthenticationRequiredException`-shaped gate for
 * that would add re-authentication ceremony around a request that already
 * passed the page's own authorization and reveals nothing beyond it.
 * Flagged here for human review per `AGENTS.md` §Infrastructure-agent
 * execution given it still is a financial/operations export, even though
 * its risk profile is narrower than the ledger's bulk export.
 */
final class OrdersReport extends Page
{
    use ExportsReportCsv;

    protected static ?string $slug = 'orders-report';

    protected string $view = 'filament.admin.pages.orders-report';

    public string $period = '';

    public string $error = '';

    public string $generatedAt = '';

    /** @var list<array{status: string, total: int}> */
    public array $reportRows = [];

    public int $total = 0;

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getNavigationLabel(): string
    {
        return 'Laporan Pesanan';
    }

    public function getTitle(): string
    {
        return 'Laporan Pesanan';
    }

    public function mount(): void
    {
        $this->period = CarbonImmutable::now()->format('Y-m');

        $this->loadReport();
    }

    public function loadReport(): void
    {
        $this->error = '';
        $this->resetErrorBag('period');

        try {
            $result = app(OrderPeriodReport::class)->summary($this->period);
        } catch (InvalidOrderPeriodReportException) {
            $message = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

            $this->addError('period', $message);
            $this->error = $message;
            $this->generatedAt = '';
            $this->reportRows = [];
            $this->total = 0;

            return;
        }

        $this->generatedAt = $result->generatedAt->format('Y-m-d H:i:s T');
        $this->reportRows = $result->rows;
        $this->total = $result->total;
    }

    /**
     * Exports exactly the rows currently loaded on the page — see the class
     * doc block for why this is a plain streamed download rather than a
     * second gated route.
     */
    public function exportCsv(): Response
    {
        $lines = [$this->csvLine(['status', 'total'])];

        foreach ($this->reportRows as $row) {
            $lines[] = $this->csvLine([$row['status'], (string) $row['total']]);
        }

        $lines[] = $this->csvLine(['TOTAL', (string) $this->total]);

        return $this->streamCsv($lines, "orders-report-{$this->period}.csv");
    }
}
