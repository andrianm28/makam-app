<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\Renewal\Exceptions\InvalidRenewalReportException;
use App\Domain\Renewal\RenewalReport;
use App\Filament\Admin\Pages\Concerns\ExportsReportCsv;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Http\Response;

/**
 * ADM-090/AC7 "report on ... renewal by period where data exists". Mirrors
 * `FinanceReports`'s architecture over `RenewalReport` — see that class's
 * own doc block for why the period filters `target_due_period` (the
 * renewal's own due-period concept) rather than `created_at`.
 *
 * `canAccess()` and the "no business-entity scoping" reasoning are identical
 * to `OrdersReport`'s, over the renewal domain instead of the order one —
 * see `RenewalReport`'s doc block, and `RenewalOrderResource::canAccess()`,
 * the resource this report shares its gate with.
 *
 * The export follows `OrdersReport`'s in-page-CSV reasoning.
 */
final class RenewalPeriodReport extends Page
{
    use ExportsReportCsv;

    protected static ?string $slug = 'laporan-periode-perpanjangan';

    protected string $view = 'filament.admin.pages.renewal-period-report';

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
        return 'Laporan Perpanjangan';
    }

    public function getTitle(): string
    {
        return 'Laporan Perpanjangan';
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
}
