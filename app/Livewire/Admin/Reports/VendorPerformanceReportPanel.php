<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Domain\Marketplace\Exceptions\InvalidVendorFulfillmentReportException;
use App\Domain\Marketplace\VendorFulfillmentReport;
use App\Livewire\Admin\Reports\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Livewire\Component;

/**
 * "Laporan Kinerja Vendor" tab of `App\Filament\Admin\Pages\Reports`. Moved
 * verbatim from the former standalone
 * `App\Filament\Admin\Pages\VendorPerformanceReport` Filament page — see
 * `OrdersReportPanel`'s doc block for why this
 * `MasterDataAdminAuthorizerContract`-gated tab still self-enforces
 * `canAccess()` even though it is the same gate `Reports::canAccess()`
 * already used.
 */
final class VendorPerformanceReportPanel extends Component
{
    use ExportsReportCsv;

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

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->period = CarbonImmutable::now()->format('Y-m');

        $this->loadReport();
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
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

    public function render(): View
    {
        return view('livewire.admin.reports.vendor-performance-report-panel');
    }
}
