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

    /**
     * `--stale-delivery-minutes=-1`, not `$this->travel()`: `Actions\
     * DispatchNotification` stamps `created_at` via `CarbonImmutable::now()`
     * (real wall-clock, always), while `$this->travel()` only mocks
     * `Illuminate\Support\Carbon`'s test-now — the two classes' test-now
     * state is independent (a PHP trait gives each USING class its own copy
     * of a static property; `Carbon` and `CarbonImmutable` are unrelated
     * classes, not parent/child), so travelling forward never ages a row
     * this fixture created.
     *
     * A threshold of exactly `0` looked like it should sidestep needing to
     * fake elapsed time at all, but doesn't reliably: `notification_
     * deliveries.created_at` is `timestamp(0)` — no sub-second precision
     * (verified against the live schema) — and the fixture's insert and
     * this command's own `now()` both run within the same test method,
     * comfortably inside the same wall-clock second. `created_at < now()`
     * then compares two values that round to an IDENTICAL second, which is
     * false, not true — confirmed: this was the actual CI failure with `0`.
     * `-1` computes `now()->subMinutes(-1)` = one minute in the FUTURE,
     * building in real margin far larger than any sub-second rounding gap.
     */
    public function test_it_detects_a_stale_queued_delivery_and_reports_it(): void
    {
        $this->createQueuedDelivery();

        $this->artisan('spine:watchdog', ['--stale-delivery-minutes' => -1])
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

        $this->ensureActiveTemplateVersion('Booking submitted');

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

    /**
     * Diagnosed via a prior CI round: the delivery row this fixture produced
     * did exist (a temporary diagnostic assertion here confirmed
     * notification_events/notification_recipients/notification_deliveries
     * were all non-empty) but was never `QUEUED` — meaning `Actions\
     * DispatchNotification`'s `$version === null` branch fired, recording
     * `Unavailable` instead. `Tests\Feature\Notification\
     * NotificationDispatchPipelineTest`'s own delivery-count assertions
     * (`deliveryCount()`) only check that a row EXISTS for an
     * (event, recipient, channel) triple, never its `state` — so that
     * suite passing was never actual proof this event has a reliably
     * ACTIVE template version on a freshly migrated database, only that
     * migration seeds an unconditional matrix ROW. Rather than depend on
     * ambient seed state this fixture doesn't control, explicitly
     * guarantees an active version exists first.
     */
    private function ensureActiveTemplateVersion(string $eventName): void
    {
        $template = DB::table('notification_templates')->where('event_name', $eventName)->first();

        if ($template === null || $template->active_version_id !== null) {
            return;
        }

        // (template_id, version) is uniquely constrained
        // (2026_08_09_100010_create_notification_template_versions_table.php)
        // — a null active_version_id does not guarantee no version rows
        // exist at all, only that none is ACTIVE, so this picks the next
        // free version number rather than assuming 1.
        $nextVersion = 1 + (int) DB::table('notification_template_versions')
            ->where('template_id', $template->id)
            ->max('version');

        $versionId = DB::table('notification_template_versions')->insertGetId([
            'template_id' => $template->id,
            'version' => $nextVersion,
            'subject' => 'Test subject',
            'body' => 'Test body',
            'variable_allowlist' => json_encode([]),
            'restricted_fields' => json_encode([]),
            'created_by' => 'test-fixture',
            'created_at' => now(),
        ]);

        DB::table('notification_templates')->where('id', $template->id)->update(['active_version_id' => $versionId]);
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
