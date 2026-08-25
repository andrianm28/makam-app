<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Pages;

use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\EditRecord;

/**
 * Non-financial edit surface — the plan's Task 4 brief says orders are
 * append-only, so this page exists for a future document-attachment phase,
 * not to mutate `orders` columns.
 *
 * `mutateFormDataBeforeSave()` keeps the brief's intended shape: no
 * writable columns, so every submit payload is emptied before it could
 * reach the model. That alone is not enough, because
 * `App\Domain\OrderWorkflow\Models\Order::update()` throws unconditionally
 * (`OrderIsGuardedException`) — an empty-array save would still throw — so
 * `getSaveFormAction()` hides the save button entirely, exactly as the
 * brief allows ("If `EditRecord` cannot work append-only ... override
 * `getSaveFormAction()` to hide save"). The page renders read-only.
 */
final class EditBookingOrder extends EditRecord
{
    protected static string $resource = BookingOrderResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        return array_intersect_key($data, array_flip([])); // no writable columns: orders are append-only
    }

    protected function getSaveFormAction(): Action
    {
        return parent::getSaveFormAction()->hidden();
    }
}
