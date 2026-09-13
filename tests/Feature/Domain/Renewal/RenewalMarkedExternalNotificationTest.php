<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
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
 * QUE-03, Phase 3 Batch M1a (07 Sep 2026): `Actions\MarkExternalRenewal`
 * previously wrote state and an audit row with no outbox event at all, even
 * though `renewal.marked_external.v1` was already catalogued
 * (`docs/contracts/event-catalog.md`). Modeled directly on
 * `RenewalPaidOnlineNotificationTest` — the online path's own end-to-end
 * proof through the SAME outbox-consumption seam
 * (`ConsumeOutboxNotificationJob::dispatchSync()` ->
 * `Actions\DispatchNotification::consumeOutboxEvent()`).
 *
 * The dispatch side is wired through a NEW matrix row, `Renewal paid/
 * verified (external)` (`docs/contracts/notification-matrix.md`), because
 * `notification_templates.event_name` is unique and the online path's row
 * already claims `Renewal paid/verified` — see
 * `2026_09_07_100000_add_renewal_marked_external_notification_template.php`.
 * Recipient facts are identical to the online row, so this test's
 * expectations mirror `RenewalPaidOnlineNotificationTest`'s exactly, aside
 * from the matrix event label and the producing Action.
 */
final class RenewalMarkedExternalNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function authorizedAdminFor(GraveRecord $grave): User
    {
        $admin = User::factory()->create();

        ActorRoleAssignment::create([
            'actor_identifier' => (string) $admin->getAuthIdentifier(),
            'role' => ActorRole::ADMIN,
        ]);

        ScopeAssignment::create([
            'actor_identifier' => (string) $admin->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grave->cemetery_id,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
        ]);

        return $admin;
    }

    public function test_marking_a_renewal_external_dispatches_a_matched_notification_event(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $operatorRef = 'cemetery-operator-external-1';
        ScopeAssignment::query()->create([
            'actor_identifier' => $operatorRef,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grave->cemetery_id,
        ]);

        $admin = $this->authorizedAdminFor($grave);
        $this->actingAs($admin);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-QUE03-1',
            reason: 'Pembayaran offline di TPU',
        );

        $renewal = Renewal::query()->sole();

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'renewal.marked_external.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $notificationEvent = NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->sole();

        $this->assertSame('renewal.marked_external.v1', $notificationEvent->event_name);
        $this->assertSame('Renewal paid/verified (external)', $notificationEvent->matrix_event_name);
        $this->assertSame('renewal', $notificationEvent->aggregate_type);
        $this->assertSame((string) $renewal->getKey(), $notificationEvent->aggregate_id);
        $this->assertNotNull($notificationEvent->consumed_at);

        // Unlike `RenewalPaidOnlineNotificationTest` (whose `OpenRenewal`
        // call requires no actor authorization at all), this action's
        // admin actor itself holds a scope grant on the SAME cemetery to
        // satisfy `RenewalMarkingPolicy` — and `ScopeAssignmentResolver::
        // actorsForEntity()` resolves every non-revoked grant on that
        // entity, admin included, so the admin is a genuine second
        // `cemetery_operator`-role recipient here, not a test artifact.
        // Filtering to `$operatorRef` isolates the one this test targets.
        $recipient = NotificationRecipient::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->sole();
        $this->assertSame($operatorRef, $recipient->recipient_ref);
        $this->assertSame(RecipientRole::CEMETERY_OPERATOR, $recipient->actor_role);

        $this->assertTrue(InAppNotification::query()
            ->where('event_id', $outboxEvent->getKey())
            ->where('recipient_ref', $operatorRef)
            ->exists());
        $this->assertSame(0, NotificationDelivery::query()->where('event_id', $outboxEvent->getKey())->count());
    }

    /**
     * AC8's redelivery-safety property — same as
     * `RenewalPaidOnlineNotificationTest`'s equivalent test.
     */
    public function test_a_redelivered_outbox_event_does_not_double_record_the_notification_event(): void
    {
        $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
        $admin = $this->authorizedAdminFor($grave);
        $this->actingAs($admin);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-QUE03-2',
            reason: 'Pembayaran offline di TPU',
        );

        $renewal = Renewal::query()->sole();

        $outboxEvent = OutboxEvent::query()
            ->where('event_name', 'renewal.marked_external.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->sole();

        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());
        ConsumeOutboxNotificationJob::dispatchSync($outboxEvent->getKey());

        $this->assertSame(1, NotificationEvent::query()->where('event_id', $outboxEvent->getKey())->count());
    }
}
