<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Resources\CemeteryResource\Pages\EditCemetery;
use App\Filament\Admin\Resources\CemeteryResource\RelationManagers\BlocksRelationManager;
use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use App\Filament\Admin\Resources\GravePlots\Pages\ListGravePlots;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The Task 2 admin surfaces for PlotInventory:
 *
 * 1. Access matrix for BOTH surfaces — `GravePlotsResource::canAccess()`
 *    (page-mount gate) and `BlocksRelationManager::canViewForRecord()`
 *    (relation-manager gate): the four back-office roles pass, a vendor
 *    (and any other non-master-data actor) fails closed.
 * 2. Block create THROUGH the relation-manager wire route
 *    (`callTableAction` against a mounted `BlocksRelationManager` with the
 *    cemetery as owner): `CreateCemeteryBlock` runs, plots are generated,
 *    and the two audit rows (`CEMETERY_BLOCK_CREATED` +
 *    `GRAVE_PLOTS_GENERATED`) land with the acting actor's identity — the
 *    action self-audits, the relation manager does not double-wrap.
 * 3. Plot state overrides ('Tandai Terisi'/'Tandai Perawatan'/'Tandai
 *    Tersedia') flip `plot_state` and write `GRAVE_PLOT_STATE_CHANGED`,
 *    with per-state action visibility, the recent-re-authentication gate
 *    (AGENTS.md: plot-override actions), and no delete action anywhere.
 * 4. Deleting a plot is blocked by the model guard (honest
 *    `InvalidArgumentException` for a non-available plot; the
 *    `plot_reservations` history arm of the guard lands with lane 2).
 *
 * ---------------------------------------------------------------------------
 * Re-authentication fixture
 * ---------------------------------------------------------------------------
 * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()` reads
 * `actor_sessions.last_authenticated_at` — the same fixture
 * `FeatureGateAdminTest::seedActorSession()` uses. The override happy paths
 * seed a fresh row; the refusal test leaves the row stale so
 * `ReauthenticationGuard::assertFresh()` fails closed and the override
 * refuses before any write.
 */
final class PlotInventoryAdminTest extends TestCase
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
        $this->seedActorSession($user, CarbonImmutable::now());

        return $user;
    }

    /**
     * @return array{0: Cemetery, 1: CemeteryBlock, 2: User}
     */
    private function cemeteryWithBlock(int $capacity = 2, ?User $user = null): array
    {
        $actor = $user ?? $this->admin();

        $cemetery = Cemetery::factory()->create();
        $block = app(CreateCemeteryBlock::class)(
            $cemetery,
            'BLOK-A',
            'Blok A',
            $capacity,
            $actor->id,
            'admin',
        );

        return [$cemetery, $block, $actor];
    }

    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    public function test_grave_plots_resource_access_matrix(): void
    {
        $this->assertFalse(GravePlotsResource::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(GravePlotsResource::canAccess());
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

            $this->assertTrue(GravePlotsResource::canAccess(), "Expected role [{$role}] to access the grave plots resource.");
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(GravePlotsResource::canAccess());
    }

    public function test_blocks_relation_manager_access_matrix(): void
    {
        $cemetery = Cemetery::factory()->create();

        $this->assertFalse(BlocksRelationManager::canViewForRecord($cemetery, EditCemetery::class));

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
                BlocksRelationManager::canViewForRecord($cemetery, EditCemetery::class),
                "Expected role [{$role}] to view the blocks relation manager.",
            );
            $this->forgetResolvedActorContext();
        }

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(BlocksRelationManager::canViewForRecord($cemetery, EditCemetery::class));
    }

    public function test_block_create_via_the_relation_manager_generates_plots_and_audit_rows(): void
    {
        $user = $this->admin();

        $cemetery = Cemetery::factory()->create();
        $package = $cemetery->packages()->create([
            'name' => 'Paket Utama',
            'availability_status' => CemeteryPackageAvailabilityStatus::AVAILABLE,
            'is_active' => true,
        ]);

        $this->forgetResolvedActorContext();

        Livewire::test(BlocksRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ])
            ->callTableAction('create', data: [
                'code' => 'blok-b',
                'name' => 'Blok B',
                'capacity' => 4,
                'cemetery_package_id' => $package->getKey(),
                'is_active' => false,
            ])
            ->assertHasNoTableActionErrors();

        $block = CemeteryBlock::query()
            ->where('cemetery_id', $cemetery->id)
            ->where('code', 'BLOK-B')
            ->sole();

        $this->assertSame('Blok B', $block->name);
        $this->assertSame(4, $block->capacity);
        $this->assertFalse($block->is_active);

        $plots = $block->plots()->orderBy('slot')->get();
        $this->assertCount(4, $plots);
        $this->assertSame(['001', '002', '003', '004'], $plots->pluck('slot')->all());
        foreach ($plots as $plot) {
            $this->assertSame(PlotState::AVAILABLE, $plot->plot_state);
            $this->assertSame($package->getKey(), $plot->cemetery_package_id);
        }

        foreach (['CEMETERY_BLOCK_CREATED', 'GRAVE_PLOTS_GENERATED'] as $action) {
            $event = AuditEvent::query()
                ->where('action', $action)
                ->where('subject_id', (string) $block->id)
                ->sole();

            $this->assertSame('cemetery_block', $event->subject_type);
            $this->assertSame((string) $user->id, $event->actor_ref);
            $this->assertSame('admin', $event->actor_role);
            $this->assertSame('panel', $event->source);
            $this->assertSame('allowed', $event->outcome);
        }
    }

    public function test_a_customer_cannot_interact_with_the_blocks_relation_manager(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $cemetery = Cemetery::factory()->create();

        $component = Livewire::test(BlocksRelationManager::class, [
            'ownerRecord' => $cemetery,
            'pageClass' => EditCemetery::class,
        ]);

        $component
            ->assertTableActionHidden('create')
            ->call('refresh')
            ->assertForbidden();
    }

    public function test_mark_occupied_flips_the_state_and_audits(): void
    {
        $user = $this->admin();
        [, $block] = $this->cemeteryWithBlock(capacity: 2, user: $user);
        $plot = $block->plots()->orderBy('slot')->firstOrFail();

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->callTableAction('markOccupied', $plot)
            ->assertNotified('Plot ditandai terisi.');

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()->plot_state);

        $event = AuditEvent::query()
            ->where('action', 'GRAVE_PLOT_STATE_CHANGED')
            ->where('subject_id', (string) $plot->id)
            ->sole();

        $this->assertSame('grave_plot', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
    }

    public function test_maintenance_then_available_override_round_trips_with_audits(): void
    {
        $user = $this->admin();
        [, $block] = $this->cemeteryWithBlock(capacity: 1, user: $user);
        $plot = $block->plots()->sole();

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->callTableAction('markMaintenance', $plot)
            ->assertNotified('Plot ditandai perawatan.');

        $this->assertSame(PlotState::MAINTENANCE, $plot->fresh()->plot_state);

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->callTableAction('markAvailable', $plot)
            ->assertNotified('Plot ditandai tersedia.');

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);

        $this->assertSame(
            2,
            AuditEvent::query()
                ->where('action', 'GRAVE_PLOT_STATE_CHANGED')
                ->where('subject_id', (string) $plot->id)
                ->count(),
        );
    }

    public function test_mark_available_is_only_offered_for_maintenance_or_occupied_plots(): void
    {
        $user = $this->admin();
        [, $block] = $this->cemeteryWithBlock(capacity: 1, user: $user);
        $plot = $block->plots()->sole();
        $plot->update(['plot_state' => PlotState::OCCUPIED]);

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->assertTableActionExists('markAvailable', null, $plot)
            ->assertTableActionHidden('markOccupied', $plot);

        $plot->update(['plot_state' => PlotState::MAINTENANCE]);

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->assertTableActionExists('markAvailable', null, $plot)
            ->assertTableActionHidden('markMaintenance', $plot);
    }

    public function test_mark_available_is_not_offered_for_available_or_reserved_plots(): void
    {
        $user = $this->admin();
        [, $block] = $this->cemeteryWithBlock(capacity: 1, user: $user);
        $plot = $block->plots()->sole();

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->assertTableActionHidden('markAvailable', $plot);

        $plot->update(['plot_state' => PlotState::RESERVED]);

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->assertTableActionHidden('markAvailable', $plot);
    }

    public function test_state_override_requires_recent_reauthentication(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());

        [, $block] = $this->cemeteryWithBlock(capacity: 1, user: $user);
        $plot = $block->plots()->sole();

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->callTableAction('markOccupied', $plot)
            ->assertNotified('Perlu verifikasi ulang');

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseMissing('audit_events', ['action' => 'GRAVE_PLOT_STATE_CHANGED']);
    }

    public function test_plot_delete_is_blocked_and_no_delete_action_is_offered(): void
    {
        $user = $this->admin();
        [, $block] = $this->cemeteryWithBlock(capacity: 1, user: $user);
        $plot = $block->plots()->sole();
        $plot->update(['plot_state' => PlotState::OCCUPIED]);

        $this->forgetResolvedActorContext();

        Livewire::test(ListGravePlots::class)
            ->assertTableActionDoesNotExist('delete');

        try {
            $plot->delete();
            $this->fail('Expected the model guard to refuse the delete.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('only plots in the', $exception->getMessage());
        }

        $this->assertDatabaseHas('grave_plots', ['id' => $plot->id]);
    }
}
