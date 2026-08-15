<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Actions\IssueQuote;
use Carbon\CarbonInterface;
use InvalidArgumentException;

final readonly class IssueOrderQuote
{
    public function __construct(
        private ComposeQuoteLinesFromBookingDraft $composeLines,
        private IssueQuote $issueQuote,
    ) {}

    public function __invoke(
        Order $order,
        CarbonInterface $expiresAt,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        $draft = $order->bookingDraft;

        if (! $draft instanceof BookingDraft) {
            throw new InvalidArgumentException(
                'Order has no booking draft to compose quote lines from.'
            );
        }

        $lines = ($this->composeLines)($draft);
        ($this->issueQuote)($order, $lines, $expiresAt, $actorRef, $actorRole);

        return app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::PENAWARAN_TERKIRIM,
            $actorRef,
            $actorRole,
            $reason,
            $metadata,
        );
    }
}
