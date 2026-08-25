<?php

declare(strict_types=1);

namespace App\Domain\CareSubscription\Actions;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\CarePlanStatus;
use App\Domain\CareSubscription\CareSubscriptionAuditActions;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Illuminate\Support\Str;

/**
 * Creates a care plan — the only writer for `care_plans` rows.
 * Audited `CARE_PLAN_CREATED`.
 */
final readonly class CreateCarePlan
{
    public function __invoke(
        string $name,
        string $productCode,
        CarePlanFrequency $frequency,
        int $priceMinor,
        string $currency = 'IDR',
        ?string $description = null,
        ?string $vendorId = null,
        ?array $checklistTemplate = null,
        string $actorRef = 'system',
        string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
    ): CarePlan {
        return Audit::wrap(
            mutation: function () use ($name, $productCode, $frequency, $priceMinor, $currency, $description, $vendorId, $checklistTemplate): CarePlan {
                return CarePlan::query()->create([
                    'reference' => 'CP-'.Str::upper(Str::random(8)),
                    'name' => $name,
                    'description' => $description,
                    'product_code' => $productCode,
                    'frequency' => $frequency->value,
                    'price_minor' => $priceMinor,
                    'currency' => $currency,
                    'vendor_id' => $vendorId,
                    'checklist_template' => $checklistTemplate ?? [],
                    'status' => CarePlanStatus::Active->value,
                ]);
            },
            action: CareSubscriptionAuditActions::CARE_PLAN_CREATED,
            subject: fn (CarePlan $plan): AuditSubject => new AuditSubject(
                'care_plan',
                $plan->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
