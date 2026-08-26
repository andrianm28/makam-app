<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Domain\OrderWorkflow\Exceptions\InvalidOrderPeriodReportException;
use App\Domain\OrderWorkflow\OrderPeriodReport;
use App\Livewire\Admin\Reports\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Livewire\Component;

/**
 * "Laporan Pesanan" tab of `App\Filament\Admin\Pages\Reports`. Moved
 * verbatim from the former standalone `App\Filament\Admin\Pages\OrdersReport`
 * Filament page — see `Reports`'s doc block for the consolidation this is
 * part of, and `FinanceReportPanel`'s doc block for why a tab still
 * self-enforces its own `canAccess()` even though `Reports::canAccess()`
 * already used the identical `MasterDataAdminAuthorizerContract` gate: this
 * tab's own check is what stays correct if that gate is ever narrowed for
 * only some of the tabs sharing it, and it is what the standalone
 * `Livewire::test(OrdersReportPanel::class)` unit-level tests exercise
 * directly.
 */
final class OrdersReportPanel extends Component
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

    public function exportCsv(): Response
    {
        $lines = [$this->csvLine(['status', 'total'])];

        foreach ($this->reportRows as $row) {
            $lines[] = $this->csvLine([$row['status'], (string) $row['total']]);
        }

        $lines[] = $this->csvLine(['TOTAL', (string) $this->total]);

        return $this->streamCsv($lines, "orders-report-{$this->period}.csv");
    }

    public function render(): View
    {
        return view('livewire.admin.reports.orders-report-panel');
    }
}
