<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Renewal\Models\Renewal;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplacePaymentStateBadge;
use App\Filament\Admin\Resources\RenewalOrders\RenewalStatusBadge;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Widgets\Widget;

/**
 * ADM-001 (`.kiro/specs/admin-operations/requirements.md` AC1) — the
 * "transaction" and "order status" dashboard modules: a real status
 * breakdown across the three order-bearing domains this panel already
 * manages (`BookingOrders`, `MarketplaceOrders`, `RenewalOrders`).
 *
 * Deliberately a plain `Widgets\Widget` with its own view rather than a
 * `TableWidget`: a `TableWidget` binds one Eloquent query paginating real
 * model rows, and there is no single query across three different tables/
 * status columns that stays a valid Eloquent record set. The breakdown here
 * is a GROUP BY count per domain, not a list of records, so a bespoke
 * summary view is the honest shape for it rather than forcing it through a
 * component built for row-level tables.
 *
 * Reuses each Resource's own status-badge class
 * (`BookingOrderStatusBadge`, `MarketplacePaymentStateBadge`,
 * `RenewalStatusBadge`) for colour/label — never a second copy of the
 * status → colour/label mapping (`AGENTS.md` §Documentation, design-system.md
 * §3.7 "resolve status -> intent in ONE place").
 *
 * Gated identically to `BookingOrderResource`/`MarketplaceOrderResource`/
 * `RenewalOrderResource::canAccess()` — the same
 * `MasterDataAdminAuthorizerContract` all three already use, so this widget
 * never shows a back-office role a breakdown it could not already reach
 * through the three Resources individually.
 */
final class OrderStatusOverviewWidget extends Widget
{
    protected string $view = 'filament.admin.widgets.order-status-overview';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $booking = $this->bookingBreakdown();
        $marketplace = $this->marketplaceBreakdown();
        $renewal = $this->renewalBreakdown();

        $total = array_sum(array_column($booking, 'count'))
            + array_sum(array_column($marketplace, 'count'))
            + array_sum(array_column($renewal, 'count'));

        return [
            'totalTransactions' => $total,
            'bookingRows' => $booking,
            'marketplaceRows' => $marketplace,
            'renewalRows' => $renewal,
        ];
    }

    /**
     * @return list<array{label: string, color: string, count: int}>
     */
    private function bookingBreakdown(): array
    {
        $counts = Order::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $rows = [];

        foreach ($counts as $status => $count) {
            $case = OrderStatus::from((string) $status);

            $rows[] = [
                'label' => BookingOrderStatusBadge::label($case),
                'color' => BookingOrderStatusBadge::color($case),
                'count' => (int) $count,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, color: string, count: int}>
     */
    private function marketplaceBreakdown(): array
    {
        $counts = MarketplaceOrder::query()
            ->selectRaw('payment_state, count(*) as aggregate')
            ->groupBy('payment_state')
            ->pluck('aggregate', 'payment_state');

        $rows = [];

        foreach ($counts as $state => $count) {
            $rows[] = [
                'label' => MarketplacePaymentStateBadge::label((string) $state),
                'color' => MarketplacePaymentStateBadge::color((string) $state),
                'count' => (int) $count,
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{label: string, color: string, count: int}>
     */
    private function renewalBreakdown(): array
    {
        $counts = Renewal::query()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $rows = [];

        foreach ($counts as $status => $count) {
            $rows[] = [
                'label' => RenewalStatusBadge::label((string) $status),
                'color' => RenewalStatusBadge::color((string) $status),
                'count' => (int) $count,
            ];
        }

        return $rows;
    }
}
