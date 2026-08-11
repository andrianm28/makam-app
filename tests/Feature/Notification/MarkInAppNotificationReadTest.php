<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Actions\MarkInAppNotificationRead;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `App\Platform\Notification\Actions\MarkInAppNotificationRead` — the read
 * transition (`read_at` null -> timestamp) and its audit row, task-5-brief.md
 * Step 2. These tests seed `notification_events` / `in_app_notifications`
 * rows directly (same isolation choice as `InAppNotificationInboxQueryTest`);
 * the write path for creating the records is `RecordInAppNotification`'s,
 * already covered by `NotificationDispatchPipelineTest`.
 */
final class MarkInAppNotificationReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_read_transition_marks_the_record_read_and_audits_once(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $notification = $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-1');

        $result = (app(MarkInAppNotificationRead::class))(
            $notification->id,
            $actor->id,
            'authenticated_actor',
            AuditSource::Panel,
        );

        $this->assertNotNull($result->fresh()->read_at);

        $audit = AuditEvent::query()->where('action', 'NOTIFICATION_READ')->sole();
        $this->assertSame('in_app_notification', $audit->subject_type);
        $this->assertSame((string) $notification->id, $audit->subject_id);
        $this->assertSame(AuditOutcome::Allowed->value, $audit->outcome);
        $this->assertSame((string) $actor->id, $audit->actor_ref);
        $this->assertSame('authenticated_actor', $audit->actor_role);
        $this->assertSame(AuditSource::Panel->value, $audit->source);
    }

    public function test_re_marking_an_already_read_record_adds_no_second_audit_row(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $notification = $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-1');

        $markRead = app(MarkInAppNotificationRead::class);

        $markRead($notification->id, $actor->id, 'authenticated_actor', AuditSource::Panel);
        $firstReadAt = $notification->fresh()->read_at;

        $markRead($notification->id, $actor->id, 'authenticated_actor', AuditSource::Panel);

        $this->assertSame(1, AuditEvent::query()->where('action', 'NOTIFICATION_READ')->count());
        $this->assertSame(
            $firstReadAt->toDateTimeString(),
            $notification->fresh()->read_at?->toDateTimeString(),
        );
    }

    public function test_a_record_inside_no_granted_scope_is_rejected_like_a_missing_one(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        // Names the actor, but sits in cemetery-b — outside their only grant.
        $notification = $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-b', 'event-1');

        try {
            (app(MarkInAppNotificationRead::class))(
                $notification->id,
                $actor->id,
                'authenticated_actor',
                AuditSource::Panel,
            );
            $this->fail('Expected ModelNotFoundException for an out-of-scope record.');
        } catch (ModelNotFoundException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertNull($notification->fresh()->read_at);
        $this->assertSame(0, AuditEvent::query()->where('action', 'NOTIFICATION_READ')->count());
    }

    public function test_an_unknown_id_is_rejected_and_audits_nothing(): void
    {
        $actor = User::factory()->create();

        try {
            (app(MarkInAppNotificationRead::class))(
                999_999,
                $actor->id,
                'authenticated_actor',
                AuditSource::Panel,
            );
            $this->fail('Expected ModelNotFoundException for an unknown id.');
        } catch (ModelNotFoundException $e) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, AuditEvent::query()->where('action', 'NOTIFICATION_READ')->count());
    }

    private function grant(int|string $actorRef, string $entityType, int|string $entityId): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $actorRef,
            'entity_type' => $entityType,
            'entity_id' => (string) $entityId,
        ]);
    }

    private function seedInAppNotification(
        int|string $actorRef,
        ?string $scopeType,
        ?string $scopeId,
        string $eventId,
    ): InAppNotification {
        DB::table('notification_events')->insert([
            'event_id' => $eventId,
            'event_name' => 'booking.draft_submitted.v2',
            'matrix_event_name' => 'Booking submitted',
            'aggregate_type' => 'booking_draft',
            'aggregate_id' => 'draft-1',
            'trace_id' => null,
            'consumed_at' => now(),
        ]);

        $notification = new InAppNotification;
        $notification->forceFill([
            'event_id' => $eventId,
            'recipient_ref' => (string) $actorRef,
            'actor_role' => 'cemetery_operator',
            'scope_entity_type' => $scopeType,
            'scope_entity_id' => $scopeId,
            'subject' => 'Pembaruan area kerja',
            'body' => 'Terdapat pembaruan pada area kerja Anda.',
        ])->save();

        return $notification;
    }
}
