<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;

/**
 * The minimal financial "data/actions" surface this lane owns: it shows the
 * `LedgerReport` summary for the badan usaha the acting finance user is granted
 * and offers the bulk CSV export behind it. The full admin-operations financial
 * dashboard (ADM-070, ADM-090) is a later lane's screen — this page exists so
 * the report and the export have a real mount point, not so it becomes that
 * dashboard.
 *
 * No financial logic and no authorization LOGIC live here: the page mounts
 * `LedgerReport` (data) and links to the `admin.finance.exports` route
 * (action), and delegates the "may this actor read the ledger, and whose books"
 * question wholesale to `FinanceLedgerReadAuthorizer` — the same policy
 * `Actions\BulkFinancialExport` enforces, so the page and the export can never
 * disagree about scope. `AGENTS.md` is explicit that no authorization decision
 * may be taken in a Blade view or Filament page; calling a policy is not
 * taking the decision.
 *
 * The concrete class is named here rather than the `Contracts
 * \LedgerReadAuthorizer` interface because this module has no service provider
 * yet — Task 3 deferred `FinancialLedgerServiceProvider` and the container
 * bindings for this module's seams to Task 7. `BulkFinancialExport` types the
 * interface and defaults to this same implementation; when Task 7 binds the
 * interface, this call site becomes the interface with no behaviour change.
 *
 * The Filament panel's own `AdminPanelAccessPolicy` is only
 * `$actor->isAuthenticated()`, so without `canAccess()` here every panel user
 * would see every badan usaha's account totals. It fails closed today
 * (`ActorContext::$roles` is always `[]`) — the correct state for a financial
 * report, and the same state `ResolveException` and `ManualPayout` are in.
 *
 * `period` is `YYYY-MM` and defaults to the current month; a malformed entry is
 * surfaced as an inline validation error (the report's own `assertPeriod`
 * refuses it), never silently reinterpreted. `generatedAt` is kept as a
 * formatted string because a Livewire property must round-trip through the
 * wire protocol — a `CarbonImmutable` would be unserialized, not preserved.
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

    /**
     * Filament calls this on mount AND on every Livewire hydration
     * (`CanAuthorizeAccess`), so a grant revoked mid-session stops the page on
     * the next interaction rather than only at the next full page load.
     */
    public static function canAccess(): bool
    {
        try {
            app(FinanceLedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

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
     * Re-runs the report for the current `period`, over the badan usaha the
     * authorizer grants this actor — never over the whole ledger. Loading,
     * empty, error and success states are all rendered from this page's own
     * state; see the Blade view for which state each property drives.
     */
    public function loadReport(): void
    {
        $this->error = '';
        $this->resetErrorBag('period');

        try {
            $scope = app(FinanceLedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );

            $result = app(LedgerReport::class)->summary($this->period, $scope->entityRefs);
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        } catch (InvalidLedgerReportException) {
            $message = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

            // Both the inline field state and the page-level alert, because the
            // design system requires the input itself to render its invalid
            // state — setting only `$this->error` left `@error('period')` and
            // the wrapper's `:valid` binding permanently dead.
            $this->addError('period', $message);
            $this->error = $message;
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

    /**
     * The export link's own query string. It carries the page's `entityRef` so
     * a scoped view exports the scope it is showing — omitting it meant the
     * button silently exported a wider set than the table above it.
     *
     * @return array<string, string>
     */
    public function exportQuery(): array
    {
        $query = ['period' => $this->period];

        if ($this->entityRef !== '') {
            $query['entity'] = $this->entityRef;
        }

        return $query;
    }
}
