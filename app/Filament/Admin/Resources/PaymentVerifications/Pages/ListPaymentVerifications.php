<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Pages;

use App\Filament\Admin\Resources\PaymentVerifications\PaymentVerificationsResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `PaymentVerificationsResource`. No header action: this
 * resource offers no write path at all (see the resource's own class-level
 * doc block), so there is nothing to mount a 'Create' button for.
 */
final class ListPaymentVerifications extends ListRecords
{
    protected static string $resource = PaymentVerificationsResource::class;
}
