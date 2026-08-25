<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkRenewalPaidOnline;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationEvent;
use App\Platform\Notification\Models\NotificationRecipient;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Task 4 of `docs/superpowers/plans/2026-08-25-renewal-online-payment.md` —
 * the "Renewal paid/verified" notification-matrix row wired to Task 2's real
 * `renewal.paid_online.v1` event.
 *
 * Runs the REAL domain Action end-to-end through the SAME outbox-consumption
 * seam `tests/Feature/Notification/NotificationDispatchPipelineTest.php`
 * exercises (`ConsumeOutboxNotificationJob::dispatchSync()` ->
 * `Actions\DispatchNotification::consumeOutboxEvent()`), not a unit-level
 * check on the seed migration's `outboxEventName()` match alone (that half
 * is `NotificationTemplatePersistenceTest::
 * test_only_the_twelve_ruled_rows_carry_an_outbox_event_name()`).
 *
 * `App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource`'s
 * own doc block ("Partially live") states plainly that `subjectFor()` has no
 * `'renewal'` arm — the SAME gap `renewal.submitted.v1` already has (see
 * that class's doc block, unmodified by this task). This means the
 * `notification_events` row this test proves IS written and correctly
 * matched to the "Renewal paid/verified" template, but recipient resolution
 * legitimately returns zero recipients — `Actions\DispatchNotification::
 * recordRecipientsAndDeliveries()`'s own documented `$subject === null`
 * branch, not a bug this task introduces or is scoped to fix. Wiring a real
 * `'renewal'` subject source is separate engineering work, out of this
 * task's file list.
 */
final class RenewalPaidOnlineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const int AMOUNT_MINOR = 150_000_00;

    public function test_marking_a_renewal_paid_online_dispatches_a_matched_notification_event(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $renewal = app(OpenRenewal::class)($grave);
        $quote = $renewal->quotes()->sole();

        app(MarkRenewalPaidOnline::class)(
            $renewal,
            (int) $quote->amount_minor,
            'pay_online_1',
            'provider_event:test-1',
        );

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'renewal.paid_online.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $notificationEvent = NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->sole();

        $this->assertSame('renewal.paid_online.v1', $notificationEvent->event_name);
        $this->assertSame('Renewal paid/verified', $notificationEvent->matrix_event_name);
        $this->assertSame('renewal', $notificationEvent->aggregate_type);
        $this->assertSame((string) $renewal->getKey(), $notificationEvent->aggregate_id);
        $this->assertNotNull($notificationEvent->consumed_at);

        // `'renewal'` is not yet a recognised aggregate type in
        // `ProvisionalAggregateNotificationSubjectSource::subjectFor()` — the
        // same documented gap `renewal.submitted.v1` has. No recipient is
        // resolved and no delivery is queued; the event is still recorded
        // (this is the class's own documented `$subject === null` handling,
        // not a silent drop).
        $this->assertSame(0, NotificationRecipient::query()->where('event_id', $outboxEvent->getKey())->count());
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', $outboxEvent->getKey())->count());
    }

    /**
     * AC8's redelivery-safety property, the same one
     * `NotificationDispatchPipelineTest::
     * test_ac8_a_duplicate_outbox_delivery_produces_exactly_one_of_everything()`
     * proves for `booking.draft_submitted.v2`: a duplicate consumption of the
     * SAME outbox event id must not produce a second `notification_events`
     * row (`event_id` is that table's primary key, inserted `insertOrIgnore`).
     */
    public function test_a_redelivered_outbox_event_does_not_double_record_the_notification_event(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $renewal = app(OpenRenewal::class)($grave);
        $quote = $renewal->quotes()->sole();

        app(MarkRenewalPaidOnline::class)(
            $renewal,
            (int) $quote->amount_minor,
            'pay_online_1',
            'provider_event:test-1',
        );

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'renewal.paid_online.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());
        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $this->assertSame(1, NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->count());
    }
}
