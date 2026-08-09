<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Platform\Outbox\Jobs\PublishOutboxEventJob;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Outbox\OutboxQueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Closes `docs/planning/sprint-plan.md` S3-T11's first self-flagged gap:
 * "AC1 is proved only against a `tests/Fixtures/` aggregate, not a real
 * domain mutation (none exists yet)." One now exists. Every row this test
 * publishes was written by `app/Domain/Booking/Actions/**` inside that
 * Action's own transaction, triggered the same way the real
 * `BookingWizard` Livewire component triggers it.
 *
 * What this does NOT prove, deliberately: cross-session `SKIP LOCKED`
 * contention (S3-T11 gap 2 — `RefreshDatabase`'s per-test transaction makes
 * a genuinely separate database session unable to see these uncommitted
 * rows; that limit is structural and is ledgered, not fixed here).
 */
final class OutboxBookingDraftPublicationTest extends TestCase
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

    public function test_a_real_booking_mutation_produces_an_event_the_publisher_claims_and_dispatches(): void
    {
        Queue::fake();

        $draft = (new StartBookingDraft)(userId: null);

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->sole();
        $this->assertNull($event->dispatched_at, 'A freshly recorded event must start undispatched.');

        $this->publishPendingEvents();

        Queue::assertPushed(PublishOutboxEventJob::class);

        $this->assertNotNull(
            $event->fresh()->dispatched_at,
            'The publisher must mark a claimed event dispatched.'
        );
        $this->assertSame((string) $draft->id, $event->aggregate_id);
    }

    public function test_several_steps_of_one_journey_each_publish_independently(): void
    {
        Queue::fake();

        $draft = (new StartBookingDraft)(userId: null);
        $saved = (new SaveBookingDraftStep)($draft, 1, ['city_code' => 'JAKARTA'], 'step-1-key');

        $this->assertSame(
            2,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'One draft-started plus one step-saved event should be pending.'
        );

        $this->publishPendingEvents();

        Queue::assertPushed(PublishOutboxEventJob::class, 2);

        $this->assertSame(
            0,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'Every pending event must have been claimed and dispatched.'
        );
        // `booking_drafts.version` DEFAULTS TO 1, so the first accepted
        // save leaves it at 2. See Task 2's corrected implementer note.
        $this->assertSame(2, $saved->version);
    }

    public function test_booking_draft_events_route_to_the_default_queue(): void
    {
        // Deliberate: both names are unmapped in `OutboxQueueRouter::ROUTES`,
        // and that class's doc block says an unmapped event correctly falls
        // back to `default` rather than being guessed at. This pins that
        // decision so a future edit to ROUTES cannot change it silently.
        $this->assertSame(
            OutboxQueueName::Default,
            OutboxQueueRouter::routeFor('booking.draft_started.v1')
        );
        $this->assertSame(
            OutboxQueueName::Default,
            OutboxQueueRouter::routeFor('booking.draft_step_saved.v1')
        );
    }

    private function publishPendingEvents(): int
    {
        return (new OutboxPublisher)->publishBatch();
    }
}
