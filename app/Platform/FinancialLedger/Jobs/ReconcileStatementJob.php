<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Jobs;

use App\Platform\Correlation\Concerns\CarriesCorrelationId;
use App\Platform\FinancialLedger\Actions\RunReconciliation;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\Outbox\OutboxQueueName;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs `Actions\RunReconciliation` on the `reports` queue.
 *
 * ---------------------------------------------------------------------------
 * Why `reports`, and what that guarantees
 * ---------------------------------------------------------------------------
 * `OutboxQueueName::Reports` is the existing `'reports'` case named by
 * `docs/architecture/queue-and-outbox.md` §2 — no new queue name is invented
 * here. A reconciliation run is a scheduled, batch, after-the-fact comparison:
 * it is never on the path of a customer payment, a webhook, or a notification,
 * so it belongs on a low-priority lane.
 *
 * That is what "must never starve `critical`, `urgent` or `notifications`"
 * means HERE, and it is worth being precise about what this file can and cannot
 * promise. Choosing the queue is all a job class can do; the ACTUAL starvation
 * guarantee is a worker-supervisor property (Horizon supervisor pools, Sprint
 * 6), and no Horizon supervisor configuration exists in this repository yet.
 * `platform-outbox`'s own AC8 note records the same honest limit. So: this job
 * cannot occupy a priority lane, and `ReconcileStatementJobTest` asserts that
 * by name — but "reports never delays critical under load" remains unproven
 * until those pools exist, and must not be reported as tested.
 *
 * ---------------------------------------------------------------------------
 * Shape follows `PublishOutboxEventJob`
 * ---------------------------------------------------------------------------
 * That is the only other real `ShouldQueue` class in the codebase, so this one
 * follows it: the same four traits, and correlation carried explicitly through
 * `CarriesCorrelationId` — captured in the constructor (which still runs in the
 * dispatching process) and restored as the first line of `handle()` (which runs
 * in the worker's own fresh context). See that trait's doc block; it is
 * deliberately opt-in and does nothing on its own.
 *
 * It differs in one way, deliberately: `PublishOutboxEventJob` takes a row id
 * and re-fetches, because its subject is a database row that may have moved on.
 * A `ProviderStatement` is not a database row at all — there is no
 * provider-statement table and no live adapter to re-fetch from (Task 5 brief,
 * "NOT TESTED / out of scope") — so the immutable value object is carried on
 * the payload. `null` means the statement could not be fetched, which
 * `RunReconciliation` records as `statement_missing` rather than as a zero.
 */
final class ReconcileStatementJob implements ShouldQueue
{
    use CarriesCorrelationId;
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $period,
        public readonly string $entityRef,
        public readonly ?ProviderStatement $statement = null,
    ) {
        $this->captureCorrelationContext();
        $this->onQueue(OutboxQueueName::Reports->value);
    }

    public function handle(RunReconciliation $runReconciliation): void
    {
        $this->restoreCorrelationContext();

        $runReconciliation->run(
            period: $this->period,
            entityRef: $this->entityRef,
            statement: $this->statement,
        );
    }

    /**
     * The queue this job is pinned to. Exposed so a test can assert the
     * routing decision by name rather than by reaching into `Queueable`'s
     * internals.
     */
    public static function queueName(): string
    {
        return OutboxQueueName::Reports->value;
    }
}
