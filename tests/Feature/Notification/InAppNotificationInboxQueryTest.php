<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\InAppNotificationInboxQuery;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `App\Platform\Notification\InAppNotificationInboxQuery` — the scope filter
 * behind the admin panel's in-app notification inbox (task-5-brief.md
 * Step 1's "no existence leak"). These tests seed `notification_events` /
 * `in_app_notifications` rows directly so they isolate the QUERY (the read
 * surface) from the dispatch pipeline that writes the rows — the pipeline's
 * own record-shape is already proven by `NotificationDispatchPipelineTest`.
 */
final class InAppNotificationInboxQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_actor_sees_only_records_within_their_own_scope_assignments(): void
    {
        $actorA = User::factory()->create();
        $actorB = User::factory()->create();
        $this->grant($actorA->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $this->grant($actorB->id, ScopeEntityType::CEMETERY, 'cemetery-b');

        $inScope = $this->seedInAppNotification($actorA->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-a-1');
        // Names actor A but sits in cemetery-b — A holds no grant there.
        $this->seedInAppNotification($actorA->id, ScopeEntityType::CEMETERY, 'cemetery-b', 'event-a-2');
        // B's own record, invisible to A.
        $this->seedInAppNotification($actorB->id, ScopeEntityType::CEMETERY, 'cemetery-b', 'event-b-1');

        $visible = app(InAppNotificationInboxQuery::class)->forActor($actorA->id)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($inScope->getKey(), $visible->first()->getKey());
    }

    public function test_a_revoked_grant_hides_those_records(): void
    {
        $actor = User::factory()->create();
        $assignment = ScopeAssignment::query()->create([
            'actor_identifier' => (string) $actor->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);
        $notification = $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-1');

        $this->assertTrue(
            app(InAppNotificationInboxQuery::class)->forActor($actor->id)->whereKey($notification->id)->exists()
        );

        $assignment->revoke();

        $this->assertFalse(
            app(InAppNotificationInboxQuery::class)->forActor($actor->id)->whereKey($notification->id)->exists()
        );
    }

    public function test_an_unscoped_record_naming_the_actor_is_still_visible(): void
    {
        $actor = User::factory()->create();
        $notification = $this->seedInAppNotification($actor->id, null, null, 'event-1');

        $visible = app(InAppNotificationInboxQuery::class)->forActor($actor->id)->get();

        $this->assertCount(1, $visible);
        $this->assertSame($notification->getKey(), $visible->first()->getKey());
    }

    public function test_a_record_with_an_unrecognised_scope_type_matches_nothing(): void
    {
        $actor = User::factory()->create();
        $this->seedInAppNotification($actor->id, 'future_entity', 'whatever', 'event-1');

        $this->assertEmpty(app(InAppNotificationInboxQuery::class)->forActor($actor->id)->get());
    }

    public function test_a_guest_sees_an_empty_inbox_by_construction(): void
    {
        $this->seedInAppNotification('someone-else', ScopeEntityType::CEMETERY, 'cemetery-a', 'event-1');

        $this->assertEmpty(app(InAppNotificationInboxQuery::class)->forCurrentActor()->get());
        $this->assertSame(0, app(InAppNotificationInboxQuery::class)->unreadCountForCurrentActor());
    }

    public function test_unread_count_covers_only_the_actors_scoped_unread_records(): void
    {
        $actor = User::factory()->create();
        $this->grant($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a');

        $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-1');
        $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-2', read: true);
        // Unread but out of A's scope — must not inflate the badge.
        $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-b', 'event-3');

        $this->actingAs($actor);

        $this->assertSame(1, app(InAppNotificationInboxQuery::class)->unreadCountForCurrentActor());
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
        bool $read = false,
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
            'read_at' => $read ? now() : null,
        ])->save();

        return $notification;
    }
}
