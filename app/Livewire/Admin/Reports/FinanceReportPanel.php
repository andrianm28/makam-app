<?php

declare(strict_types=1);

namespace App\Livewire\Admin\Reports;

use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\LedgerReport;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * "Laporan Keuangan" tab of the consolidated `App\Filament\Admin\Pages\Reports`
 * page. Moved verbatim (state and behaviour unchanged) from the former
 * standalone `App\Filament\Admin\Pages\FinanceReports` Filament page as part
 * of the six-report-page-to-one-page consolidation — see `Reports`'s own doc
 * block for why a plain nested Livewire component, not another Filament
 * page, is the right shape now.
 *
 * ---------------------------------------------------------------------------
 * Why this one still self-enforces `canAccess()`, unlike the three
 * `MasterDataAdminAuthorizerContract`-gated tabs
 * ---------------------------------------------------------------------------
 * `Reports::canAccess()` is only the FLOOR every tab needs (the
 * `MasterDataAdminAuthorizerContract` four-role gate) — deliberately not
 * `LedgerReadAuthorizer`'s narrower `finance`-role-plus-business-entity-grant
 * test, because an `operator` or `restricted_admin` actor must still reach
 * the page to see the other tabs. That means the page-level gate alone is
 * NOT sufficient to keep this tab's ledger data off screen for a
 * `MasterDataAdminAuthorizerContract`-passing actor who lacks
 * `LedgerReadAuthorizer`'s stricter grant, so this component still
 * re-authorizes independently, exactly as `FinanceReports` did standalone.
 *
 * `Reports` never even mounts this component (see its `reportTabs()` and the
 * Blade view's `@livewire()` guard) unless its own `canAccess()` already
 * returned true for the current actor — this `abort_unless()` is
 * defence-in-depth against the component being reached out of band (e.g. a
 * stale nested-Livewire snapshot from before a grant was revoked
 * mid-session), matching `Filament\Pages\Concerns\CanAuthorizeAccess`'s own
 * mount/hydrate double-check, which this class no longer inherits now that
 * it is a plain `Livewire\Component`.
 *
 * `period` is `YYYY-MM` and defaults to the current month; a malformed entry
 * is surfaced as an inline validation error (the report's own `assertPeriod`
 * refuses it), never silently reinterpreted. `generatedAt` is kept as a
 * formatted string because a Livewire property must round-trip through the
 * wire protocol — a `CarbonImmutable` would be unserialized, not preserved.
 */
final class FinanceReportPanel extends Component
{
    public string $period = '';

    public string $error = '';

    public string $source = '';

    public string $generatedAt = '';

    public string $entityRef = '';

    /** @var list<array{account_code: string, debit_total: int, credit_total: int, net: int}> */
    public array $reportRows = [];

    public int $debitTotal = 0;

    public int $creditTotal = 0;

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

    /**
     * Re-runs the report for the current `period`, over the badan usaha the
     * authorizer grants this actor — never over the whole ledger.
     */
    public function loadReport(): void
    {
        $this->error = '';
        $this->resetErrorBag('period');

        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(
                app(ActorContext::class),
                $this->entityRef !== '' ? $this->entityRef : null,
            );

            $result = app(LedgerReport::class)->summary($this->period, $scope->entityRefs);
        } catch (LedgerReadNotAuthorisedException) {
            abort(403);
        } catch (InvalidLedgerReportException) {
            $message = 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.';

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
     * The export link's own query string. It carries the component's
     * `entityRef` so a scoped view exports the scope it is showing.
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

    public function render(): View
    {
        return view('livewire.admin.reports.finance-report-panel');
    }
}
