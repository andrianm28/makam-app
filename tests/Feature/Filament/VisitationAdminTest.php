<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\ChangeVisitationBookingStatus;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\VisitationAuditActions;
use App\Domain\Visitation\VisitationBookingStatus;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages\CreateCemeteryVisitationPolicy;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages\EditCemeteryVisitationPolicy;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\RelationManagers\BlackoutDatesRelationManager;
use App\Filament\Admin\Resources\VisitationBookings\Pages\ListVisitationBookings;
use App\Filament\Admin\Resources\VisitationBookings\VisitationBookingsResource;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Task 2 (Lane 2 — Visitation): the two admin surfaces —
 *
 * 1. `CemeteryVisitationPolicyResource` (single policy per cemetery via a
 *    cemetery select; every create/edit write audits
 *    `CEMETERY_VISITATION_POLICY_UPDATED`) + its
 *    `BlackoutDatesRelationManager` (create/delete with a required reason,
 *    audited `VISITATION_BLACKOUT_CREATED`/`VISITATION_BLACKOUT_DELETED`).
 * 2. `VisitationBookingsResource` (reference/cemetery/date/count/contact/
 *    status table; **per-cemetery scoping**: a cemetery-granted operator
 *    sees only their cemeteries' bookings, an admin sees all; the
 *    confirm/cancel/no-show row actions run `ChangeVisitationBookingStatus`
 *    — audit `VISITATION_STATUS_CHANGED` + outbox `visit.booking_confirmed.v1`
 *    on confirm, with late transitions refused honestly).
 * 3. The shared `MasterDataAdminAuthorizerContract` access matrix for all
 *    three surfaces (the four back-office roles pass, everyone else fails
 *    closed).
 *
 * The scope grant fixture follows the exact shape the brief fixes:
 * `app(GrantScopeAssignment::class)(actorIdentifier, 'cemetery', cemeteryId,
 * null, reason, null)`.
 */
final class VisitationAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    // =========================================================================
    // Access matrix
    // =========================================================================

    public function test_the_policy_resource_access_matrix(): void
    {
        $this->assertFalse(CemeteryVisitationPolicyResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(CemeteryVisitationPolicyResource::canAccess());
        $this->forgetResolvedActorContext();

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                CemeteryVisitationPolicyResource::canAccess(),
                "Expected role [{$role}] to access the visitation policy resource.",
            );
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(CemeteryVisitationPolicyResource::canAccess());
    }

    public function test_the_bookings_resource_access_matrix(): void
    {
        $this->assertFalse(VisitationBookingsResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(VisitationBookingsResource::canAccess());
        $this->forgetResolvedActorContext();

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                VisitationBookingsResource::canAccess(),
                "Expected role [{$role}] to access the visitation bookings resource.",
            );
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(VisitationBookingsResource::canAccess());
    }

    public function test_the_blackout_relation_manager_access_matrix(): void
    {
        $policy = $this->policy($this->cemetery());

        $this->assertFalse(BlackoutDatesRelationManager::canViewForRecord($policy, EditCemeteryVisitationPolicy::class));

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                BlackoutDatesRelationManager::canViewForRecord($policy, EditCemeteryVisitationPolicy::class),
                "Expected role [{$role}] to view the blackout relation manager.",
            );
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(BlackoutDatesRelationManager::canViewForRecord($policy, EditCemeteryVisitationPolicy::class));
    }

    // =========================================================================
    // Policy resource — single policy per cemetery, audited writes
    // =========================================================================

    public function test_creating_a_policy_for_a_cemetery_writes_the_policy_and_audits(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();

        Livewire::test(CreateCemeteryVisitationPolicy::class)
            ->fillForm($this->policyFormData($cemetery))
            ->call('create')
            ->assertHasNoFormErrors();

        $policy = CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->id)->sole();

        $this->assertSame(10, $policy->daily_capacity);
        $this->assertSame(['08:00', '17:00'], [$policy->operating_hours['mon']['open'], $policy->operating_hours['mon']['close']]);
        $this->assertNull($policy->operating_hours['sun']);

        $event = AuditEvent::query()
            ->where('action', VisitationAuditActions::CEMETERY_VISITATION_POLICY_UPDATED)
            ->where('subject_type', 'cemetery_visitation_policy')
            ->where('subject_id', (string) $policy->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_a_cemetery_cannot_get_a_second_policy(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();
        $this->policy($cemetery);

        Livewire::test(CreateCemeteryVisitationPolicy::class)
            ->fillForm($this->policyFormData($cemetery))
            ->call('create')
            ->assertHasFormErrors(['cemetery_id']);

        $this->assertSame(1, CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->id)->count());
    }

    public function test_editing_a_policy_updates_the_hours_and_audits(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery);

        Livewire::test(EditCemeteryVisitationPolicy::class, ['record' => $policy->getKey()])
            ->fillForm([
                'daily_capacity' => '25',
                'hours_mon_enabled' => false,
                'hours_tue_enabled' => true,
                'hours_tue_open' => '09:00',
                'hours_tue_close' => '15:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $policy->refresh();

        $this->assertSame(25, $policy->daily_capacity);
        $this->assertNull($policy->operating_hours['mon']);
        $this->assertSame(['open' => '09:00', 'close' => '15:00'], $policy->operating_hours['tue']);

        $this->assertDatabaseHas('audit_events', [
            'action' => VisitationAuditActions::CEMETERY_VISITATION_POLICY_UPDATED,
            'subject_type' => 'cemetery_visitation_policy',
            'subject_id' => (string) $policy->id,
        ]);
    }

    // =========================================================================
    // Policy resource — per-cemetery scoping (the whole-branch review fix)
    // =========================================================================

    public function test_a_cemetery_granted_operator_sees_only_their_cemetery_policies(): void
    {
        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::OPERATOR);
        $this->actingAs($operator);

        $theirCemetery = $this->cemetery();
        $otherCemetery = $this->cemetery();
        $theirPolicy = $this->policy($theirCemetery);
        $otherPolicy = $this->policy($otherCemetery);

        app(GrantScopeAssignment::class)(
            (string) $operator->id,
            ScopeEntityType::CEMETERY,
            (string) $theirCemetery->id,
            null,
            'Test fixture: the operator is assigned to one cemetery.',
            null,
        );

        $this->forgetResolvedActorContext();

        $visible = CemeteryVisitationPolicyResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains((string) $theirPolicy->id, $visible);
        $this->assertNotContains((string) $otherPolicy->id, $visible);
    }

    public function test_an_admin_sees_policies_from_all_cemeteries(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $first = $this->cemetery();
        $second = $this->cemetery();
        $policyA = $this->policy($first);
        $policyB = $this->policy($second);

        $this->forgetResolvedActorContext();

        $visible = CemeteryVisitationPolicyResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains((string) $policyA->id, $visible);
        $this->assertContains((string) $policyB->id, $visible);
    }

    public function test_the_create_select_offers_only_policy_less_cemeteries_the_actor_can_reach(): void
    {
        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::OPERATOR);
        $this->actingAs($operator);

        $grantedOpen = $this->cemetery();
        $grantedWithPolicy = $this->cemetery();
        $this->policy($grantedWithPolicy);
        $ungrantedOpen = $this->cemetery();
        $ungrantedWithPolicy = $this->cemetery();
        $this->policy($ungrantedWithPolicy);

        app(GrantScopeAssignment::class)(
            (string) $operator->id,
            ScopeEntityType::CEMETERY,
            (string) $grantedOpen->id,
            null,
            'Test fixture: the operator is assigned to one cemetery.',
            null,
        );
        app(GrantScopeAssignment::class)(
            (string) $operator->id,
            ScopeEntityType::CEMETERY,
            (string) $grantedWithPolicy->id,
            null,
            'Test fixture: the operator is assigned to one cemetery.',
            null,
        );

        $this->forgetResolvedActorContext();

        $html = (string) Livewire::test(CreateCemeteryVisitationPolicy::class)->html();

        $this->assertStringContainsString($grantedOpen->name, $html);
        $this->assertStringNotContainsString($grantedWithPolicy->name, $html);
        $this->assertStringNotContainsString($ungrantedOpen->name, $html);
        $this->assertStringNotContainsString($ungrantedWithPolicy->name, $html);
    }

    // =========================================================================
    // Blackout relation manager — required reason, audited create/delete
    // =========================================================================

    public function test_blackout_create_requires_a_reason(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $policy = $this->policy($this->cemetery());

        Livewire::test(BlackoutDatesRelationManager::class, [
            'ownerRecord' => $policy,
            'pageClass' => EditCemeteryVisitationPolicy::class,
        ])
            ->callTableAction('create', data: ['date' => '2026-09-01'])
            ->assertHasTableActionErrors();

        $this->assertSame(0, VisitationBlackoutDate::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', VisitationAuditActions::VISITATION_BLACKOUT_CREATED)->count());
    }

    public function test_blackout_create_with_a_reason_writes_the_row_and_audits(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $policy = $this->policy($this->cemetery());

        Livewire::test(BlackoutDatesRelationManager::class, [
            'ownerRecord' => $policy,
            'pageClass' => EditCemeteryVisitationPolicy::class,
        ])
            ->callTableAction('create', data: [
                'date' => '2026-09-01',
                'reason' => 'Pemeliharaan tahunan',
            ])
            ->assertHasNoTableActionErrors();

        $blackout = VisitationBlackoutDate::query()->where('policy_id', $policy->id)->sole();

        $this->assertSame('2026-09-01', $blackout->date->toDateString());
        $this->assertSame('Pemeliharaan tahunan', $blackout->reason);

        $event = AuditEvent::query()
            ->where('action', VisitationAuditActions::VISITATION_BLACKOUT_CREATED)
            ->where('subject_type', 'visitation_blackout_date')
            ->where('subject_id', (string) $blackout->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('panel', $event->source);
    }

    public function test_blackout_delete_writes_the_row_removal_and_audits(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $policy = $this->policy($this->cemetery());
        $blackout = VisitationBlackoutDate::query()->create([
            'policy_id' => $policy->id,
            'date' => '2026-09-01',
            'reason' => 'Pemeliharaan tahunan',
        ]);

        Livewire::test(BlackoutDatesRelationManager::class, [
            'ownerRecord' => $policy,
            'pageClass' => EditCemeteryVisitationPolicy::class,
        ])
            ->callTableAction('delete', $blackout);

        $this->assertSame(0, VisitationBlackoutDate::query()->count());

        $this->assertDatabaseHas('audit_events', [
            'action' => VisitationAuditActions::VISITATION_BLACKOUT_DELETED,
            'subject_type' => 'visitation_blackout_date',
            'subject_id' => (string) $blackout->id,
            'actor_ref' => (string) $user->id,
            'source' => 'panel',
        ]);
    }

    // =========================================================================
    // Bookings resource — per-cemetery scoping
    // =========================================================================

    public function test_a_cemetery_granted_operator_sees_only_their_cemetery_bookings(): void
    {
        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::OPERATOR);
        $this->actingAs($operator);

        $theirCemetery = $this->cemetery();
        $otherCemetery = $this->cemetery();
        $this->policy($theirCemetery);
        $this->policy($otherCemetery);

        $theirs = $this->requestBooking($theirCemetery, '0812-1111-1111');
        $others = $this->requestBooking($otherCemetery, '0812-2222-2222');

        app(GrantScopeAssignment::class)(
            (string) $operator->id,
            ScopeEntityType::CEMETERY,
            (string) $theirCemetery->id,
            null,
            'Test fixture: the operator is assigned to one cemetery.',
            null,
        );

        $this->forgetResolvedActorContext();

        $visible = VisitationBookingsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains((string) $theirs->id, $visible);
        $this->assertNotContains((string) $others->id, $visible);

        Livewire::test(ListVisitationBookings::class)
            ->assertSee($theirs->reference)
            ->assertDontSee($others->reference);
    }

    public function test_an_admin_sees_bookings_from_all_cemeteries(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $first = $this->cemetery();
        $second = $this->cemetery();
        $this->policy($first);
        $this->policy($second);

        $bookingA = $this->requestBooking($first, '0812-1111-1111');
        $bookingB = $this->requestBooking($second, '0812-2222-2222');

        $this->forgetResolvedActorContext();

        $visible = VisitationBookingsResource::getEloquentQuery()->pluck('id')->all();

        $this->assertContains((string) $bookingA->id, $visible);
        $this->assertContains((string) $bookingB->id, $visible);

        Livewire::test(ListVisitationBookings::class)
            ->assertSee($bookingA->reference)
            ->assertSee($bookingB->reference);
    }

    // =========================================================================
    // Status transitions — ChangeVisitationBookingStatus through the table
    // =========================================================================

    public function test_confirm_transitions_to_confirmed_and_emits_the_outbox_event(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();
        $this->policy($cemetery);
        $booking = $this->requestBooking($cemetery, '0812-3456-7890');

        Livewire::test(ListVisitationBookings::class)
            ->callTableAction('confirm', $booking)
            ->assertNotified();

        $this->assertSame(VisitationBookingStatus::CONFIRMED, $booking->fresh()->status);

        $event = AuditEvent::query()
            ->where('action', VisitationAuditActions::VISITATION_STATUS_CHANGED)
            ->where('subject_type', 'visitation_booking')
            ->where('subject_id', (string) $booking->id)
            ->sole();

        $this->assertSame('requested', $event->metadata['previous_state']);
        $this->assertSame('confirmed', $event->metadata['new_state']);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'visit.booking_confirmed.v1',
            'aggregate_type' => 'visitation_booking',
            'aggregate_id' => (string) $booking->id,
            'classification' => 'INTERNAL',
            'idempotency_key' => "visitation_booking:{$booking->id}:confirmed",
        ]);
    }

    public function test_cancel_is_allowed_from_requested_and_confirmed(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();
        $this->policy($cemetery);

        $requested = $this->requestBooking($cemetery, '0812-1111-1111');
        $confirmed = $this->requestBooking($cemetery, '0812-2222-2222');
        app(ChangeVisitationBookingStatus::class)(
            $confirmed,
            VisitationBookingStatus::CONFIRMED,
            (string) $user->id,
            'admin',
        );

        Livewire::test(ListVisitationBookings::class)
            ->callTableAction('cancel', $requested)
            ->callTableAction('cancel', $confirmed);

        $this->assertSame(VisitationBookingStatus::CANCELLED, $requested->fresh()->status);
        $this->assertSame(VisitationBookingStatus::CANCELLED, $confirmed->fresh()->status);
        $this->assertSame(2, AuditEvent::query()
            ->where('action', VisitationAuditActions::VISITATION_STATUS_CHANGED)
            ->where('metadata->new_state', VisitationBookingStatus::CANCELLED)
            ->count());
    }

    public function test_no_show_is_allowed_only_from_requested(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = $this->cemetery();
        $this->policy($cemetery);

        $requested = $this->requestBooking($cemetery, '0812-1111-1111');
        $confirmed = $this->requestBooking($cemetery, '0812-2222-2222');
        app(ChangeVisitationBookingStatus::class)(
            $confirmed,
            VisitationBookingStatus::CONFIRMED,
            (string) $user->id,
            'admin',
        );

        Livewire::test(ListVisitationBookings::class)
            ->callTableAction('no_show', $requested)
            ->assertTableActionHidden('no_show', $confirmed);

        $this->assertSame(VisitationBookingStatus::NO_SHOW, $requested->fresh()->status);
        $this->assertSame(VisitationBookingStatus::CONFIRMED, $confirmed->fresh()->status);
    }

    public function test_a_late_transition_is_refused_honestly_without_a_write(): void
    {
        $cemetery = $this->cemetery();
        $this->policy($cemetery);
        $booking = $this->requestBooking($cemetery, '0812-3456-7890');

        app(ChangeVisitationBookingStatus::class)(
            $booking,
            VisitationBookingStatus::CONFIRMED,
            'actor:admin',
            'admin',
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('requested');

        app(ChangeVisitationBookingStatus::class)(
            $booking,
            VisitationBookingStatus::NO_SHOW,
            'actor:admin',
            'admin',
        );
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Kunjungan '.Str::lower(Str::random(6)),
            'slug' => 'tpu-uji-kunjungan-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function policy(Cemetery $cemetery, array $overrides = []): CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->create(array_merge([
            'cemetery_id' => $cemetery->id,
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
        ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function policyFormData(Cemetery $cemetery): array
    {
        $data = [
            'cemetery_id' => (string) $cemetery->id,
            'daily_capacity' => '10',
        ];

        foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $key) {
            $data["hours_{$key}_enabled"] = true;
            $data["hours_{$key}_open"] = '08:00';
            $data["hours_{$key}_close"] = '17:00';
        }

        $data['hours_sun_enabled'] = false;

        return $data;
    }

    private function requestBooking(Cemetery $cemetery, string $phone): VisitationBooking
    {
        return app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            2,
            $phone,
            null,
            null,
            [],
            'admin-fixture-'.Str::random(8),
            'actor:admin',
        );
    }
}
