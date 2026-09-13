<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Livewire\Admin\Reports\Concerns\ExportsReportCsv;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\PayoutSummaryReport;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Laporan Pembayaran Keluar" tab of `App\Filament\Admin\Pages\Reports`.
 * Moved verbatim from the former standalone
 * `App\Filament\Admin\Pages\OutgoingPaymentsReport` Filament page — see
 * `FinanceReportPanel`'s doc block for why this `LedgerReadAuthorizer`-gated
 * tab still self-enforces `canAccess()` on top of `Reports::canAccess()`'s
 * broader floor.
 *
 * PERF-14 (batch M7b): see `ReceiptsReportPanel`'s matching doc block —
 * this panel's `$reportRows`/`$totalMinor` were removed from public state
 * for the identical reasoning, and `exportCsv()` was rebuilt on
 * `PayoutSummaryReport::cursor()` the same way.
 */
final class OutgoingPaymentsReportPanel extends Component
{
    use ExportsReportCsv;

    /** PERF-14 — see ReceiptsReportPanel::MAX_DISPLAY_ROWS. */
    private const int MAX_DISPLAY_ROWS = 500;

    public string $period = '';

    #[Locked]
    public string $error = '';

    public string $entityRef = '';

    public static function canAccess(): bool
    {
        try {
            app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);

        $this->period = CarbonImmutable::now()->format('Y-m');
    }

    public function hydrate(): void
    {
        abort_unless(self::canAccess(), 403);
    }

    /**
     * `wire:click` target for the "Tampilkan" button — see
     * `ReceiptsReportPanel::loadReport()`'s doc block for why this is a
     * deliberate no-op: `render()` recomputes the report from the current
     * bound `$period`/`$entityRef` on every render, including the one
     * this action triggers.
     */
    public function loadReport(): void
    {
        //
    }

    public function exportCsv(): StreamedResponse
    {
        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        }

        try {
            app(PayoutSummaryReport::class)->assertPeriod($this->period);
        } catch (InvalidLedgerReportException) {
            abort(422, 'Format periode tidak valid.');
        }

        $period = $this->period;
        $entityRefs = $scope->entityRefs;

        return $this->streamCsvRows(
            $this->exportCsvLines($period, $entityRefs),
            "outgoing-payments-report-{$this->period}.csv",
        );
    }

    /**
     * @param  list<string>|null  $entityRefs
     * @return iterable<string>
     */
    private function exportCsvLines(string $period, ?array $entityRefs): iterable
    {
        yield $this->csvLine([
            'id', 'vendor_id', 'entity_ref', 'amount_minor', 'method', 'state', 'occurred_at',
        ]);

        $totalMinor = 0;

        foreach (app(PayoutSummaryReport::class)->cursor($period, $entityRefs) as $row) {
            $totalMinor += $row['amount_minor'];

            yield $this->csvLine([
                $row['id'],
                $row['vendor_id'],
                $row['entity_ref'],
                (string) $row['amount_minor'],
                $row['method'],
                $row['state'],
                $row['occurred_at'],
            ]);
        }

        yield $this->csvLine(['TOTAL', '', '', (string) $totalMinor, '', '', '']);
    }

    /**
     * PERF-14 — see ReceiptsReportPanel::render()'s doc block; identical
     * reasoning and shape, only the report class and row columns differ.
     */
    public function render(): View
    {
        $error = '';
        $reportRows = [];
        $totalMinor = 0;
        $generatedAt = '';

        $this->resetErrorBag('period');

        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );

            $result = app(PayoutSummaryReport::class)->summary($this->period, $scope->entityRefs);

            $reportRows = array_slice($result->rows, 0, self::MAX_DISPLAY_ROWS);
            $totalMinor = $result->totalMinor;
            $generatedAt = $result->generatedAt->format('Y-m-d H:i:s T');
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        } catch (InvalidLedgerReportException) {
            $error = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

            $this->addError('period', $error);
        }

        $this->error = $error;

        return view('livewire.admin.reports.outgoing-payments-report-panel', [
            'reportRows' => $reportRows,
            'totalMinor' => $totalMinor,
            'generatedAt' => $generatedAt,
        ]);
    }
}
