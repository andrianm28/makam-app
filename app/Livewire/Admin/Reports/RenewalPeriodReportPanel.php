<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Domain\Renewal\Exceptions\InvalidRenewalReportException;
use App\Domain\Renewal\RenewalReport;
use App\Livewire\Admin\Reports\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Livewire\Component;

/**
 * "Laporan Perpanjangan" tab of `App\Filament\Admin\Pages\Reports`. Moved
 * verbatim from the former standalone
 * `App\Filament\Admin\Pages\RenewalPeriodReport` Filament page — see
 * `OrdersReportPanel`'s doc block for why this
 * `MasterDataAdminAuthorizerContract`-gated tab still self-enforces
 * `canAccess()` even though it is the same gate `Reports::canAccess()`
 * already used.
 */
final class RenewalPeriodReportPanel extends Component
{
    use ExportsReportCsv;

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
            $result = app(RenewalReport::class)->summary($this->period);
        } catch (InvalidRenewalReportException) {
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

    public function exportCsv(): Response
    {
        $lines = [$this->csvLine(['status', 'total'])];

        foreach ($this->reportRows as $row) {
            $lines[] = $this->csvLine([$row['status'], (string) $row['total']]);
        }

        $lines[] = $this->csvLine(['TOTAL', (string) $this->total]);

        return $this->streamCsv($lines, "renewal-period-report-{$this->period}.csv");
    }

    public function render(): View
    {
        return view('livewire.admin.reports.renewal-period-report-panel');
    }
}
