<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Observability\SpineDegradedException;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * `spine:watchdog` — see its own doc block for why these three signals and
 * why each is checked independently.
 *
 * `outbox_events`/`failed_jobs` fixtures below are plain `DB::table()`
 * inserts — neither table carries the runtime write guard
 * `notification_deliveries` does. A `notification_deliveries` fixture,
 * though, MUST go through the real pipeline
 * (`ConsumeOutboxNotificationJob::dispatchSync()`, mirroring
 * `Tests\Feature\Notification\NotificationDispatchPipelineTest`'s own
 * fixture helpers): `NotificationDeliveryWriteGuard` rejects any write to
 * that table from outside `Actions\DispatchNotification` at the connection
 * level (AC9), and its own `withWritesUnlocked()` refuses to run unless
 * `Actions\DispatchNotification` is genuinely on the call stack — there is
 * no test-side escape hatch, deliberately. Not running
 * `SendNotificationChannelJob` afterward leaves the row `QUEUED`, exactly
 * the state this watchdog looks for.
 */
final class SpineWatchdogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_healthy_and_exits_zero_when_nothing_is_stale(): void
    {
        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    public function test_it_detects_a_stale_undispatched_outbox_event_and_reports_it(): void
    {
        $handler = Mockery::mock(ExceptionHandler::class);
        $handler->shouldReceive('report')->once()->with(Mockery::type(SpineDegradedException::class));
        $this->app->instance(ExceptionHandler::class, $handler);

        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_name' => 'payment.received.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '1',
            'payload' => json_encode([]),
            'classification' => 'INTERNAL',
            'occurred_at' => now()->subMinutes(10),
            'available_at' => now()->subMinutes(10),
            'attempt_count' => 0,
            'dispatched_at' => null,
        ]);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Outbox publisher stalled: 1 event(s)')
            ->assertExitCode(1);
    }

    public function test_a_recently_undispatched_event_within_the_threshold_is_not_flagged(): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_name' => 'payment.received.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '1',
            'payload' => json_encode([]),
            'classification' => 'INTERNAL',
            'occurred_at' => now()->subMinutes(1),
            'available_at' => now()->subMinutes(1),
            'attempt_count' => 0,
            'dispatched_at' => null,
        ]);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    public function test_it_detects_a_stale_queued_delivery_and_reports_it(): void
    {
        $this->createQueuedDelivery();

        $this->travel(20)->minutes();

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Notification queue worker stalled: 1 delivery(ies)')
            ->assertExitCode(1);
    }

    public function test_a_recently_queued_delivery_within_the_threshold_is_not_flagged(): void
    {
        $this->createQueuedDelivery();

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    /**
     * `booking.draft_submitted.v2` is a real, seeded matrix event with a
     * real customer-recipient producer — the same fixture shape
     * `NotificationDispatchPipelineTest::bookingSubmittedFixture()`/
     * `recordBookingSubmitted()` use, reused here rather than restated.
     * Runs the dispatch action synchronously and stops — no channel job —
     * so the resulting delivery stays `QUEUED`.
     */
    private function createQueuedDelivery(): void
    {
        $user = User::factory()->create();
        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'Spine Watchdog Test Cemetery',
            'slug' => 'spine-watchdog-test-cemetery-'.Str::random(8),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba Spine Watchdog',
        ]);

        $draft = (new StartBookingDraft)(userId: $user->id);
        $draft->forceFill(['cemetery_id' => $cemetery->id])->save();

        $outboxEventId = Outbox::record(
            eventName: 'booking.draft_submitted.v2',
            eventVersion: 2,
            aggregateType: 'booking_draft',
            aggregateId: $draft->getKey(),
            data: ['draft_id' => $draft->getKey()],
            classification: OutboxClassification::Internal,
        )->getKey();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEventId);
    }

    public function test_it_detects_a_recent_failed_job_and_reports_it(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([]),
            'exception' => 'fixture',
            'failed_at' => now()->subMinutes(1),
        ]);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('1 job(s) failed outright')
            ->assertExitCode(1);
    }

    public function test_an_old_failed_job_outside_the_window_is_not_flagged(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([]),
            'exception' => 'fixture',
            'failed_at' => now()->subHours(2),
        ]);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    public function test_multiple_simultaneous_problems_are_all_reported_in_one_run(): void
    {
        DB::table('outbox_events')->insert([
            'id' => (string) Str::uuid(),
            'event_name' => 'payment.received.v1',
            'event_version' => 1,
            'aggregate_type' => 'fixture',
            'aggregate_id' => '1',
            'payload' => json_encode([]),
            'classification' => 'INTERNAL',
            'occurred_at' => now()->subMinutes(10),
            'available_at' => now()->subMinutes(10),
            'attempt_count' => 0,
            'dispatched_at' => null,
        ]);

        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => json_encode([]),
            'exception' => 'fixture',
            'failed_at' => now()->subMinutes(1),
        ]);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Outbox publisher stalled')
            ->expectsOutputToContain('job(s) failed outright')
            ->assertExitCode(1);
    }
}
