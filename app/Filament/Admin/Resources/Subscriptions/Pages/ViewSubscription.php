<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Domain\CareSubscription\Models\Subscription;
use App\Filament\Admin\Resources\Subscriptions\Actions\CancelSubscriptionAction;
use App\Filament\Admin\Resources\Subscriptions\Actions\PauseSubscriptionAction;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `SubscriptionsResource` with header actions for pause and cancel.
 */
final class ViewSubscription extends ViewRecord
{
    protected static string $resource = SubscriptionsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Subscription $subscription */
        $subscription = $this->getRecord();

        return [
            PauseSubscriptionAction::make($subscription),
            CancelSubscriptionAction::make($subscription),
        ];
    }
}
