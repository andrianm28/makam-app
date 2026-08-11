<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\LedgerReport;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/**
 * The minimal financial "data/actions" surface this lane owns: it shows the
 * `LedgerReport` summary for one period and offers the bulk CSV export
 * behind it. The full admin-operations financial dashboard (ADM-070,
 * ADM-090) is a later lane's screen — this page exists so the report and the
 * export have a real mount point, not so it becomes that dashboard.
 *
 * No financial logic lives here: the page only mounts `LedgerReport` (data)
 * and links to the `admin.finance.exports` route (action). The export route
 * itself is protected by `RequireRecentAuthentication`, so clicking the
 * button as a stale actor lands on `MfaChallenge` and returns here via the
 * `url.intended` key both classes share — the page says so in its own copy,
 * not because it performs the gate itself.
 *
 * `period` is `YYYY-MM` and defaults to the current month; a malformed entry
 * is surfaced as an inline error (the report's own `assertPeriod` refuses
 * it), never silently reinterpreted. `generatedAt` is kept as a formatted
 * string because a Livewire property must round-trip through the wire
 * protocol — a `CarbonImmutable` would be unserialized, not preserved.
 */
final class FinanceReports extends Page
{
    protected static ?string $slug = 'finance-reports';

    protected string $view = 'filament.admin.pages.finance-reports';

    public string $period = '';

    public string $error = '';

    public string $source = '';

    public string $generatedAt = '';

    public string $entityRef = '';

    /** @var list<array{account_code: string, debit_total: int, credit_total: int, net: int}> */
    public array $reportRows = [];

    public int $debitTotal = 0;

    public int $creditTotal = 0;

    public static function getNavigationLabel(): string
    {
        return 'Laporan Keuangan';
    }

    public function getTitle(): string
    {
        return 'Laporan Keuangan';
    }

    public function mount(): void
    {
        $this->period = CarbonImmutable::now()->format('Y-m');

        $this->loadReport();
    }

    /**
     * Re-runs the report for the current `period`. Loading, empty, error and
     * success states are all rendered from this page's own state — see the
     * Blade view for which state each property drives.
     */
    public function loadReport(): void
    {
        $this->error = '';

        try {
            $result = app(LedgerReport::class)->summary(
                $this->period,
                $this->entityRef !== '' ? $this->entityRef : null,
            );
        } catch (InvalidLedgerReportException) {
            $this->error = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';
            $this->source = '';
            $this->generatedAt = '';
            $this->reportRows = [];
            $this->debitTotal = 0;
            $this->creditTotal = 0;

            return;
        }

        $this->source = $result->source;
        $this->generatedAt = $result->generatedAt->format('Y-m-d H:i:s T');
        $this->reportRows = $result->rows;
        $this->debitTotal = array_sum(array_column($result->rows, 'debit_total'));
        $this->creditTotal = array_sum(array_column($result->rows, 'credit_total'));
    }
}
