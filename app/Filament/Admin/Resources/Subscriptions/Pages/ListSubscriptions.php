<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Pages;

use App\Filament\Admin\Resources\Subscriptions\SubscriptionsResource;
use Filament\Resources\Pages\ListRecords;

final class ListSubscriptions extends ListRecords
{
    protected static string $resource = SubscriptionsResource::class;
}
