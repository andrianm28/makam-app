<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Pages;

use App\Filament\Admin\Resources\PaymentVerifications\PaymentVerificationsResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `PaymentVerificationsResource` — the full detail infolist
 * (`Schemas\PaymentVerificationInfolist`). No header action: no 'Edit'
 * action is registered, and no decision action either — deciding a
 * verification stays on the existing, separate write path
 * (`App\Platform\Payment\Http\Controllers\VerifyManualPaymentController`),
 * which this admin surface does not call.
 */
final class ViewPaymentVerification extends ViewRecord
{
    protected static string $resource = PaymentVerificationsResource::class;
}
