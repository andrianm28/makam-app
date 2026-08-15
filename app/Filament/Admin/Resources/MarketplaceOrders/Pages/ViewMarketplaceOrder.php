<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Pages;

use App\Filament\Admin\Resources\MarketplaceOrders\Actions\MarkMarketplaceOrderPaidAction;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `MarketplaceOrderResource` — renders
 * `Schemas\MarketplaceOrderInfolist` and carries the resource's single
 * write surface: the finance-gated `MarkMarketplaceOrderPaidAction` header
 * action. The action's own `->authorize()` closure (plus the in-action
 * `OrderTransitionAuthorizerContract` + `ReauthenticationGuard`
 * enforcement) decides both whether the button renders and whether the
 * transition is permitted — this page itself stays behind the resource's
 * master-data gate.
 */
final class ViewMarketplaceOrder extends ViewRecord
{
    protected static string $resource = MarketplaceOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            MarkMarketplaceOrderPaidAction::make($this->record),
        ];
    }
}
