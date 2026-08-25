<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
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
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `renewal.submitted.v1` (`App\Domain\Renewal\Actions\OpenRenewal`) end-to-end
 * through the real outbox-consumption seam — the same seam
 * `tests/Feature/Notification/NotificationDispatchPipelineTest.php` and
 * `RenewalPaidOnlineNotificationTest.php` exercise.
 *
 * Before 25 Aug 2026, `ProvisionalAggregateNotificationSubjectSource::
 * subjectFor()` had no `'renewal'` arm, so every `renewal.submitted.v1`
 * event recorded a `notification_events` row but resolved zero recipients —
 * nobody was ever told a renewal request had been received. This test
 * proves the fix: the renewal's grave record's cemetery now resolves a real
 * `cemetery_operator` recipient. It also proves, honestly, what the fix does
 * NOT close — a renewal has no owner/contact reference anywhere in this
 * codebase (see `ProvisionalAggregateNotificationSubjectSource`'s own
 * `renewal` doc-block section), so the matrix's `Customer: EMAIL/WA` column
 * still resolves nobody.
 */
final class RenewalSubmittedNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_a_renewal_dispatches_a_matched_notification_event_to_the_cemetery_operator(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $operatorRef = 'cemetery-operator-1';
        ScopeAssignment::query()->create([
            'actor_identifier' => $operatorRef,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grave->cemetery_id,
        ]);

        $renewal = app(OpenRenewal::class)($grave);

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'renewal.submitted.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $notificationEvent = NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->sole();

        $this->assertSame('renewal.submitted.v1', $notificationEvent->event_name);
        $this->assertSame('Renewal submitted', $notificationEvent->matrix_event_name);
        $this->assertSame('renewal', $notificationEvent->aggregate_type);
        $this->assertSame((string) $renewal->getKey(), $notificationEvent->aggregate_id);
        $this->assertNotNull($notificationEvent->consumed_at);

        // The real fix: the cemetery operator is now a genuine recipient.
        $recipient = NotificationRecipient::query()->where('event_id', $outboxEvent->getKey())->sole();
        $this->assertSame($operatorRef, $recipient->recipient_ref);
        $this->assertSame(RecipientRole::CEMETERY_OPERATOR, $recipient->actor_role);

        // Matrix cell for "Pengelola TPU/TPS" on "Renewal submitted" is
        // `IN_APP/EMAIL` — an in-app record AND a queued EMAIL delivery.
        $this->assertTrue(InAppNotification::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->exists());
        $this->assertSame(
            1,
            NotificationDelivery::query()
                ->where('event_id', $outboxEvent->getKey())
                ->where('recipient_ref', $operatorRef)
                ->where('channel', 'EMAIL')
                ->count()
        );

        // No customer recipient: `ownerRef` is always `null` for a renewal
        // subject (no owner/contact reference exists on `renewals` or
        // `grave_records`) — the matrix's `Customer: EMAIL/WA` column stays
        // honestly unreachable, a real product limitation this fix does not
        // (and is not scoped to) close.
        $this->assertSame(
            0,
            NotificationRecipient::query()
                ->where('event_id', $outboxEvent->getKey())
                ->where('actor_role', RecipientRole::CUSTOMER)
                ->count()
        );
    }

    public function test_a_renewal_id_with_no_matching_row_resolves_no_recipients_without_throwing(): void
    {
        // renewalSubject()'s own failure mode: an aggregate_id that does not
        // match any renewals row must return null (no scope entity, no
        // owner), never throw - matching bookingDraftSubject()/
        // orderSubject()'s established shape for a missing row of their own
        // aggregate. renewals.grave_record_id is a real, always-present
        // foreign key, so this is the one reachable "not found" case for
        // 'renewal' - there is no way to construct a real renewals row whose
        // grave_record_id does not resolve. A syntactically valid,
        // non-existent UUID is used (not an arbitrary string) because
        // renewals.id is a real Postgres uuid column - an arbitrary string
        // would fail the cast before ever reaching the "not found" branch
        // this test targets.
        $missingRenewalId = '00000000-0000-0000-0000-000000000000';

        $outboxEvent = Outbox::record(
            eventName: 'renewal.submitted.v1',
            eventVersion: 1,
            aggregateType: 'renewal',
            aggregateId: $missingRenewalId,
            data: ['renewal_id' => $missingRenewalId],
            classification: OutboxClassification::Internal,
        );

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $this->assertTrue(NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->exists());
        $this->assertSame(0, NotificationRecipient::query()->where('event_id', $outboxEvent->getKey())->count());
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', $outboxEvent->getKey())->count());
    }
}
