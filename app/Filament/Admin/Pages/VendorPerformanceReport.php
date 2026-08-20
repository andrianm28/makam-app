<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Marketplace\Exceptions\InvalidVendorFulfillmentReportException;
use App\Domain\Marketplace\VendorFulfillmentReport;
use App\Filament\Admin\Pages\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Http\Response;

/**
 * ADM-090/AC7 "report on ... vendor performance ... by period where data
 * exists". Mirrors `FinanceReports`'s architecture over
 * `VendorFulfillmentReport` — see that class's own doc block for why
 * fulfilment-outcome counts, not an invented rating, is "performance" here.
 *
 * `canAccess()` and the "no business-entity scoping" reasoning are identical
 * to `OrdersReport`'s, over the vendor domain instead of the order one — see
 * `VendorFulfillmentReport`'s doc block, and `Filament\Admin\Resources\
 * Vendors\VendorResource::canAccess()`, the resource this report shares its
 * gate with.
 *
 * The export follows `OrdersReport`'s in-page-CSV reasoning: `exportCsv()`
 * re-derives the same rows already authorized and rendered.
 */
final class VendorPerformanceReport extends Page
{
    use ExportsReportCsv;

    protected static ?string $slug = 'vendor-performance-report';

    protected string $view = 'filament.admin.pages.vendor-performance-report';

    public string $period = '';

    public string $error = '';

    public string $generatedAt = '';

    /** @var list<array{vendor_id: string, vendor_name: string, total: int, completed: int, cancelled: int, complaints: int, completion_rate: float}> */
    public array $reportRows = [];

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
        return 'Laporan Kinerja Vendor';
    }

    public function getTitle(): string
    {
        return 'Laporan Kinerja Vendor';
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
            $result = app(VendorFulfillmentReport::class)->summary($this->period);
        } catch (InvalidVendorFulfillmentReportException) {
            $message = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

            $this->addError('period', $message);
            $this->error = $message;
            $this->generatedAt = '';
            $this->reportRows = [];

            return;
        }

        $this->generatedAt = $result->generatedAt->format('Y-m-d H:i:s T');
        $this->reportRows = $result->rows;
    }

    public function exportCsv(): Response
    {
        $lines = [$this->csvLine([
            'vendor_id', 'vendor_name', 'total', 'completed', 'cancelled', 'complaints', 'completion_rate',
        ])];

        foreach ($this->reportRows as $row) {
            $lines[] = $this->csvLine([
                $row['vendor_id'],
                $row['vendor_name'],
                (string) $row['total'],
                (string) $row['completed'],
                (string) $row['cancelled'],
                (string) $row['complaints'],
                number_format($row['completion_rate'], 4, '.', ''),
            ]);
        }

        return $this->streamCsv($lines, "vendor-performance-report-{$this->period}.csv");
    }
}
