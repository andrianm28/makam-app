<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;

/**
 * The admin accepts a paid order: `DIBAYAR_MENUNGGU_KONFIRMASI` ->
 * `DIKONFIRMASI` — Stage R1 / Tahap 2 of the pay-in-full-upfront flow.
 *
 * A thin delegation to `Actions\RecordOrderStatusChange`, deliberately the
 * same shape as `Actions\VerifyOrder` and every other forward-path Action in
 * this namespace. It is the acceptance half of the pair whose refusal half is
 * `Actions\RefusePaidOrder` — and the asymmetry between them is the design,
 * not an oversight. Accepting costs nothing beyond the status change: the
 * money keeps the meaning it already had. Refusing creates a debt, so it
 * carries a precondition, a mandatory amount and a mandatory reason.
 *
 * No reason is required here for the same reason none is required to verify
 * an order: `OrderStatus::DIKONFIRMASI->requiresReason()` is false and
 * `DIKONFIRMASI` is not on `SensitiveActions::ACTIONS`. A note may still be
 * passed and is recorded on both the status event and the audit row.
 */
final readonly class ConfirmPaidOrder
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DIKONFIRMASI,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
