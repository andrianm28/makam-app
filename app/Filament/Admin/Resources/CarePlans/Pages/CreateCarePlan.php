<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CarePlans\Pages;

use App\Domain\CareSubscription\Actions\CreateCarePlan as CreateCarePlanAction;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Filament\Admin\Resources\CarePlans\CarePlansResource;
use App\Filament\Shared\PanelFailure;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Create page for `CarePlansResource`. Delegates to domain Action.
 */
final class CreateCarePlan extends CreateRecord
{
    protected static string $resource = CarePlansResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(CreateCarePlanAction::class)(
                name: $data['name'],
                productCode: $data['product_code'],
                frequency: CarePlanFrequency::from($data['frequency']),
                priceMinor: Money::fromDecimal((string) $data['price_minor']),
                vendorId: $data['vendor_id'] ?? null,
                checklistTemplate: $data['checklist_template'] ?? null,
                actorRef: (string) auth()->id(),
                actorRole: CarePlansResource::auditRoleFor(app(ActorContext::class)),
            );
        } catch (Throwable $exception) {
            PanelFailure::notify($exception, 'Gagal membuat rencana perawatan');

            throw $exception;
        }
    }
}
