<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Pages;

use App\Filament\Admin\Resources\MarketplaceOrders\MarketplaceOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `MarketplaceOrderResource` — the `CemeteryResource`
 * ground-truth shape (`Pages\ListCemeteries`), with NO header actions:
 * orders are created by customers through the marketplace, never by an
 * admin, so there is no `CreateAction` here — the only write surface is the
 * finance-gated mark-paid action on the view page.
 */
final class ListMarketplaceOrders extends ListRecords
{
    protected static string $resource = MarketplaceOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [];
    }
}
