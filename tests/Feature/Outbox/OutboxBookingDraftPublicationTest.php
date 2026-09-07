<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Outbox\Events\OutboxEventPublished;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxPublisher;
use App\Platform\Outbox\OutboxQueueName;
use App\Platform\Outbox\OutboxQueueRouter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

    /**
     * QUE-04: `dispatched_at` is stamped by `PublishOutboxEventJob::handle()`
     * once it has actually published, not by `OutboxPublisher::dispatchOne()`
     * the moment the job is handed to the queue driver — see that job's own
     * class doc block. `Queue::fake()` would intercept the dispatch and the
     * job would never run at all, so `dispatched_at` could never be
     * observed set; `Event::fake()` instead lets the real (test env:
     * `QUEUE_CONNECTION=sync`) job run and fire `OutboxEventPublished`,
     * which is this test's actual "did this publish" proof.
     */
    public function test_a_real_booking_mutation_produces_an_event_the_publisher_claims_and_dispatches(): void
    {
        Event::fake([OutboxEventPublished::class]);

        $draft = (new StartBookingDraft)(userId: null);

        $event = OutboxEvent::query()->where('event_name', 'booking.draft_started.v1')->sole();
        $this->assertNull($event->dispatched_at, 'A freshly recorded event must start undispatched.');

        $this->publishPendingEvents();

        Event::assertDispatched(OutboxEventPublished::class);

        $this->assertNotNull(
            $event->fresh()->dispatched_at,
            'The publisher must mark a claimed event dispatched.'
        );
        $this->assertSame((string) $draft->id, $event->aggregate_id);
    }

    public function test_several_steps_of_one_journey_each_publish_independently(): void
    {
        // See test_a_real_booking_mutation_produces_an_event_the_publisher_
        // claims_and_dispatches()'s doc block for why this needs the real
        // sync-queue job to run rather than `Queue::fake()`.
        Event::fake([OutboxEventPublished::class]);

        $draft = (new StartBookingDraft)(userId: null);

        // DISCOVERY validates city, cemetery, service type and services as
        // ONE payload — this used to pass the old bare-city step-1 payload
        // (`['city_code' => 'JAKARTA']`), which the merged step rejects. This
        // file is pgsql-only (see `setUp()`), so a SQLite-only run never
        // executed it.
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => ServiceCode::DOCUMENT_PROCESSING, 'quantity' => 1],
                ['code' => ServiceCode::GRAVE_DIGGING, 'quantity' => 1],
            ],
        ], 'step-1-key');

        $this->assertSame(
            2,
            OutboxEvent::query()->whereNull('dispatched_at')->count(),
            'One draft-started plus one step-saved event should be pending.'
        );

        $this->publishPendingEvents();

        Event::assertDispatched(OutboxEventPublished::class, 2);

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
