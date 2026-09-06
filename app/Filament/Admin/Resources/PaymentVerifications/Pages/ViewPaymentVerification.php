<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Pages;

use App\Filament\Admin\Resources\PaymentVerifications\Actions\DecidePaymentVerificationAction;
use App\Filament\Admin\Resources\PaymentVerifications\Actions\RecordPaymentReversalAction;
use App\Filament\Admin\Resources\PaymentVerifications\PaymentVerificationsResource;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\PaymentVerificationDecision;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `PaymentVerificationsResource` — the full detail infolist
 * (`Schemas\PaymentVerificationInfolist`) plus the FIL-04 remediation header
 * actions: 'Setujui'/'Tolak' (visible only while `SUBMITTED`, calling
 * `App\Platform\Payment\VerifyManualPayment::verify()`) and 'Catat
 * Refund'/'Catat Chargeback' (visible only once `VERIFIED`, calling
 * `App\Platform\Payment\ReversalService::record()`). Each action factory
 * owns its own visibility/authorization predicate — this page never
 * switches on the status itself, same convention `ViewCertificate` uses.
 */
final class ViewPaymentVerification extends ViewRecord
{
    protected static string $resource = PaymentVerificationsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var PaymentVerification $verification */
        $verification = $this->getRecord();

        return [
            DecidePaymentVerificationAction::make($verification, PaymentVerificationDecision::Approve),
            DecidePaymentVerificationAction::make($verification, PaymentVerificationDecision::Reject),
            RecordPaymentReversalAction::make($verification, PaymentReversalType::Refund),
            RecordPaymentReversalAction::make($verification, PaymentReversalType::Chargeback),
        ];
    }
}
