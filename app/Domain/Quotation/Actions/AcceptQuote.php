<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Actions;

use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * AC8, acceptance half — Task 4 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 * Accepts an issued quote version: the version is re-read under
 * `lockForUpdate()`, then moved through `Quote::accept()`, the model door
 * that itself refuses a superseded/accepted version and an EXPIRED one
 * (expiry evaluated lazily and authoritatively at guard time —
 * `task-4-brief.md` Q5). The caller's instance is synced to the accepted
 * row, and `quote.accepted.v1` is emitted in the same transaction as the
 * state mutation.
 *
 * Acceptance is a property of the QUOTE VERSION: this Action never touches
 * `orders.status`, so superseding an accepted version later never moves the
 * order backward (pinned by `IssueQuoteTest::test_superseding_an_accepted_
 * quote_does_not_change_the_orders_status`).
 */
final readonly class AcceptQuote
{
    public function __invoke(Quote $quote, string $actorRef): Quote
    {
        return DB::transaction(function () use ($quote, $actorRef): Quote {
            /** @var Quote $current */
            $current = Quote::query()->lockForUpdate()->findOrFail($quote->getKey());

            $current->accept(CarbonImmutable::now(), $actorRef);

            // Same precedent as `RecordOrderStatusChange::record()`: without
            // this the caller's `$quote` still reports the pre-acceptance
            // status, and the obvious next line — `if ($quote->status() ===
            // 'ACCEPTED')` — would silently read stale state.
            if ($quote !== $current) {
                $quote->setRawAttributes($current->getAttributes(), true);
            }

            // `event-catalog.md:18` — a catalogued event, not invented here.
            // References only: no amounts, no restricted data.
            Outbox::record(
                eventName: 'quote.accepted.v1',
                eventVersion: 1,
                aggregateType: 'quote',
                aggregateId: $current->getKey(),
                data: [
                    'quote_id' => $current->getKey(),
                    'order_id' => $current->order_id,
                    'version_number' => $current->version_number,
                    'status' => QuoteStatus::ACCEPTED->value,
                ],
                classification: OutboxClassification::Internal,
                idempotencyKey: "quote_accepted:{$current->getKey()}",
            );

            return $quote;
        });
    }
}
