<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Livewire\Admin\Reports\Concerns\ExportsReportCsv;
use App\Platform\FinancialLedger\CashReceiptsReport;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * "Laporan Penerimaan" tab of `App\Filament\Admin\Pages\Reports`. Moved
 * verbatim from the former standalone `App\Filament\Admin\Pages\ReceiptsReport`
 * Filament page — see `FinanceReportPanel`'s doc block for why this
 * `LedgerReadAuthorizer`-gated tab still self-enforces `canAccess()` on top
 * of `Reports::canAccess()`'s broader floor.
 *
 * ---------------------------------------------------------------------------
 * PERF-14 (batch M7b) — no row-level financial data in public state
 * ---------------------------------------------------------------------------
 * This component previously held `public array $reportRows` and
 * `public int $totalMinor` — full row-level financial data serialized into
 * every Livewire snapshot round-trip to the browser. Both are now computed
 * fresh inside `render()` and handed straight to the Blade view, never
 * stored on `$this` — see `render()`'s own doc block. `$error` is the only
 * component-owned (non-form-input) property left public, and is
 * `#[Locked]` so a client-side update attempt to it is rejected outright.
 * `$period`/`$entityRef` stay public and unlocked because they are genuine
 * two-way-bound form inputs the operator is meant to change.
 */
final class ReceiptsReportPanel extends Component
{
    use ExportsReportCsv;

    /**
     * On-screen row cap — PERF-14: the report itself is unbounded (a busy
     * entity's month of receipts), so the on-screen view is capped while
     * `exportCsv()` streams the full, uncapped set via a `cursor()`-based
     * path instead.
     */
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
     * `wire:click` target for the "Tampilkan" button: submits the bound
     * `$period`/`$entityRef` to the server and triggers Livewire's normal
     * re-render, which recomputes the report from scratch in `render()`.
     * Kept as a named action (rather than relying on a live-bound input)
     * so a partial/invalid `$period` mid-edit is never queried against.
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
            app(CashReceiptsReport::class)->assertPeriod($this->period);
        } catch (InvalidLedgerReportException) {
            abort(422, 'Format periode tidak valid.');
        }

        $period = $this->period;
        $entityRefs = $scope->entityRefs;

        // PERF-14 — streamed via cursor(), never materializing the full
        // result set: the header line and each row are echoed as they are
        // pulled off the database cursor, and the running total is
        // accumulated in O(1) memory rather than summed over a
        // fully-loaded array.
        return $this->streamCsvRows(
            $this->exportCsvLines($period, $entityRefs),
            "receipts-report-{$this->period}.csv",
        );
    }

    /**
     * @param  list<string>|null  $entityRefs
     * @return iterable<string>
     */
    private function exportCsvLines(string $period, ?array $entityRefs): iterable
    {
        yield $this->csvLine([
            'business_key', 'source_type', 'source_id', 'entity_ref', 'occurred_at', 'amount_minor',
        ]);

        $totalMinor = 0;

        foreach (app(CashReceiptsReport::class)->cursor($period, $entityRefs) as $row) {
            $totalMinor += $row['amount_minor'];

            yield $this->csvLine([
                $row['business_key'],
                $row['source_type'],
                $row['source_id'],
                $row['entity_ref'],
                $row['occurred_at'],
                (string) $row['amount_minor'],
            ]);
        }

        yield $this->csvLine(['TOTAL', '', '', '', '', (string) $totalMinor]);
    }

    /**
     * PERF-14 — the on-screen query lives here now, not in a
     * component-state-mutating `loadReport()`: `render()` runs after
     * every action (including `loadReport()`'s no-op body above), so
     * recomputing the report here, from the current `$period`/
     * `$entityRef`, on every render is equivalent to the old
     * mount()-then-loadReport() flow without ever storing row-level data
     * on `$this`.
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

            $result = app(CashReceiptsReport::class)->summary($this->period, $scope->entityRefs);

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

        return view('livewire.admin.reports.receipts-report-panel', [
            'reportRows' => $reportRows,
            'totalMinor' => $totalMinor,
            'generatedAt' => $generatedAt,
        ]);
    }
}
