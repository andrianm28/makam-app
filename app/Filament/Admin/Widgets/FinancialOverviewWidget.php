<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Filament\Admin\Pages\FinanceReports;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\Models\Reconciliation;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\SessionState;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * ADM-001 (`.kiro/specs/admin-operations/requirements.md` AC1) — the
 * "payment" and "report" dashboard modules.
 *
 * ---------------------------------------------------------------------------
 * Why this is gated (and scoped) differently from the master-data widgets
 * ---------------------------------------------------------------------------
 * Unlike TPU/TPS, vendor, and FAQ counts, `payment_sessions` and
 * `reconciliations` are badan-usaha-scoped financial records —
 * `LedgerReadScope`'s own doc block: "the QUERY-LEVEL SCOPE `AGENTS.md`
 * §Authorization makes mandatory... not a display hint." `FinanceReports`
 * and `ReconciliationsResource` both gate on `LedgerReadAuthorizer` (`finance`
 * role plus at least one active privileged `BUSINESS_ENTITY` grant) rather
 * than the four-role `MasterDataAdminAuthorizerContract` — this widget
 * follows the same narrower gate, and filters every query by the actor's own
 * `$scope->entityRefs` rather than counting the whole table. An `operator`
 * or `restricted_admin` role that can see TPU/TPS counts must NOT see another
 * badan usaha's payment/reconciliation figures through this widget.
 *
 * `canView()` only proves the actor holds SOME grant (`entityRef: null`);
 * `getStats()` re-derives the scope itself rather than trusting a cached
 * result, matching `FinanceReports::loadReport()`'s own re-authorize-per-call
 * shape — a grant revoked mid-session stops the figures at the next render.
 */
final class FinancialOverviewWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        try {
            app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

    protected function getStats(): array
    {
        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return [];
        }

        $entityRefs = $scope->entityRefs;

        $paid = PaymentSession::query()
            ->whereIn('badan_usaha_ref', $entityRefs)
            ->where('state', SessionState::Paid->value)
            ->count();

        $failed = PaymentSession::query()
            ->whereIn('badan_usaha_ref', $entityRefs)
            ->whereIn('state', [SessionState::Failed->value, SessionState::Expired->value])
            ->count();

        $reconciliationRuns = Reconciliation::query()
            ->whereIn('entity_ref', $entityRefs)
            ->count();

        return [
            Stat::make('Pembayaran Berhasil', (string) $paid)
                ->description('Sesi pembayaran berstatus lunas')
                ->icon('heroicon-o-banknotes')
                ->color('success'),

            Stat::make('Pembayaran Bermasalah', (string) $failed)
                ->description('Gagal atau kedaluwarsa')
                ->icon('heroicon-o-x-circle')
                ->color('danger'),

            Stat::make('Laporan Rekonsiliasi', (string) $reconciliationRuns)
                ->description('Lihat laporan keuangan')
                ->icon('heroicon-o-document-chart-bar')
                ->color('info')
                ->url(FinanceReports::getUrl()),
        ];
    }
}
