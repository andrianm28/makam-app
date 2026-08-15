<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Filament\Admin\Resources\LaunchCities\LaunchCityResource;
use App\Filament\Admin\Resources\LaunchCities\Pages\CreateLaunchCity;
use App\Filament\Admin\Resources\LaunchCities\Pages\EditLaunchCity;
use App\Filament\Admin\Resources\LaunchCities\Pages\ListLaunchCities;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Proves the LaunchCityResource end to end: the shared master-data role
 * gate (the four back-office roles pass, a vendor and everyone else fail
 * closed), the audited create/update/delete write paths, the adjacent-swap
 * reorder with its audit row, the honest delete denial when a booking
 * draft references the city code, and the public-query effect of the
 * active toggle.
 *
 * ---------------------------------------------------------------------------
 * Every actor here is granted `ActorRole::ADMIN` first — not boilerplate
 * ---------------------------------------------------------------------------
 * Same reasoning as `CemeteryResourceCrudTest`: `canAccess()` refuses every
 * actor without one of the four back-office roles, so the grant is what
 * lets these tests reach their subject at all. The refusal side is proved
 * in `test_vendor_and_non_back_office_actors_are_denied`.
 *
 * ---------------------------------------------------------------------------
 * The audit row is what proves the write went through the audited path
 * ---------------------------------------------------------------------------
 * All four write verbs route through `Audit::wrap` (the task brief's
 * requirement): create/update via the pages' `handleRecordCreation()` /
 * `handleRecordUpdate()` overrides, delete via the `DeleteAction` closure,
 * reorder via the table's swap helper — so asserting both the row change
 * AND its `audit_events` entry proves the paired mutation+audit rather
 * than a bare model save.
 *
 * ---------------------------------------------------------------------------
 * Booking drafts are created directly, deliberately
 * ---------------------------------------------------------------------------
 * `BookingDraft`'s own doc block bans `BookingDraft::create()` from
 * production call sites outside `app/Domain/Booking/Actions/` — tests are
 * not that call site (the domain tests themselves construct drafts
 * directly, e.g. `BookingDraftQueryTest`), and the delete-blocked test
 * needs a draft row whose `city_code` is a launch city. The model's saving
 * hook validates the code through `LaunchCityQuery::isKnown()`, which is
 * exactly the reference the delete check mirrors.
 */
final class LaunchCityResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function validCreateData(): array
    {
        return [
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 6,
        ];
    }

    // -----------------------------------------------------------------------
    // Access matrix
    // -----------------------------------------------------------------------

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(LaunchCityResource::canAccess());

        $this->actingAs(User::factory()->create());

        $this->assertFalse(LaunchCityResource::canAccess());
    }

    public function test_the_four_back_office_roles_can_access(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(LaunchCityResource::canAccess(), "Expected role [{$role}] to access the launch city resource.");

            $this->forgetResolvedActorContext();
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);

        $this->assertFalse(LaunchCityResource::canAccess());
    }

    // -----------------------------------------------------------------------
    // Create / update / delete, each with its audit row
    // -----------------------------------------------------------------------

    public function test_an_authorized_admin_can_create_a_launch_city_with_an_audit_trail(): void
    {
        $user = $this->admin();

        Livewire::test(CreateLaunchCity::class)
            ->fillForm($this->validCreateData())
            ->call('create')
            ->assertHasNoFormErrors();

        $city = LaunchCity::query()->where('code', 'SUKABUMI')->sole();

        $this->assertSame('Sukabumi', $city->label);
        $this->assertTrue($city->is_active);
        $this->assertSame(6, $city->sort_order);

        $event = AuditEvent::query()
            ->where('action', 'LAUNCH_CITY_CREATED')
            ->where('subject_id', (string) $city->id)
            ->sole();

        $this->assertSame('launch_city', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame(['new_state' => true], $event->metadata);
    }

    public function test_an_authorized_admin_can_update_a_launch_city_with_an_audit_trail(): void
    {
        $user = $this->admin();

        $city = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 6,
        ]);

        Livewire::test(EditLaunchCity::class, ['record' => $city->getRouteKey()])
            ->fillForm([
                'label' => 'Kota Sukabumi',
                'is_active' => false,
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $city->refresh();

        $this->assertSame('Kota Sukabumi', $city->label);
        $this->assertFalse($city->is_active);

        $event = AuditEvent::query()
            ->where('action', 'LAUNCH_CITY_UPDATED')
            ->where('subject_id', (string) $city->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame(
            ['previous_state' => true, 'new_state' => false],
            $event->metadata,
        );
    }

    public function test_an_authorized_admin_can_delete_a_launch_city_with_an_audit_trail(): void
    {
        $user = $this->admin();

        $city = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 6,
        ]);

        Livewire::test(EditLaunchCity::class, ['record' => $city->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Kota dihapus.');

        $this->assertDatabaseMissing('launch_cities', ['id' => $city->id]);

        $event = AuditEvent::query()
            ->where('action', 'LAUNCH_CITY_DELETED')
            ->where('subject_id', (string) $city->id)
            ->sole();

        $this->assertSame('launch_city', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_a_duplicate_code_fails_create_validation(): void
    {
        $this->admin();

        $existingCode = LaunchCity::query()->value('code');
        $countBefore = LaunchCity::query()->count();

        Livewire::test(CreateLaunchCity::class)
            ->fillForm([
                ...$this->validCreateData(),
                'code' => $existingCode,
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'unique']);

        $this->assertSame($countBefore, LaunchCity::query()->count());
    }

    public function test_a_lowercase_code_fails_create_validation(): void
    {
        $this->admin();

        Livewire::test(CreateLaunchCity::class)
            ->fillForm([
                ...$this->validCreateData(),
                'code' => 'sukabumi',
            ])
            ->call('create')
            ->assertHasFormErrors(['code' => 'regex']);

        $this->assertDatabaseMissing('launch_cities', ['code' => 'sukabumi']);
    }

    // -----------------------------------------------------------------------
    // Reorder: adjacent swap + audit
    // -----------------------------------------------------------------------

    public function test_move_down_swaps_sort_orders_with_the_next_city_and_audits_the_swap(): void
    {
        $user = $this->admin();

        $upper = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $lower = LaunchCity::query()->create([
            'code' => 'CIREBON',
            'label' => 'Cirebon',
            'is_active' => true,
            'sort_order' => 11,
        ]);

        Livewire::test(ListLaunchCities::class)
            ->callTableAction('moveDown', $upper)
            ->assertNotified('Urutan kota diperbarui.');

        $this->assertSame(11, $upper->refresh()->sort_order);
        $this->assertSame(10, $lower->refresh()->sort_order);

        $event = AuditEvent::query()
            ->where('action', 'LAUNCH_CITY_REORDERED')
            ->where('subject_id', (string) $upper->id)
            ->sole();

        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame(
            ['previous_state' => 10, 'new_state' => 11],
            $event->metadata,
        );
    }

    public function test_move_up_swaps_sort_orders_with_the_previous_city(): void
    {
        $this->admin();

        $upper = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 10,
        ]);

        $lower = LaunchCity::query()->create([
            'code' => 'CIREBON',
            'label' => 'Cirebon',
            'is_active' => true,
            'sort_order' => 11,
        ]);

        Livewire::test(ListLaunchCities::class)
            ->callTableAction('moveUp', $lower);

        $this->assertSame(11, $upper->refresh()->sort_order);
        $this->assertSame(10, $lower->refresh()->sort_order);
    }

    // -----------------------------------------------------------------------
    // Delete blocked by a referencing booking draft
    // -----------------------------------------------------------------------

    public function test_deleting_a_launch_city_referenced_by_a_booking_draft_is_honest(): void
    {
        $this->admin();

        $city = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 6,
        ]);

        BookingDraft::query()->create(['city_code' => 'SUKABUMI']);

        Livewire::test(EditLaunchCity::class, ['record' => $city->getRouteKey()])
            ->callAction('delete')
            ->assertNotified('Kota tidak dapat dihapus.');

        $this->assertDatabaseHas('launch_cities', ['id' => $city->id]);

        // A refused delete is not a mutation, so it must not leave an
        // audit row claiming one.
        $this->assertDatabaseMissing('audit_events', ['action' => 'LAUNCH_CITY_DELETED']);
    }

    // -----------------------------------------------------------------------
    // Active-toggle effect on the public query seam
    // -----------------------------------------------------------------------

    public function test_deactivating_a_launch_city_removes_it_from_the_active_city_query(): void
    {
        $this->admin();

        $city = LaunchCity::query()->create([
            'code' => 'SUKABUMI',
            'label' => 'Sukabumi',
            'is_active' => true,
            'sort_order' => 6,
        ]);

        Livewire::test(EditLaunchCity::class, ['record' => $city->getRouteKey()])
            ->fillForm(['is_active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $codes = array_column(LaunchCityQuery::activeCities(), 'code');

        $this->assertNotContains('SUKABUMI', $codes);

        // The city stays "known" (drafts referencing it remain valid) even
        // while inactive.
        $this->assertTrue(LaunchCityQuery::isKnown('SUKABUMI'));
    }
}
