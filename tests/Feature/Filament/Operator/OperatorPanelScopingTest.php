<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Filament\Operator\Concerns\ScopesToCurrentCemetery;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * `CurrentCemeteryScope`/`ScopesToCurrentCemetery` — the query-level half of
 * AC4's "SHALL NOT grant record access on panel membership alone", proven
 * against real Postgres rows rather than mocks.
 *
 * Phase C added the resource-walking structural test below, mirroring
 * `VendorPanelScopingTest::test_every_resource_in_the_panel_applies_the_vendor_scope`:
 * every concrete class under `app/Filament/Operator/**` is discovered from
 * disk and checked for `ScopesToCurrentCemetery`, so a future unscoped
 * `/operator` Resource fails CI without anyone remembering to list it here.
 *
 * `CemeteryBlock` (already shipped, `App\Domain\PlotInventory\Models
 * \CemeteryBlock`, `cemetery_id` column) remains the stand-in for the
 * trait's direct-column default path above — `CemeteryOrderResource`
 * (Phase C's first real Resource) reaches its cemetery indirectly via
 * `bookingDraft.cemetery_id` and overrides `applyCemeteryScope()` instead of
 * using that default, so it does not exercise this class's column-default
 * behaviour and `CemeteryBlockScopeFixture` below still carries that proof.
 */
final class OperatorPanelScopingTest extends TestCase
{
    use RefreshDatabase;

    private string $ownCemeteryId;

    private string $otherCemeteryId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ownCemeteryId = (string) Cemetery::factory()->create()->id;
        $this->otherCemeteryId = (string) Cemetery::factory()->create()->id;

        CemeteryBlock::query()->create([
            'cemetery_id' => $this->ownCemeteryId,
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 10,
            'is_active' => true,
        ]);

        CemeteryBlock::query()->create([
            'cemetery_id' => $this->otherCemeteryId,
            'code' => 'BLOK-B',
            'name' => 'Blok B',
            'capacity' => 10,
            'is_active' => true,
        ]);
    }

    // -----------------------------------------------------------------
    // CurrentCemeteryScope — the grant-reading half
    // -----------------------------------------------------------------

    public function test_a_guest_has_no_granted_cemeteries(): void
    {
        $this->assertSame([], app(CurrentCemeteryScope::class)->grantedCemeteryIds());
        $this->assertFalse(app(CurrentCemeteryScope::class)->hasAnyGrant());
    }

    public function test_a_granted_operator_sees_only_their_own_cemetery_id(): void
    {
        $this->actingAsOperatorGrantedTo($this->ownCemeteryId);

        $this->assertSame([$this->ownCemeteryId], app(CurrentCemeteryScope::class)->grantedCemeteryIds());
        $this->assertTrue(app(CurrentCemeteryScope::class)->allows($this->ownCemeteryId));
        $this->assertFalse(app(CurrentCemeteryScope::class)->allows($this->otherCemeteryId));
    }

    public function test_default_cemetery_id_is_null_when_the_actor_holds_no_or_several_grants(): void
    {
        $this->assertNull(app(CurrentCemeteryScope::class)->defaultCemeteryId());

        $user = $this->actingAsOperatorGrantedTo($this->ownCemeteryId);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => $this->otherCemeteryId,
        ]);
        $this->refreshActorContext();

        $this->assertNull(app(CurrentCemeteryScope::class)->defaultCemeteryId());
    }

    public function test_default_cemetery_id_resolves_when_exactly_one_grant_exists(): void
    {
        $this->actingAsOperatorGrantedTo($this->ownCemeteryId);

        $this->assertSame($this->ownCemeteryId, app(CurrentCemeteryScope::class)->defaultCemeteryId());
    }

    // -----------------------------------------------------------------
    // ScopesToCurrentCemetery — the query-restriction half
    // -----------------------------------------------------------------

    public function test_the_trait_restricts_a_real_query_to_the_granted_cemetery_only(): void
    {
        $this->actingAsOperatorGrantedTo($this->ownCemeteryId);

        $scoped = CemeteryBlockScopeFixture::applyCemeteryScope(CemeteryBlock::query())->get();

        $this->assertCount(1, $scoped);
        $this->assertSame($this->ownCemeteryId, (string) $scoped->first()->cemetery_id);
    }

    public function test_the_trait_returns_nothing_for_an_actor_with_no_grant(): void
    {
        $this->actingAs(User::factory()->create());
        $this->refreshActorContext();

        $scoped = CemeteryBlockScopeFixture::applyCemeteryScope(CemeteryBlock::query())->get();

        $this->assertCount(0, $scoped);
    }

    public function test_the_trait_default_scope_column_is_cemetery_id(): void
    {
        $this->assertSame('cemetery_id', CemeteryBlockScopeFixture::cemeteryScopeColumn());
    }

    // -----------------------------------------------------------------
    // Structural walk — every /operator surface must be scoped, forever
    // -----------------------------------------------------------------

    public function test_the_orders_resource_is_registered_and_scoped(): void
    {
        // Named explicitly as well as walked, so a rename or an accidental
        // removal of the resource fails here with a clear message rather
        // than by the walk quietly finding nothing to check.
        $this->assertContains(
            ScopesToCurrentCemetery::class,
            class_uses_recursive(CemeteryOrderResource::class),
        );
    }

    public function test_every_resource_in_the_operator_panel_applies_the_cemetery_scope(): void
    {
        $resources = $this->panelClassesThatAre(Resource::class);

        $this->assertNotEmpty($resources, 'Expected at least one /operator Resource to walk.');

        foreach ($resources as $resource) {
            $this->assertContains(
                ScopesToCurrentCemetery::class,
                class_uses_recursive($resource),
                "[{$resource}] is an /operator Resource that does not apply ScopesToCurrentCemetery. "
                .'Every surface in this panel must scope its own query — panel membership is not record access (AC4).'
            );
        }
    }

    public function test_every_table_page_in_the_operator_panel_applies_the_cemetery_scope(): void
    {
        $allPages = $this->panelClassesThatAre(Page::class);

        // Canary on the UNFILTERED discovery, independent of whether any
        // standalone table page exists today (it legitimately does not
        // yet). Without this, a broken filter predicate below — inverted
        // `!`, wrong interface — would silently reduce $pages to empty
        // forever and this test would never be able to fail on its own
        // defects, discovery-walk or otherwise.
        $this->assertNotEmpty($allPages, 'Discovered no Page classes — the discovery walk is broken.');

        // A Resource's own List/View pages are Page subclasses that render a
        // table, but their query comes from the Resource, which the walk
        // above already checks — so they are excluded here. Only standalone
        // table pages, which own their query, are walked.
        $pages = array_filter(
            $allPages,
            static fn (string $page): bool => is_subclass_of($page, HasTable::class)
                && ! is_subclass_of($page, \Filament\Resources\Pages\Page::class),
        );

        foreach ($pages as $page) {
            $this->assertContains(
                ScopesToCurrentCemetery::class,
                class_uses_recursive($page),
                "[{$page}] is an /operator table page that does not apply ScopesToCurrentCemetery."
            );
        }
    }

    /**
     * Every concrete class under `app/Filament/Operator/**` that is a
     * subclass of `$base`. Filesystem-driven on purpose: a class added to
     * the panel later is walked without anyone remembering to list it here.
     *
     * @param  class-string  $base
     * @return list<class-string>
     */
    private function panelClassesThatAre(string $base): array
    {
        $found = [];

        foreach (Finder::create()->files()->in(app_path('Filament/Operator'))->name('*.php') as $file) {
            $class = 'App\\Filament\\Operator\\'.str_replace(
                ['/', '.php'],
                ['\\', ''],
                $file->getRelativePathname(),
            );

            if (! class_exists($class) || ! is_subclass_of($class, $base)) {
                continue;
            }

            $found[] = $class;
        }

        return $found;
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function actingAsOperatorGrantedTo(string $cemeteryId): User
    {
        $user = User::factory()->create();

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => $cemeteryId,
        ]);

        $this->actingAs($user);
        $this->refreshActorContext();

        return $user;
    }

    /**
     * `ActorContext`/`ScopeAssignmentResolver` are `scoped()` container
     * bindings, resolved once per request and cached — same reasoning as
     * `VendorPanelScopingTest::refreshActorContext()`.
     */
    private function refreshActorContext(): void
    {
        $this->app->forgetScopedInstances();
    }
}

/**
 * Test-only fixture proving `ScopesToCurrentCemetery::applyCemeteryScope()`
 * works against a real Eloquent query without needing a full Filament
 * Resource base class. `getEloquentQuery()`'s `parent::` call (the override
 * a real Resource needs) is exercised for real only once Phase C's
 * `CemeteryOrderResource` lands — this fixture intentionally does not
 * attempt to fake that.
 */
final class CemeteryBlockScopeFixture
{
    use ScopesToCurrentCemetery;
}
