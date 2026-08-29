<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

/**
 * "Where does this order's view page live, for the panel I am currently
 * in?" — the one answer the shared order actions redirect to.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * `ReservePlotAction`, `TransitionOrderAction` and
 * `PlotReservationLifecycleActions` all live under `App\Filament\Admin` but
 * are rendered by BOTH `/admin`'s `ViewBookingOrder` and `/operator`'s
 * `ViewCemeteryOrder` (Phase C). Each of them hardcoded
 * `route('filament.admin.resources.pesanan-pemakaman.view', ...)`, which
 * would bounce a successful `cemetery_operator` action into `/admin` — a
 * panel `AdminPanelAccessPolicy` refuses. One shared helper rather than four
 * near-identical inline resolutions, so the four sites cannot drift.
 *
 * ---------------------------------------------------------------------------
 * Resolution and fallback
 * ---------------------------------------------------------------------------
 * `Filament::getCurrentPanel()` (deliberately not
 * `getCurrentOrDefaultPanel()`, which dereferences a possibly-unset default)
 * returns null outside a panel request — a queued job, a console command, a
 * test that never entered a panel. `Panel::getModelResource()` returns null
 * when the current panel registers no resource for `Order` (the `/vendor`
 * panel, for instance). Either way this falls back to `/admin`'s resource,
 * which is the historical behaviour and therefore the safe default: it
 * cannot make any pre-Phase-C call site worse.
 */
final class OrderViewUrl
{
    public static function for(Order $order): string
    {
        $panel = Filament::getCurrentPanel();

        /** @var class-string<\Filament\Resources\Resource>|null $resource */
        $resource = $panel?->getModelResource(Order::class);

        if ($panel !== null && $resource !== null) {
            return $resource::getUrl('view', ['record' => $order->getKey()], panel: $panel->getId());
        }

        return BookingOrderResource::getUrl('view', ['record' => $order->getKey()], panel: 'admin');
    }
}
