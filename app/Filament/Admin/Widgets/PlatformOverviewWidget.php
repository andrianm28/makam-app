<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Marketplace\Models\Vendor;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * ADM-001 (`.kiro/specs/admin-operations/requirements.md` AC1) — the
 * TPU/TPS, vendor, and FAQ modules of the dashboard summary. "Transaction",
 * "payment", "order status" and "report" are covered by
 * `OrderStatusOverviewWidget` and `FinancialOverviewWidget` respectively;
 * this widget only covers the three master-data counts.
 *
 * Gated the same way every master-data Resource in this panel is
 * (`CemeteryResource::canAccess()`, `VendorResource::canAccess()`): any of
 * the four back-office roles via `MasterDataAdminAuthorizerContract`. These
 * are platform-wide counts with no per-badan-usaha scope to apply — the
 * same reasoning `MasterDataAdminAuthorizer`'s own doc block gives for why
 * master data has no record-level scope check.
 */
final class PlatformOverviewWidget extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    protected function getStats(): array
    {
        $tpuTotal = Cemetery::query()->ofType(CemeteryType::TPU)->count();
        $tpuPublished = Cemetery::query()->ofType(CemeteryType::TPU)->published()->count();

        $tpsTotal = Cemetery::query()->ofType(CemeteryType::TPS)->count();
        $tpsPublished = Cemetery::query()->ofType(CemeteryType::TPS)->published()->count();

        $vendorActive = Vendor::query()->active()->count();
        $vendorTotal = Vendor::query()->count();

        $faqPublished = FaqArticle::query()->published()->count();
        $faqTotal = FaqArticle::query()->count();

        return [
            Stat::make('TPU', (string) $tpuTotal)
                ->description("{$tpuPublished} dipublikasikan")
                ->icon('heroicon-o-building-office-2')
                ->color('primary'),

            Stat::make('TPS', (string) $tpsTotal)
                ->description("{$tpsPublished} dipublikasikan")
                ->icon('heroicon-o-building-office')
                ->color('primary'),

            Stat::make('Vendor Aktif', (string) $vendorActive)
                ->description("dari {$vendorTotal} total vendor")
                ->icon('heroicon-o-truck')
                ->color('info'),

            Stat::make('FAQ Dipublikasikan', (string) $faqPublished)
                ->description("dari {$faqTotal} total artikel")
                ->icon('heroicon-o-question-mark-circle')
                ->color('info'),
        ];
    }
}
