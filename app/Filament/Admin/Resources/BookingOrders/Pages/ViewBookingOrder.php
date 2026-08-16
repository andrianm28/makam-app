<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

final class ViewBookingOrder extends ViewRecord
{
    protected static string $resource = BookingOrderResource::class;

    /**
     * One header action per allowed outgoing edge of the record's current
     * status, all built by the single dynamic factory
     * (`Actions\TransitionOrderAction`), plus the P3 reservation actions:
     * 'Reservasi Plot' (its factory owns the state/actor gates, the plot
     * select and the `ReservePlot` dispatch) and, when an active
     * reservation exists, the three lifecycle actions (each factory owns
     * its per-edge visibility and dispatch). This page never switches on
     * the status itself.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        /** @var Order $record */
        $record = $this->getRecord();

        foreach (OrderTransition::allowedFrom($record->status()) as $to) {
            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $record);
        }

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
