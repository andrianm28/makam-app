<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Pages\Concerns\ExportsReportCsv;
use App\Platform\FinancialLedger\CashReceiptsReport;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Illuminate\Http\Response;

/**
 * ADM-090/AC7 "report on ... receipts ... by period where data exists".
 * Mirrors `FinanceReports`'s architecture — see that page's own doc block —
 * over `CashReceiptsReport` instead of `LedgerReport` (that class's doc
 * block argues why a receipts LIST, not an account-code summary, is the
 * right shape here).
 *
 * ---------------------------------------------------------------------------
 * AC10 scoping: the SAME `LedgerReadAuthorizer` `FinanceReports` uses
 * ---------------------------------------------------------------------------
 * Receipts are journal-derived money, so they carry the same
 * `journal_batches.entity_ref` business-entity dimension the ledger does.
 * `canAccess()`, `loadReport()` and `exportCsv()` all resolve
 * `Contracts\LedgerReadAuthorizer` fresh (never cache the scope across a
 * request) — identical discipline to `FinanceReports`, for the identical
 * reason: a grant revoked mid-session must stop the page on the next
 * Livewire interaction, not only at the next full page load.
 *
 * ---------------------------------------------------------------------------
 * The export — in-page, not `BulkFinancialExport`'s reauthenticated route
 * ---------------------------------------------------------------------------
 * See `OrdersReport`'s doc block for the reasoning this page shares:
 * `exportCsv()` re-derives the same authorized, already-rendered rows rather
 * than opening a second, wider read, so it does not need
 * `BulkFinancialExport`'s re-authentication ceremony. This is still real
 * financial data leaving the system as a file — flagged for human review per
 * `AGENTS.md` §Infrastructure-agent execution.
 */
final class ReceiptsReport extends Page
{
    use ExportsReportCsv;

    protected static ?string $slug = 'laporan-kwitansi';

    protected string $view = 'filament.admin.pages.receipts-report';

    public string $period = '';

    public string $error = '';

    public string $entityRef = '';

    public string $generatedAt = '';

    /** @var list<array{business_key: string, source_type: string, source_id: string, entity_ref: string, occurred_at: string, amount_minor: int}> */
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

    public static function getNavigationLabel(): string
    {
        return 'Laporan Penerimaan';
    }

    public function getTitle(): string
    {
        return 'Laporan Penerimaan';
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
            $scope = app(LedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );

            $result = app(CashReceiptsReport::class)->summary($this->period, $scope->entityRefs);
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
            $result = app(CashReceiptsReport::class)->summary($this->period, $scope->entityRefs);
        } catch (InvalidLedgerReportException) {
            abort(422, 'Format periode tidak valid.');
        }

        $lines = [$this->csvLine([
            'business_key', 'source_type', 'source_id', 'entity_ref', 'occurred_at', 'amount_minor',
        ])];

        foreach ($result->rows as $row) {
            $lines[] = $this->csvLine([
                $row['business_key'],
                $row['source_type'],
                $row['source_id'],
                $row['entity_ref'],
                $row['occurred_at'],
                (string) $row['amount_minor'],
            ]);
        }

        $lines[] = $this->csvLine(['TOTAL', '', '', '', '', (string) $result->totalMinor]);

        return $this->streamCsv($lines, "receipts-report-{$this->period}.csv");
    }
}
