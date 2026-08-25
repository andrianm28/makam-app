<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\Quotation\Models\Quote;
use Carbon\CarbonImmutable;

final readonly class MarkOrderPaid
{
    public function __construct(
        private ApplyPaidEffects $applyPaidEffects,
    ) {}

    public function __invoke(
        Order $order,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
    ): Order {
        $quote = Quote::currentFor($order);

        if (! $quote instanceof Quote) {
            throw PaidAmountDoesNotMatchQuoteException::forMissingAcceptedQuote(
                (string) $order->getKey()
            );
        }

        return ($this->applyPaidEffects)(
            $order,
            new PaidTrigger(
                source: PaidTriggerSource::ManualVerification,
                sourceId: "manual:{$actorRef}",
                businessKey: "manual_paid:{$order->reference}",
                amount: $quote->totalMinor(),
                currency: $quote->currency,
                occurredAt: CarbonImmutable::now(),
                actorRef: $actorRef,
                actorRole: $actorRole,
            ),
        );
    }
}
