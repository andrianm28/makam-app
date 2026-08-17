<?php

declare(strict_types=1);

namespace App\Domain\VendorFulfillment\Actions;

use App\Domain\VendorFulfillment\Models\ServiceAcceptance;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\VendorFulfillmentAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Carbon\CarbonImmutable;

/**
 * Records customer acceptance of a completed work order, optionally
 * including a satisfaction rating (1–5).
 */
final readonly class AcceptService
{
    public function __invoke(
        WorkOrder $workOrder,
        string $customerId,
        ?int $rating,
        ?string $notes,
    ): ServiceAcceptance {
        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            throw new \InvalidArgumentException(
                "rating must be between 1 and 5, got [{$rating}]."
            );
        }

        return Audit::wrap(
            mutation: function () use ($workOrder, $customerId, $rating, $notes): ServiceAcceptance {
                return ServiceAcceptance::query()->create([
                    'work_order_id' => $workOrder->getKey(),
                    'customer_id' => $customerId,
                    'accepted_at' => CarbonImmutable::now(),
                    'rating' => $rating,
                    'notes' => $notes,
                ]);
            },
            action: VendorFulfillmentAuditActions::SERVICE_ACCEPTED,
            subject: new AuditSubject('work_order', $workOrder->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $customerId,
            actorRole: 'customer',
            source: AuditSource::Api,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}
