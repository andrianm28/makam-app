<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notification;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Notification\DemoDataSuppression;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Outbox\OutboxPublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `SubmitBookingDraft`'s own MASUK-status outbox event is the real,
 * existing, order-lifecycle trigger `DispatchOrderNotifications` bridges —
 * this proves the suppression guard added to that listener genuinely
 * prevents the queued job, using a real order submission rather than a
 * synthetic outbox event.
 *
 * A real booking submission alone does NOT fire `OutboxEventPublished` —
 * it only writes an `outbox_events` row. Publishing is a separate step:
 * `OutboxPublisher::dispatchOne()` (the only thing that dispatches
 * `PublishOutboxEventJob`, whose `handle()` fires `OutboxEventPublished`
 * synchronously) is invoked exclusively by `OutboxPublisher::publishBatch()`,
 * whose only production caller is the `outbox:publish` scheduled command
 * (`routes/console.php`, every minute, its own process) — see
 * `tests/Feature/Outbox/OutboxBookingDraftPublicationTest.php` for the same
 * pattern. This test therefore drains the outbox in-process the same way
 * that test does, via `(new OutboxPublisher)->publishBatch()`, rather than
 * waiting on the scheduler. `PublishOutboxEventJob` itself is left real
 * (not faked) so it runs synchronously under the testing
 * `QUEUE_CONNECTION=sync` and fires `OutboxEventPublished` in this same
 * process — only `ConsumeOutboxNotificationJob`, the job under test, is
 * faked.
 */
final class DemoDataSuppressionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'OutboxPublisher::claim() requires real Postgres row locking '.
                '(SELECT ... FOR UPDATE SKIP LOCKED). Run with DB_CONNECTION=pgsql, as CI does.'
            );
        }
    }

    private function publishPendingEvents(): int
    {
        return (new OutboxPublisher)->publishBatch();
    }

    private function realBookingSubmission(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                ServiceCode::BASIC_CODES,
            ),
        ], 'idem-discovery-'.$draft->id);

        app(SubmitBookingDraft::class)($draft, 'idem-submit-'.$draft->id);
    }

    public function test_a_real_order_submission_during_suppression_never_queues_the_notification_job(): void
    {
        // Scoped fake: `PublishOutboxEventJob` must actually run
        // (synchronously, via the testing QUEUE_CONNECTION=sync) so it
        // fires the OutboxEventPublished event these listeners react to.
        // A bare Queue::fake() intercepts it as well and the listeners
        // never run at all, making this assertion vacuously true either
        // way.
        Queue::fake([ConsumeOutboxNotificationJob::class]);

        DemoDataSuppression::run(function (): void {
            $this->realBookingSubmission();
            $this->publishPendingEvents();
        });

        Queue::assertNotPushed(ConsumeOutboxNotificationJob::class);
    }

    public function test_the_same_submission_outside_suppression_queues_the_notification_job_normally(): void
    {
        Queue::fake([ConsumeOutboxNotificationJob::class]);

        $this->realBookingSubmission();
        $this->publishPendingEvents();

        Queue::assertPushed(ConsumeOutboxNotificationJob::class);
    }
}
