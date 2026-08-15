<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
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
     * (`Actions\TransitionOrderAction`). The factory owns label, colour,
     * reason form, confirmation modal, authorization and the domain-Action
     * enforcement path — this page never switches on the status itself.
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

        return $actions;
    }
}
