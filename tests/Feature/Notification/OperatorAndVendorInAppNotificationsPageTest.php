<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * NOTIF-04 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`): before this fix, the panel-agnostic
 * `InAppNotificationList` component and its actor-scoped
 * `InAppNotificationInboxQuery` had no page mounting them anywhere except
 * `/admin` — a cemetery operator with real, resolved in-app rows (e.g. from
 * `Renewal submitted` or NOTIF-13's new visitation rows) had genuinely no
 * surface in this codebase to ever read them. This proves both new pages
 * are reachable and render the actor's own scoped rows — the same proof
 * `InAppNotificationListPageTest` already gives for `/admin`, since neither
 * the component nor the query changed.
 */
final class OperatorAndVendorInAppNotificationsPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_away_from_the_operator_inbox(): void
    {
        $this->get('/operator/notifikasi-aplikasi')
            ->assertRedirect(route('filament.operator.auth.login'));
    }

    public function test_a_guest_is_redirected_away_from_the_vendor_inbox(): void
    {
        $this->get('/vendor/notifikasi-aplikasi')
            ->assertRedirect(route('filament.vendor.auth.login'));
    }

    public function test_a_cemetery_operator_sees_the_operator_inbox_with_their_own_scoped_row(): void
    {
        $actor = User::factory()->create();
        $this->grantRoleTo($actor, ActorRole::CEMETERY_OPERATOR);
        $this->grant($actor->id, ScopeEntityType::CEMETERY, 'cemetery-operator-panel-test');

        $this->seedInAppNotification($actor->id, ScopeEntityType::CEMETERY, 'cemetery-operator-panel-test', 'event-operator-panel', 'Notifikasi panel operator');

        $this->actingAs($actor)
            ->get('/operator/notifikasi-aplikasi')
            ->assertOk()
            ->assertSee('Notifikasi panel operator');
    }

    public function test_a_vendor_sees_the_vendor_inbox_with_their_own_scoped_row(): void
    {
        $actor = User::factory()->create();
        $this->grantRoleTo($actor, ActorRole::VENDOR);
        $this->grant($actor->id, ScopeEntityType::VENDOR, 'vendor-panel-test');

        $this->seedInAppNotification($actor->id, ScopeEntityType::VENDOR, 'vendor-panel-test', 'event-vendor-panel', 'Notifikasi panel vendor');

        $this->actingAs($actor)
            ->get('/vendor/notifikasi-aplikasi')
            ->assertOk()
            ->assertSee('Notifikasi panel vendor');
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
        string $subject,
    ): void {
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
            'actor_role' => $scopeType === ScopeEntityType::VENDOR ? 'vendor' : 'cemetery_operator',
            'scope_entity_type' => $scopeType,
            'scope_entity_id' => $scopeId,
            'subject' => $subject,
            'body' => 'Isi notifikasi uji.',
        ])->save();
    }
}
