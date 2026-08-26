<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Livewire\Admin\Reports\FinanceReportPanel;
use App\Livewire\Admin\Reports\OrdersReportPanel;
use App\Livewire\Admin\Reports\OutgoingPaymentsReportPanel;
use App\Livewire\Admin\Reports\ReceiptsReportPanel;
use App\Livewire\Admin\Reports\RenewalPeriodReportPanel;
use App\Livewire\Admin\Reports\VendorPerformanceReportPanel;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Pages\Page;
use Livewire\Attributes\Url;

/**
 * "Laporan" — the single consolidated admin reports page. Replaces the six
 * former separate navigation entries (`FinanceReports`, `OrdersReport`,
 * `ReceiptsReport`, `OutgoingPaymentsReport`, `VendorPerformanceReport`,
 * `RenewalPeriodReport`) with one nav entry and one landing page, each
 * former page's content now a tab rendered by a nested Livewire component
 * under `App\Livewire\Admin\Reports\*Panel` — the exact same properties,
 * `loadReport()`/`exportCsv()` logic, and Blade markup those six pages had,
 * only moved out of `Filament\Pages\Page` (see each panel's own doc block).
 *
 * ---------------------------------------------------------------------------
 * Why `canAccess()` is `MasterDataAdminAuthorizerContract` and not
 * `LedgerReadAuthorizer`
 * ---------------------------------------------------------------------------
 * The six original pages split into two authorization tiers, not "finance
 * vs. the other five" as might be assumed from the nav labels:
 *
 *   - `MasterDataAdminAuthorizerContract` (any of admin / restricted_admin /
 *     operator / finance — a role-only check, no record scope): gated
 *     `OrdersReport`, `VendorPerformanceReport`, `RenewalPeriodReport`.
 *   - `LedgerReadAuthorizer` (the `finance` role specifically, PLUS at least
 *     one active privileged `BUSINESS_ENTITY` scope grant, and the read
 *     scoped to exactly those grants): gated `FinanceReports`,
 *     `ReceiptsReport`, `OutgoingPaymentsReport` — three pages, not one.
 *
 * Because `finance` is itself one of `MasterDataAdminAuthorizerContract`'s
 * four allowed roles, every actor who can pass `LedgerReadAuthorizer` can
 * also pass `MasterDataAdminAuthorizerContract` — but not the reverse (an
 * `operator`, `restricted_admin`, or ungranted `finance` actor passes the
 * master-data gate while failing the ledger one). `MasterDataAdminAuthorizerContract`
 * is therefore exactly the UNION floor: the minimum required to reach ANY of
 * the six tabs. Gating the whole page behind the stricter `LedgerReadAuthorizer`
 * would 403 an `operator`/`restricted_admin` actor out of the three tabs they
 * are entitled to; gating it behind nothing narrower would be a regression
 * from every one of the six pages' own `canAccess()`.
 *
 * ---------------------------------------------------------------------------
 * How the finance-tier tabs stay correctly hidden — the load-bearing
 * mechanism
 * ---------------------------------------------------------------------------
 * The page-level floor above is NOT sufficient on its own to keep ledger
 * data off screen for an actor who passes it without passing
 * `LedgerReadAuthorizer` (e.g. `operator`). Two independent layers close
 * that gap:
 *
 *   1. `reportTabs()` below calls each panel's own `canAccess()` (identical
 *      logic to what its former standalone Filament page used) to decide
 *      whether that tab is listed AND whether its content may render for
 *      the current request — recomputed on every `mount()`/`hydrate()`, so
 *      a grant revoked mid-session closes a tab on the very next Livewire
 *      round trip, matching every one of the six original pages' own
 *      "re-authorize on every access" discipline.
 *   2. The Blade view only emits `@livewire(...)` for the ACTIVE tab, and
 *      only when that tab's own `visible` flag is true. An unauthorized
 *      tab's Livewire component is never mounted at all — not mounted and
 *      hidden with CSS, which would still leak its data into the rendered
 *      HTML. A nested Livewire component that is never mounted has no
 *      server-signed snapshot for a client to replay, so it cannot be
 *      reached out of band either. Each panel's own `mount()`/`hydrate()`
 *      additionally re-runs its own `canAccess()` and aborts 403 — belt and
 *      braces against the tab ever being embedded for an unauthorized actor
 *      by a future code change that forgets the `visible` guard.
 *
 * `activeTab` is `#[Url]`-bound so a deep link (e.g.
 * `FinancialOverviewWidget`'s stat card, which used to link straight to
 * `FinanceReports::getUrl()`) can land directly on a tab — `?tab=keuangan`.
 * If the tab named in the URL is not visible to the current actor (a stale
 * link, or a deliberately tampered query string), `mount()`/`hydrate()`
 * silently fall back to the first tab that IS visible, rather than 403ing
 * the whole page or rendering nothing.
 */
final class Reports extends Page
{
    protected static ?string $slug = 'laporan';

    protected string $view = 'filament.admin.pages.reports';

    #[Url(as: 'tab')]
    public string $activeTab = '';

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
        return 'Laporan';
    }

    public function getTitle(): string
    {
        return 'Laporan';
    }

    public function mount(): void
    {
        $this->resolveActiveTab();
    }

    public function hydrate(): void
    {
        $this->resolveActiveTab();
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->resolveActiveTab();
    }

    /**
     * @return list<array{key: string, label: string, component: class-string, visible: bool}>
     */
    public function reportTabs(): array
    {
        return [
            [
                'key' => 'keuangan',
                'label' => 'Laporan Keuangan',
                'component' => FinanceReportPanel::class,
                'visible' => FinanceReportPanel::canAccess(),
            ],
            [
                'key' => 'pesanan',
                'label' => 'Laporan Pesanan',
                'component' => OrdersReportPanel::class,
                'visible' => OrdersReportPanel::canAccess(),
            ],
            [
                'key' => 'penerimaan',
                'label' => 'Laporan Penerimaan',
                'component' => ReceiptsReportPanel::class,
                'visible' => ReceiptsReportPanel::canAccess(),
            ],
            [
                'key' => 'pembayaran-keluar',
                'label' => 'Laporan Pembayaran Keluar',
                'component' => OutgoingPaymentsReportPanel::class,
                'visible' => OutgoingPaymentsReportPanel::canAccess(),
            ],
            [
                'key' => 'kinerja-vendor',
                'label' => 'Laporan Kinerja Vendor',
                'component' => VendorPerformanceReportPanel::class,
                'visible' => VendorPerformanceReportPanel::canAccess(),
            ],
            [
                'key' => 'perpanjangan',
                'label' => 'Laporan Perpanjangan',
                'component' => RenewalPeriodReportPanel::class,
                'visible' => RenewalPeriodReportPanel::canAccess(),
            ],
        ];
    }

    /**
     * Ensures `activeTab` always names a tab visible to the current actor —
     * defaulting to the first visible one when it is blank, unknown, or no
     * longer visible (a grant revoked mid-session, or a tampered/stale
     * `?tab=` value). `Reports::canAccess()` guarantees at least one tab is
     * always visible: every role it admits also passes at least one of the
     * three `MasterDataAdminAuthorizerContract`-gated panels' own identical
     * check.
     */
    private function resolveActiveTab(): void
    {
        $tabs = $this->reportTabs();

        $isCurrentTabVisible = collect($tabs)
            ->contains(fn (array $tab): bool => $tab['key'] === $this->activeTab && $tab['visible']);

        if ($isCurrentTabVisible) {
            return;
        }

        $firstVisible = collect($tabs)->first(static fn (array $tab): bool => $tab['visible']);

        $this->activeTab = $firstVisible['key'] ?? '';
    }
}
