<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderWorkflowAuditActions;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

final readonly class GrantOrderPaymentOpening
{
    public function __construct(
        private GrantScopeAssignment $grantScope,
    ) {}

    public function __invoke(
        Order $order,
        int|string $granteeActorIdentifier,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: function () use ($order, $granteeActorIdentifier, $actorRef, $actorRole, $reason, $metadata): OrderStatusEvent {
                ($this->grantScope)(
                    actorIdentifier: $granteeActorIdentifier,
                    entityType: ScopeEntityType::ORDER,
                    entityId: (string) $order->getKey(),
                    grantLevel: null,
                    reason: $reason ?? 'Order payment opening authorized from the admin panel.',
                    grantedBy: $actorRef,
                );

                return app(RecordOrderStatusChange::class)(
                    $order,
                    OrderStatus::MENUNGGU_PEMBAYARAN,
                    $actorRef,
                    $actorRole,
                    $reason,
                    $metadata,
                );
            },
            action: OrderWorkflowAuditActions::PAYMENT_OPENING_AUTHORIZED,
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: $metadata,
        );
    }
}
