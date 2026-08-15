<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Quotation\Actions\AcceptQuote;
use App\Domain\Quotation\Models\Quote;
use InvalidArgumentException;

final readonly class RecordBuyerApproval
{
    public function __construct(
        private AcceptQuote $acceptQuote,
    ) {}

    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        $quote = Quote::currentFor($order);

        if (! $quote instanceof Quote) {
            throw new InvalidArgumentException('Order has no current quote to accept.');
        }

        ($this->acceptQuote)($quote, $actorRef);

        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DISETUJUI_PEMESAN,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
