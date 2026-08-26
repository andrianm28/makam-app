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
use Illuminate\Http\Response;
use Livewire\Component;

/**
 * "Laporan Pembayaran Keluar" tab of `App\Filament\Admin\Pages\Reports`.
 * Moved verbatim from the former standalone
 * `App\Filament\Admin\Pages\OutgoingPaymentsReport` Filament page — see
 * `FinanceReportPanel`'s doc block for why this `LedgerReadAuthorizer`-gated
 * tab still self-enforces `canAccess()` on top of `Reports::canAccess()`'s
 * broader floor.
 */
final class OutgoingPaymentsReportPanel extends Component
{
    use ExportsReportCsv;

    public string $period = '';

    public string $error = '';

    public string $entityRef = '';

    public string $generatedAt = '';

    /** @var list<array{id: string, vendor_id: string, entity_ref: string, amount_minor: int, method: string, state: string, occurred_at: string}> */
    public array $reportRows = [];

    public int $totalMinor = 0;

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
            $scope = app(LedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );

            $result = app(PayoutSummaryReport::class)->summary($this->period, $scope->entityRefs);
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        } catch (InvalidLedgerReportException) {
            $message = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

            $this->addError('period', $message);
            $this->error = $message;
            $this->generatedAt = '';
            $this->reportRows = [];
            $this->totalMinor = 0;

            return;
        }

        $this->generatedAt = $result->generatedAt->format('Y-m-d H:i:s T');
        $this->reportRows = $result->rows;
        $this->totalMinor = $result->totalMinor;
    }

    public function exportCsv(): Response
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
            $result = app(PayoutSummaryReport::class)->summary($this->period, $scope->entityRefs);
        } catch (InvalidLedgerReportException) {
            abort(422, 'Format periode tidak valid.');
        }

        $lines = [$this->csvLine([
            'id', 'vendor_id', 'entity_ref', 'amount_minor', 'method', 'state', 'occurred_at',
        ])];

        foreach ($result->rows as $row) {
            $lines[] = $this->csvLine([
                $row['id'],
                $row['vendor_id'],
                $row['entity_ref'],
                (string) $row['amount_minor'],
                $row['method'],
                $row['state'],
                $row['occurred_at'],
            ]);
        }

        $lines[] = $this->csvLine(['TOTAL', '', '', (string) $result->totalMinor, '', '', '']);

        return $this->streamCsv($lines, "outgoing-payments-report-{$this->period}.csv");
    }

    public function render(): View
    {
        return view('livewire.admin.reports.outgoing-payments-report-panel');
    }
}
