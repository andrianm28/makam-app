<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;

/**
 * The job `ReceiveWebhook` dispatches once a delivery reaches `VALIDATED`.
 *
 * What stays here is what can be proved without a database: the job runs on
 * `critical`, and it survives the queue's own serialisation round trip
 * carrying its id.
 *
 * The two claims that need real rows to mean anything moved to
 * `Tests\Feature\Payment\ProcessWebhookEventTest`, where the provider-event
 * fixtures already live:
 *
 *   - AC14 "no payload may enter a queue payload" —
 *     `test_dispatching_the_job_puts_only_the_row_id_on_the_queue`, which
 *     plants a distinctive secret in a real row's encrypted `raw_payload`
 *     and reads the actual serialised queue payload back.
 *   - "never a `PROCESSED` row for work that did not commit" —
 *     `test_the_queued_job_leaves_the_row_validated_when_the_settlement_
 *     throws`.
 *
 * Both were previously asserted here by reading `ProcessProviderEventJob`'s
 * own source: the constructor's parameter list, and `handle()`'s method body
 * scanned for the literal strings `PROCESSED` and `markStatus`. The source
 * scan is why this file is worth reading as a cautionary note. Task 4 moved
 * the claim into `ProcessWebhookEvent` and Task 5 wired the settlement, so
 * running this job now does mark a row `PROCESSED` — the literal strings
 * simply live one call away, in the collaborator. The structural assertion
 * stayed green throughout while the behaviour it named inverted, and
 * `ProcessWebhookEventTest::test_the_queued_job_performs_the_claim` has been
 * asserting the opposite next door.
 */
final class ProcessProviderEventJobTest extends TestCase
{
    public function test_it_is_a_queued_job_on_the_critical_queue(): void
    {
        $job = new ProcessProviderEventJob('01996f4e-0000-7000-8000-000000000000');

        $this->assertInstanceOf(ShouldQueue::class, $job);

        // `queue-and-outbox.md` §2 / `AGENTS.md`: "Imports/reports/media must
        // not starve critical or urgent queues." Set in the constructor rather
        // than at the dispatch site, so the queue travels with the job.
        $this->assertSame(OutboxQueueName::Critical->value, $job->queue);
    }

    /**
     * The round trip is the point: a worker rebuilds the job from the
     * serialised payload, so anything the job needs must survive `serialize()`
     * and anything it does not need must not be in there to survive. Both the
     * id and the queue assignment come back.
     */
    public function test_the_id_and_its_queue_survive_the_serialisation_a_worker_replays(): void
    {
        $job = new ProcessProviderEventJob('01996f4e-0000-7000-8000-000000000000');

        $restored = unserialize(serialize($job));

        $this->assertInstanceOf(ProcessProviderEventJob::class, $restored);
        $this->assertSame('01996f4e-0000-7000-8000-000000000000', $restored->providerEventId);
        $this->assertSame(OutboxQueueName::Critical->value, $restored->queue);
    }
}
