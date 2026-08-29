<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Filament\Operator\Concerns\ScopesToCurrentCemetery;
use App\Models\User;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CurrentCemeteryScope`/`ScopesToCurrentCemetery` — the query-level half of
 * AC4's "SHALL NOT grant record access on panel membership alone", proven
 * against real Postgres rows rather than mocks.
 *
 * This is deliberately NOT the full resource-walking structural test
 * `VendorPanelScopingTest` runs (that test enumerates every class under
 * `app/Filament/Vendor/**` and fails CI when one is unscoped): Phase A ships
 * a panel skeleton with zero real Resources, so there is nothing yet to
 * walk. Phase C, which adds the first real `/operator` Resource
 * (`CemeteryOrderResource`), must EXTEND this file with that structural
 * walk rather than replace it — see the roadmap's own Phase A verification
 * note.
 *
 * `CemeteryBlock` (already shipped, `App\Domain\PlotInventory\Models
 * \CemeteryBlock`, `cemetery_id` column) stands in as the real
 * cemetery-owned model here — no synthetic fixture model, no mocking.
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
