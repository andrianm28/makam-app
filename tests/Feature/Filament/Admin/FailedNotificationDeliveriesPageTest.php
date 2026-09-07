<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Notification\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * NOTIF-06 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`): before this page existed, a permanently-failed
 * `notification_deliveries` row was completely invisible in the admin
 * panel. Rows are seeded via raw PDO, not the ORM — the same sanctioned
 * test-only path `InAppNotificationListPageTest`'s own doc block
 * establishes (`NotificationDeliveryWriteGuard` (AC9) rejects every
 * query-builder/Eloquent write to this table, including from tests).
 */
final class FailedNotificationDeliveriesPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_away(): void
    {
        $this->get('/admin/notifikasi-gagal')
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_a_failed_delivery_is_listed(): void
    {
        $actor = User::factory()->create();
        $this->grantRoleTo($actor, ActorRole::OPERATOR);

        $this->seedDelivery('FAILED', 'event-failed-1', 'recipient-failed-1');

        $this->actingAs($actor)
            ->get('/admin/notifikasi-gagal')
            ->assertOk()
            ->assertSee('recipient-failed-1')
            ->assertSee('Gagal mengirim');
    }

    public function test_a_healthy_queued_delivery_is_not_listed(): void
    {
        $actor = User::factory()->create();
        $this->grantRoleTo($actor, ActorRole::OPERATOR);

        $this->seedDelivery('QUEUED', 'event-queued-1', 'recipient-queued-1');

        $this->actingAs($actor)
            ->get('/admin/notifikasi-gagal')
            ->assertOk()
            ->assertDontSee('recipient-queued-1');
    }

    private function seedDelivery(string $state, string $eventId, string $recipientRef): void
    {
        DB::table('notification_events')->insert([
            'event_id' => $eventId,
            'event_name' => 'booking.draft_submitted.v2',
            'matrix_event_name' => 'Booking submitted',
            'aggregate_type' => 'booking_draft',
            'aggregate_id' => 'draft-1',
            'trace_id' => null,
            'consumed_at' => now(),
        ]);

        $recipientId = DB::table('notification_recipients')->insertGetId([
            'event_id' => $eventId,
            'recipient_ref' => $recipientRef,
            'actor_role' => 'cemetery_operator',
            'scope_entity_type' => null,
            'scope_entity_id' => null,
        ]);

        // Raw PDO only: NotificationDeliveryWriteGuard (AC9) rejects
        // ORM/query-builder writes to notification_deliveries — the same
        // sanctioned test-only path `InAppNotificationListPageTest::
        // seedEventWithDeliveries()` uses.
        $statement = DB::connection()->getPdo()->prepare(
            'INSERT INTO notification_deliveries
                (event_id, notification_recipient_id, recipient_ref, channel,
                 window_key, state, template_version_id, provider_ref,
                 failure_message, attempt_count, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $eventId,
            $recipientId,
            $recipientRef,
            'EMAIL',
            $eventId,
            $state,
            null,
            null,
            $state === DeliveryState::Failed->value ? 'NOTIFICATION_CHANNEL_SEND_FAILED' : null,
            $state === DeliveryState::Failed->value ? 3 : 0,
            now()->toDateTimeString(),
            now()->toDateTimeString(),
        ]);
    }
}
