<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment;

use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The job `ReceiveWebhook` dispatches once a delivery reaches `VALIDATED`.
 *
 * Its apply logic belongs to Task 4 (`ProcessWebhookEvent` /
 * `ApplyWebhookEffect`), so `handle()` is deliberately empty. What this task
 * owns, and what is asserted here, are the two properties that must survive
 * Task 4 filling that body in: it runs on `critical`, and it carries an id
 * rather than a model or a payload.
 *
 * The dispatch itself is NOT TESTED end to end — no delivery can reach
 * `VALIDATED` while `payment_sessions` is uncreatable under Wave 1b ruling
 * 1b-L3-01, and no session fixture is fabricated to manufacture one. Recorded
 * as such in the Task 3 report.
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

    public function test_it_carries_only_a_row_id_so_no_payload_or_credential_can_enter_a_queue_payload(): void
    {
        $constructor = (new ReflectionClass(ProcessProviderEventJob::class))->getConstructor();

        $this->assertNotNull($constructor);

        $parameters = $constructor->getParameters();

        $this->assertCount(1, $parameters, 'the job must take exactly one constructor argument');
        $this->assertSame('providerEventId', $parameters[0]->getName());
        $this->assertSame('string', (string) $parameters[0]->getType());

        $job = new ProcessProviderEventJob('01996f4e-0000-7000-8000-000000000000');

        $this->assertStringNotContainsString(
            'raw_payload',
            (string) json_encode(get_object_vars($job)),
        );
    }

    public function test_it_does_not_claim_to_have_processed_anything(): void
    {
        // A shell that marked a row `PROCESSED` would write a false claim into
        // the append-only table design.md calls the replay source of truth —
        // and append-only means it could not be quietly corrected later.
        $body = (new ReflectionClass(ProcessProviderEventJob::class))
            ->getMethod('handle');

        $source = file(__DIR__.'/../../../../app/Platform/Payment/Jobs/ProcessProviderEventJob.php');
        $lines = array_slice(
            $source,
            $body->getStartLine() - 1,
            $body->getEndLine() - $body->getStartLine() + 1
        );

        $this->assertStringNotContainsString('PROCESSED', implode('', $lines));
        $this->assertStringNotContainsString('markStatus', implode('', $lines));
    }
}
