<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkRenewalPaidOnline;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Notification\Models\NotificationDelivery;
use App\Platform\Notification\Models\NotificationEvent;
use App\Platform\Notification\Models\NotificationRecipient;
use App\Platform\Notification\RecipientRole;
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
 * `App\Platform\Notification\ProvisionalAggregateNotificationSubjectSource`
 * now carries a real `'renewal'` arm (fixed 25 Aug 2026, closing the gap this
 * class's doc block used to describe here). A renewal has no owner/contact
 * reference anywhere in this codebase (see that class's own `renewal`
 * doc-block section), so the Customer column — `EMAIL/WA + invoice` on the
 * matrix — still resolves nobody; that part of the gap is a real product
 * limitation, not a wiring bug, and stays open. What the fix DOES close: the
 * renewal's grave record always carries a `cemetery_id`
 * (`grave_records.cemetery_id` is NOT NULL), so the "Pengelola TPU/TPS"
 * column now resolves a genuine cemetery-operator recipient where before it
 * resolved none at all.
 */
final class RenewalPaidOnlineNotificationTest extends TestCase
{
    use RefreshDatabase;

    private const int AMOUNT_MINOR = 150_000_00;

    public function test_marking_a_renewal_paid_online_dispatches_a_matched_notification_event(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $operatorRef = 'cemetery-operator-1';
        ScopeAssignment::query()->create([
            'actor_identifier' => $operatorRef,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grave->cemetery_id,
        ]);

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

        // `ProvisionalAggregateNotificationSubjectSource::renewalSubject()`
        // now resolves the renewal's grave record's cemetery as a real scope
        // entity, so the cemetery operator IS a real recipient — the fix
        // this test proves. There is still no customer recipient: a renewal
        // has no owner/contact reference anywhere in this codebase (see that
        // class's `renewal` doc-block section), so `ownerRef` is always
        // `null` and the Customer column, though targeted on the matrix,
        // resolves nobody. This is the honest current end state, not a
        // regression this fix introduces.
        $recipient = NotificationRecipient::query()->where('event_id', $outboxEvent->getKey())->sole();
        $this->assertSame($operatorRef, $recipient->recipient_ref);
        $this->assertSame(RecipientRole::CEMETERY_OPERATOR, $recipient->actor_role);

        // `CEMETERY_OPERATOR` is one of `DispatchNotification::
        // UNCONDITIONAL_IN_APP_ROLES`, so an in-app record is always written
        // for it. Matrix cell for "Pengelola TPU/TPS" on "Renewal
        // paid/verified" is `IN_APP` only (no EMAIL/WA token), so no channel
        // delivery row is queued alongside it.
        $this->assertTrue(InAppNotification::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->exists());
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
