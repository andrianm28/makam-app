<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Platform\Notification\DeliveryState;
use App\Platform\Observability\SpineDegradedException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * `spine:watchdog` — see its own doc block for why these three signals and
 * why each is checked independently. Every fixture row below is a plain
 * `DB::table()` insert of the exact shape the real spine writes, not a
 * scenario walked through the real pipeline — the three detection queries
 * are what is under test, not the pipeline that would normally produce
 * their input.
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
        $this->insertQueuedDelivery(queuedMinutesAgo: 20);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Notification queue worker stalled: 1 delivery(ies)')
            ->assertExitCode(1);
    }

    public function test_a_recently_queued_delivery_within_the_threshold_is_not_flagged(): void
    {
        $this->insertQueuedDelivery(queuedMinutesAgo: 1);

        $this->artisan('spine:watchdog')
            ->expectsOutputToContain('Spine healthy')
            ->assertExitCode(0);
    }

    /**
     * `notification_deliveries.event_id` restrictOnDelete's on
     * `notification_events.event_id`, and `notification_recipient_id`
     * restrictOnDelete's on `notification_recipients.id` — both real,
     * required foreign keys, so a stale-delivery fixture needs a minimal
     * valid row on each parent table first.
     */
    private function insertQueuedDelivery(int $queuedMinutesAgo): void
    {
        $eventId = (string) Str::uuid();

        DB::table('notification_events')->insert([
            'event_id' => $eventId,
            'event_name' => 'payment.received.v1',
            'matrix_event_name' => 'Payment received',
            'aggregate_type' => 'fixture',
            'aggregate_id' => '1',
            'consumed_at' => now(),
        ]);

        $recipientId = DB::table('notification_recipients')->insertGetId([
            'event_id' => $eventId,
            'recipient_ref' => '1',
            'actor_role' => 'customer',
        ]);

        DB::table('notification_deliveries')->insert([
            'event_id' => $eventId,
            'notification_recipient_id' => $recipientId,
            'recipient_ref' => '1',
            'channel' => 'EMAIL',
            'window_key' => 'fixture',
            'state' => DeliveryState::Queued->value,
            'attempt_count' => 0,
            'created_at' => now()->subMinutes($queuedMinutesAgo),
            'updated_at' => now()->subMinutes($queuedMinutesAgo),
        ]);
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
