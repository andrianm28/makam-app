<?php

declare(strict_types=1);

namespace Tests\Feature\Notification;

use App\Filament\Admin\Pages\InAppNotifications;
use App\Livewire\Platform\Notification\InAppNotificationList;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\InAppNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The admin panel's in-app notification inbox read surface — the
 * `InAppNotifications` Filament page, the `InAppNotificationList` Livewire
 * component it mounts, and the delivery-state chips (task-5-brief.md
 * Step 3). Scope/audit behaviour is proven in
 * `InAppNotificationInboxQueryTest`/`MarkInAppNotificationReadTest`; these
 * tests prove the render surface: reachable page, scoped visibility through
 * the page, the mandatory empty state, honest delivery-state chips
 * (pending/unavailable rendered, never a false "Terkirim"), and the
 * mark-read transition end to end.
 *
 * Every HTTP-reached actor holds a real `operator` role grant (via
 * `Tests\Support\GrantsActorRoles`): Task 10 of the L9 `admin-operations`
 * lane restricted `/admin` panel membership to the four back-office roles,
 * so a roleless actor would get a 403 at the panel boundary and never reach
 * the inbox this file exists to render. `operator` is the least role that
 * makes each test's "some panel user" intent true.
 *
 * Delivery rows are seeded directly in the chosen states (the brief's own
 * instruction: "seed a notification_deliveries row in each state and assert
 * the rendered UI text") rather than driven through the dispatch pipeline —
 * the pipeline's own state-recording is already covered by
 * `NotificationDispatchPipelineTest`, and direct seeding lets each chip test
 * pin exactly the state it asserts on. The seeds must go through raw PDO
 * (never the ORM): `NotificationDeliveryWriteGuard` (AC9) rejects every
 * query-builder/Eloquent write to `notification_deliveries` — including
 * from tests — and the pipeline suite already establishes PDO as the
 * sanctioned test-only path for placing delivery rows in a chosen state
 * (see `NotificationDispatchPipelineTest::test_reclaimed_delivery_...`:
 * "This setup deliberately uses PDO so it does not exercise the production
 * delivery write API").
 */
final class InAppNotificationListPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_away_from_the_inbox_page(): void
    {
        $this->get('/admin/notifikasi-aplikasi')
            ->assertRedirect(route('filament.admin.auth.login'));
    }

    public function test_the_panel_serves_the_inbox_and_shows_only_the_actors_scoped_records(): void
    {
        $actorA = User::factory()->create();
        $actorB = User::factory()->create();
        $this->grantRoleTo($actorA, ActorRole::OPERATOR);
        $this->grantRoleTo($actorB, ActorRole::OPERATOR);
        $this->grant($actorA->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $this->grant($actorB->id, ScopeEntityType::CEMETERY, 'cemetery-b');

        $this->seedInAppNotification($actorA->id, ScopeEntityType::CEMETERY, 'cemetery-a', 'event-a', 'Pembaruan area A');
        $this->seedInAppNotification($actorB->id, ScopeEntityType::CEMETERY, 'cemetery-b', 'event-b', 'Pembaruan area B');

        $this->actingAs($actorA)
            ->get('/admin/notifikasi-aplikasi')
            ->assertOk()
            ->assertSee('Pembaruan area A')
            ->assertDontSee('Pembaruan area B');
    }

    public function test_an_actor_with_no_records_sees_the_empty_state(): void
    {
        $actor = User::factory()->create();
        $this->grantRoleTo($actor, ActorRole::OPERATOR);

        $this->actingAs($actor)
            ->get('/admin/notifikasi-aplikasi')
            ->assertOk()
            ->assertSee('Belum ada notifikasi');
    }

    public function test_delivery_chips_render_pending_and_unavailable_never_a_false_sent(): void
    {
        $operator = User::factory()->create();
        $this->grant($operator->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $this->seedEventWithDeliveries($operator->id, [
            'EMAIL' => DeliveryState::Queued,
            'WA' => DeliveryState::Unavailable,
        ]);

        Livewire::actingAs($operator)
            ->test(InAppNotificationList::class)
            ->assertSee('Email · Sedang dikirim')
            ->assertSee('WhatsApp · WhatsApp belum tersedia')
            ->assertDontSee('Terkirim');
    }

    /**
     * Task 7a slice 3 fix: UNAVAILABLE is also recorded for a missing
     * active template version (`DeliveryResult::TEMPLATE_VERSION_UNAVAILABLE`),
     * on any channel including EMAIL — not only for the WA-gate closure.
     * Before the fix, `DeliveryState::presentation()` always rendered the
     * WA-gate-specific "WhatsApp belum tersedia" for ANY unavailable row,
     * so an EMAIL row with this cause rendered the self-contradicting
     * "Email · WhatsApp belum tersedia". A non-null `failure_message` must
     * render the channel-neutral label instead.
     */
    public function test_an_unavailable_email_delivery_for_a_non_whatsapp_gate_cause_never_shows_the_whatsapp_gate_label(): void
    {
        $operator = User::factory()->create();
        $this->grant($operator->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $this->seedEventWithDeliveries(
            $operator->id,
            ['EMAIL' => DeliveryState::Unavailable],
            ['EMAIL' => 'NOTIFICATION_TEMPLATE_UNAVAILABLE']
        );

        Livewire::actingAs($operator)
            ->test(InAppNotificationList::class)
            ->assertSee('Email · Notifikasi tidak tersedia')
            ->assertDontSee('WhatsApp belum tersedia')
            ->assertDontSee('Terkirim');
    }

    public function test_sent_deliveries_render_terkirim(): void
    {
        $operator = User::factory()->create();
        $this->grant($operator->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $this->seedEventWithDeliveries($operator->id, [
            'EMAIL' => DeliveryState::Sent,
            'WA' => DeliveryState::Sent,
        ]);

        Livewire::actingAs($operator)
            ->test(InAppNotificationList::class)
            ->assertSee('Email · Terkirim')
            ->assertSee('WhatsApp · Terkirim');
    }

    public function test_marking_a_notification_read_updates_the_list_and_audits(): void
    {
        $operator = User::factory()->create();
        $this->grant($operator->id, ScopeEntityType::CEMETERY, 'cemetery-a');
        $notification = $this->seedEventWithDeliveries($operator->id, [
            'EMAIL' => DeliveryState::Queued,
            'WA' => DeliveryState::Unavailable,
        ]);

        Livewire::actingAs($operator)
            ->test(InAppNotificationList::class)
            ->assertSee('Tandai dibaca')
            ->call('markRead', $notification->id)
            ->assertDontSee('Tandai dibaca');

        $this->assertNotNull($notification->fresh()->read_at);
        $this->assertSame(1, AuditEvent::query()->where('action', 'NOTIFICATION_READ')->count());
    }

    public function test_the_inbox_page_slug_resolves_to_notifikasi_aplikasi(): void
    {
        $this->assertSame('notifikasi-aplikasi', InAppNotifications::getSlug());
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
            'actor_role' => 'cemetery_operator',
            'scope_entity_type' => $scopeType,
            'scope_entity_id' => $scopeId,
            'subject' => $subject,
            'body' => 'Terdapat pembaruan pada area kerja Anda.',
        ])->save();
    }

    /**
     * Seeds one notification event with one in-app record and one
     * `notification_deliveries` row per channel in the requested state — the
     * same row shapes `DispatchNotification::consumeOutboxEvent()` writes,
     * placed directly so each chip test can pin the exact state it asserts.
     *
     * @param  array<string, DeliveryState>  $deliveriesByChannel
     */
    private function seedEventWithDeliveries(int|string $operatorRef, array $deliveriesByChannel, array $failureMessagesByChannel = []): InAppNotification
    {
        $eventId = 'event-delivery-'.md5(implode('|', array_keys($deliveriesByChannel)));

        DB::table('notification_events')->insert([
            'event_id' => $eventId,
            'event_name' => 'booking.draft_submitted.v2',
            'matrix_event_name' => 'Booking submitted',
            'aggregate_type' => 'booking_draft',
            'aggregate_id' => 'draft-1',
            'trace_id' => null,
            'consumed_at' => now(),
        ]);

        $versionId = DB::table('notification_templates')
            ->where('event_name', 'Booking submitted')
            ->value('active_version_id');

        if ($versionId === null) {
            $this->fail('The seeded "Booking submitted" template has no active version.');
        }

        $recipientId = DB::table('notification_recipients')->insertGetId([
            'event_id' => $eventId,
            'recipient_ref' => (string) $operatorRef,
            'actor_role' => 'cemetery_operator',
            'scope_entity_type' => ScopeEntityType::CEMETERY,
            'scope_entity_id' => 'cemetery-a',
        ]);

        $notification = new InAppNotification;
        $notification->forceFill([
            'event_id' => $eventId,
            'recipient_ref' => (string) $operatorRef,
            'actor_role' => 'cemetery_operator',
            'scope_entity_type' => ScopeEntityType::CEMETERY,
            'scope_entity_id' => 'cemetery-a',
            'subject' => 'Pembaruan area kerja',
            'body' => 'Terdapat pembaruan pada area kerja Anda.',
        ])->save();

        foreach ($deliveriesByChannel as $channel => $state) {
            // Raw PDO only: NotificationDeliveryWriteGuard (AC9) rejects
            // ORM/query-builder writes to notification_deliveries, and the
            // pipeline suite sanctions PDO for test fixture placement.
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
                (string) $operatorRef,
                $channel,
                $eventId,
                $state->value,
                $versionId,
                null,
                $failureMessagesByChannel[$channel] ?? null,
                0,
                now()->toDateTimeString(),
                now()->toDateTimeString(),
            ]);
        }

        return $notification;
    }
}
