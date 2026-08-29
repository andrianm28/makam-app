<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders\Pages;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\Actions\IssueQuoteFromReservedPlotAction;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The `/operator` order view page. Its header actions are the SAME factories
 * `App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder` builds,
 * in the same order — the roadmap's "reused unchanged in mechanism". Each
 * factory carries its own order-state gate (`->visible()`) and its own actor
 * gate (`->authorize()`), and each re-checks the actor gate inside its run
 * path, so nothing panel-specific belongs here.
 *
 * The record itself arrives already scoped: Filament resolves `{record}`
 * through `CemeteryOrderResource::getEloquentQuery()`, so an order belonging
 * to a cemetery this actor holds no grant for 404s before any action is
 * built.
 *
 * @see ViewBookingOrder
 */
final class ViewCemeteryOrder extends ViewRecord
{
    protected static string $resource = CemeteryOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        /** @var Order $record */
        $record = $this->getRecord();

        foreach (OrderTransition::allowedFrom($record->status()) as $to) {
            // The DIVERIFIKASI -> PENAWARAN_TERKIRIM edge (Phase F) is
            // conditional on a plot reservation and is rendered by
            // IssueQuoteFromReservedPlotAction below instead — the generic
            // per-edge factory has no way to express that condition and
            // would otherwise dispatch to the WRONG action (IssueOrderQuote,
            // no reservation check). See that class's own doc block.
            if ($record->status() === OrderStatus::DIVERIFIKASI && $to === OrderStatus::PENAWARAN_TERKIRIM->value) {
                continue;
            }

            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $record);
        }

        $actions[] = IssueQuoteFromReservedPlotAction::make($record);
        $actions[] = ReservePlotAction::make($record);

        $reservation = PlotReservation::activeForOrder($record);

        if ($reservation !== null) {
            $actions[] = PlotReservationLifecycleActions::confirm($record, $reservation);
            $actions[] = PlotReservationLifecycleActions::release($record, $reservation);
            $actions[] = PlotReservationLifecycleActions::expire($record, $reservation);
        }

        return $actions;
    }
}
