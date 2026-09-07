<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Visitation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\ChangeVisitationBookingStatus;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\VisitationBookingStatus;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use App\Platform\Notification\Models\InAppNotification;
use App\Platform\Notification\Models\NotificationEvent;
use App\Platform\Notification\Models\NotificationRecipient;
use App\Platform\Notification\RecipientRole;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * NOTIF-13 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`), end-to-end through the real outbox-consumption seam —
 * the same seam `tests/Feature/Domain/Renewal/RenewalSubmittedNotificationTest`
 * exercises for its own aggregate.
 *
 * Before this fix, `RequestVisitation::book()` and
 * `ChangeVisitationBookingStatus::__invoke()` both already recorded their
 * outbox events, but `ProvisionalAggregateNotificationSubjectSource` had no
 * `'visitation_booking'` arm and no matching `notification_templates` row
 * existed — every visitation event recorded a `notification_events` row and
 * resolved zero recipients, ever. This proves the cemetery operator now
 * gets a real in-app record for both the request and the confirmation.
 */
final class VisitationBookingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requesting_a_visit_dispatches_a_matched_notification_event_to_the_cemetery_operator(): void
    {
        $cemetery = $this->cemetery();
        $this->policy($cemetery);

        $operatorRef = 'cemetery-operator-visitation-1';
        ScopeAssignment::query()->create([
            'actor_identifier' => $operatorRef,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);

        $booking = app(RequestVisitation::class)(
            $cemetery,
            now()->addDays(3)->toDateString(),
            2,
            '0812-3456-7890',
            'visitor@example.test',
            null,
            [],
            'visitation-notif-test-'.Str::random(8),
            'actor:customer',
        );

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'visit.booking_requested.v1')
            ->where('aggregate_id', (string) $booking->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $notificationEvent = NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->sole();
        $this->assertSame('Visitation booking requested', $notificationEvent->matrix_event_name);
        $this->assertSame('visitation_booking', $notificationEvent->aggregate_type);

        $recipient = NotificationRecipient::query()->where('event_id', $outboxEvent->getKey())->sole();
        $this->assertSame($operatorRef, $recipient->recipient_ref);
        $this->assertSame(RecipientRole::CEMETERY_OPERATOR, $recipient->actor_role);

        $this->assertTrue(InAppNotification::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->exists());

        // Customer column is `none` — no owner reference exists to notify
        // (see `visitationBookingSubject()`'s own doc block).
        $this->assertSame(
            0,
            NotificationRecipient::query()
                ->where('event_id', $outboxEvent->getKey())
                ->where('actor_role', RecipientRole::CUSTOMER)
                ->count()
        );
    }

    public function test_confirming_a_visit_also_notifies_the_cemetery_operator(): void
    {
        $cemetery = $this->cemetery();
        $this->policy($cemetery);

        $operatorRef = 'cemetery-operator-visitation-2';
        ScopeAssignment::query()->create([
            'actor_identifier' => $operatorRef,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);

        $booking = app(RequestVisitation::class)(
            $cemetery,
            now()->addDays(3)->toDateString(),
            2,
            '0812-3456-7890',
            'visitor@example.test',
            null,
            [],
            'visitation-notif-confirm-test-'.Str::random(8),
            'actor:customer',
        );

        app(ChangeVisitationBookingStatus::class)(
            $booking,
            VisitationBookingStatus::CONFIRMED,
            'operator:test',
            'cemetery_operator',
        );

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'visit.booking_confirmed.v1')
            ->where('aggregate_id', (string) $booking->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $notificationEvent = NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->sole();
        $this->assertSame('Visitation booking confirmed', $notificationEvent->matrix_event_name);

        $this->assertTrue(InAppNotification::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->exists());
    }

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'Visitation Notification Test Cemetery',
            'slug' => 'visitation-notification-test-cemetery-'.Str::random(8),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba Notifikasi Kunjungan',
        ]);
    }

    private function policy(Cemetery $cemetery): CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['open' => '08:00', 'close' => '17:00'],
            ],
            'daily_capacity' => 10,
        ]);
    }
}
