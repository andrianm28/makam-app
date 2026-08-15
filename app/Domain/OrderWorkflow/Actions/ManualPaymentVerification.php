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

final readonly class ManualPaymentVerification
{
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        string $verificationNote,
        array $metadata = [],
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: fn (): OrderStatusEvent => app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
                $actorRef,
                $actorRole,
                $verificationNote,
                $metadata,
            ),
            action: OrderWorkflowAuditActions::MANUAL_PAYMENT_VERIFICATION_STARTED,
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Panel,
            reason: $verificationNote,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: $metadata,
        );
    }
}
