# Cemetery Operator Panel & Role (Phase A) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce the `cemetery_operator` actor role, the `/operator` Filament panel skeleton with deny-by-default cemetery scoping, and the cemetery-aware `OrderTransitionAuthorizerContract` signature the rest of the TPU/TPS operator dashboard roadmap depends on.

**Architecture:** Mirror the existing `/vendor` panel's role+scope pattern (`ActorRole::VENDOR` / `VendorPanelAccessPolicy` / `CurrentVendorScope` / `ScopesToCurrentVendor`) exactly for cemeteries, reusing the already-existing `ScopeEntityType::CEMETERY` grant type. Widen `OrderTransitionAuthorizerContract::authorizeTransition()` with an optional cemetery-id parameter so a `cemetery_operator` can be authorized per-cemetery at every call site that already has a cemetery-bearing record in scope.

**Tech Stack:** Laravel 13, Filament 5, PHP 8.5 (in the pinned CI container), Postgres 18 (tests), Pest/PHPUnit.

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` — the approved TPU/TPS operator dashboard roadmap, section "Role & scoping (Phase A)".

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- No AWS references. No hardcoded design/color values (this phase is pure backend/authorization — no UI beyond a stock Filament placeholder page).
- Every new PHP class needs real Feature or Unit test coverage run against real Postgres 18 (never SQLite), via `docs/operations/local-test-recipe.md`'s pinned-CI-image Docker recipe. `vendor/` is already hard-linked into this worktree and `composer install` already run against it — re-run inside the container if that state looks stale, never skip verification.
- This phase adds a new authorization role and changes `OrderTransitionAuthorizerContract`'s signature — per `AGENTS.md` §Infrastructure-agent execution, human review is mandatory before merge. This does not block writing/testing the code, only the final merge decision.
- Composer/npm builds do not run on this host outside the pinned container — CI only.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` must stay clean after every task.
- Never report `PASS` for a check that was not actually executed against the real Postgres container.

## Known, deliberate incompleteness carried into Phase C (do not silently "fix" these in this plan)

1. **`ReservePlotAction::roleAllowed()` composes `BookingOrderResource::canAccess()` first**, which is gated by `MasterDataAdminAuthorizerContract` — a closed list of `admin`/`restricted_admin`/`operator`/`finance` that does **not** include `cemetery_operator`. Task 5 below adds `ActorRole::CEMETERY_OPERATOR` to `ReservePlotAction::ALLOWED_ROLES` as required groundwork, but a `cemetery_operator` actor is still refused today because the composed `canAccess()` check runs first and denies unconditionally. Full enablement (an operator-panel-aware `canAccess()` composition) is Phase C's job, once the `/operator` panel has a real resource that reuses this action. Task 5's test documents this honestly rather than asserting a false enablement.
2. **`BookingOrderResource::auditRoleFor()` / `RenewalOrderResource::auditRoleFor()` / `MarketplaceOrderResource::auditRoleFor()` don't recognize `cemetery_operator`**, so if a `cemetery_operator` ever did reach one of these `/admin`-panel actions today, the audit trail would record `'authenticated_actor'` rather than `'cemetery_operator'`. Not fixed here: these are `/admin`-panel resource classes a `cemetery_operator` cannot reach in Phase A (no `/admin` panel access), and the real operator-facing call site is Phase C's own `/operator` resource, which is a different, not-yet-written class. Flagging this now so Phase C's plan does not have to rediscover it.

## Task 1: `ActorRole::CEMETERY_OPERATOR`

**Files:**
- Modify: `app/Platform/IdentityAccess/Roles/ActorRole.php`
- Modify: `tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php`

**Interfaces:**
- Produces: `ActorRole::CEMETERY_OPERATOR = 'cemetery_operator'`, included in `ActorRole::KNOWN_ROLES` (9 entries total), declared immediately after `VENDOR` and before `CUSTOMER` — cemetery_operator is a scoped operational role structurally identical to vendor (external or internal staff acting for specific entities via a scope grant), so it sits next to vendor in the precedence list rather than near the platform-wide admin tier.

- [ ] **Step 1: Write the failing test**

Edit `tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php`:

```php
    public function test_known_roles_is_the_ruled_closed_list_in_precedence_order(): void
    {
        $this->assertSame([
            'admin', 'restricted_admin', 'finance', 'operator',
            'case_manager', 'vendor', 'cemetery_operator', 'customer', 'system',
        ], ActorRole::KNOWN_ROLES);
    }

    public function test_known_roles_list_has_exactly_nine_entries(): void
    {
        // Locks the count in explicitly so an accidental duplicate or a
        // silently dropped entry fails loudly here.
        $this->assertCount(9, ActorRole::KNOWN_ROLES);
    }
```

Replace the existing `test_known_roles_is_the_ruled_closed_list_in_precedence_order` and `test_known_roles_list_has_exactly_eight_entries` methods with the two above (same test names updated to `nine`).

- [ ] **Step 2: Run test to verify it fails**

Run (from the worktree root, via the pinned CI image per `docs/operations/local-test-recipe.md`):
```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter ActorRoleTest
```
Expected: FAIL — actual `KNOWN_ROLES` is still the 8-entry list without `cemetery_operator`.

- [ ] **Step 3: Write minimal implementation**

In `app/Platform/IdentityAccess/Roles/ActorRole.php`, add the constant after `VENDOR`:

```php
    public const string VENDOR = 'vendor';

    /**
     * The TPU/TPS operator dashboard roadmap's Phase A role
     * (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`, "Role &
     * scoping"). Structurally identical to `VENDOR`: an actor acting for one
     * or more specific cemeteries via a `scope_assignments` grant of
     * `Scopes\ScopeEntityType::CEMETERY`, never platform-wide. First real
     * consumers land in this same plan: `Panel\CemeteryOperatorPanelAccessPolicy`
     * (the `/operator` panel's access check) and
     * `OrderWorkflow\Authorization\OrderTransitionAuthorizer`'s
     * cemetery-scoped branch.
     */
    public const string CEMETERY_OPERATOR = 'cemetery_operator';

    public const string CUSTOMER = 'customer';
```

Update `KNOWN_ROLES`:

```php
    public const array KNOWN_ROLES = [
        self::ADMIN,
        self::RESTRICTED_ADMIN,
        self::FINANCE,
        self::OPERATOR,
        self::CASE_MANAGER,
        self::VENDOR,
        self::CEMETERY_OPERATOR,
        self::CUSTOMER,
        self::SYSTEM,
    ];
```

Also add one line to the class doc block's bullet list (after the `vendor` bullet):
```
 * - `cemetery_operator` — `docs/superpowers/plans/2026-08-28-operator-panel-and-role.md`
 *   (this plan); no code consumer until this same plan's later tasks land.
```

- [ ] **Step 4: Run test to verify it passes**

Run the same command as Step 2. Expected: PASS (2 tests). Also run the full `ActorRoleTest` class to confirm no other test in the file broke:
```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php
```
Expected: PASS, 7 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/IdentityAccess/Roles/ActorRole.php tests/Unit/Platform/IdentityAccess/Roles/ActorRoleTest.php
git commit -m "feat(identity-access): add cemetery_operator actor role"
```

---

## Task 2: `CemeteryOperatorPanelAccessPolicy`

**Files:**
- Create: `app/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicy.php`
- Create: `tests/Unit/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicyTest.php`

**Interfaces:**
- Consumes: `ActorRole::CEMETERY_OPERATOR` (Task 1), `App\Platform\IdentityAccess\Scopes\ScopeEntityType::CEMETERY` (existing, `= 'cemetery'`), `App\Platform\IdentityAccess\Contracts\PanelAccessPolicy` (existing interface, `allows(ActorContext $actor): bool`), `App\Platform\IdentityAccess\ActorContext` (existing).
- Produces: `App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy implements PanelAccessPolicy`, method `allows(ActorContext $actor): bool` — `true` iff the actor is authenticated, holds `ActorRole::CEMETERY_OPERATOR`, AND holds at least one `cemetery:` scope grant.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use PHPUnit\Framework\TestCase;

/**
 * `CemeteryOperatorPanelAccessPolicy` in isolation — the AC4 access check for
 * `/operator`, mirroring `VendorPanelAccessPolicyTest` exactly.
 *
 * A plain `PHPUnit\Framework\TestCase` with hand-built `ActorContext` values:
 * the policy is a pure predicate over an already-resolved actor context, so
 * nothing here needs a database or a booted application. The end-to-end
 * wiring through `User::canAccessPanel()` and a real HTTP request is covered
 * separately by `Tests\Feature\Filament\Operator\OperatorPanelAccessTest`.
 */
final class CemeteryOperatorPanelAccessPolicyTest extends TestCase
{
    private CemeteryOperatorPanelAccessPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->policy = new CemeteryOperatorPanelAccessPolicy;
    }

    public function test_guest_is_refused(): void
    {
        $this->assertFalse($this->policy->allows(ActorContext::guest()));
    }

    public function test_cemetery_operator_role_with_an_active_cemetery_grant_is_admitted(): void
    {
        $this->assertTrue($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_cemetery_operator_role_without_any_scope_grant_is_refused(): void
    {
        // The panel would render nothing but empty tables for this actor —
        // every surface inside scopes on the same (empty) grant list. Refusing
        // at the boundary is the honest answer.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: [],
        )));
    }

    public function test_cemetery_grant_without_the_cemetery_operator_role_is_refused(): void
    {
        // A customer or vendor who holds a cemetery-entity grant for some
        // unrelated reason must not reach an operator's own surfaces on the
        // strength of that grant alone.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CUSTOMER],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }

    public function test_a_non_cemetery_scope_grant_does_not_satisfy_the_scope_condition(): void
    {
        // Guards the prefix match: 'vendor:...' must not be read as a
        // cemetery grant.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::CEMETERY_OPERATOR],
            scopes: ['vendor:1', 'order:9'],
        )));
    }

    public function test_admin_role_alone_does_not_open_the_operator_panel(): void
    {
        // /admin's roles are not a superset of /operator's. An admin who
        // needs to see a cemetery's records uses the admin surfaces, which
        // are built for cross-cemetery visibility; letting them in here
        // would put them inside a panel whose every query is scoped to
        // grants they do not hold.
        $this->assertFalse($this->policy->allows(new ActorContext(
            identityReference: 1,
            roles: [ActorRole::ADMIN],
            scopes: ['cemetery:0198f2b6-1c2d-7000-8000-000000000001'],
        )));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter CemeteryOperatorPanelAccessPolicyTest
```
Expected: FAIL — class `CemeteryOperatorPanelAccessPolicy` does not exist.

- [ ] **Step 3: Write minimal implementation**

Create `app/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Panel;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\PanelAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * Explicit access check for the `/operator` Filament panel — AC4: "THE
 * SYSTEM SHALL require each panel (`/admin`, `/vendor`, operator) to declare
 * explicit access checks. THE SYSTEM SHALL NOT grant record access on panel
 * membership alone."
 *
 * `docs/superpowers/plans/2026-08-28-operator-panel-and-role.md` (Task 2),
 * implementing the TPU/TPS operator dashboard roadmap's "Role & scoping"
 * section. Deliberately structured identically to `VendorPanelAccessPolicy`
 * — see that class's own doc block for the full reasoning on why BOTH the
 * role and an active scope grant are required (neither substitutes for the
 * other), and why refusing at the panel boundary when the grant list is
 * empty is more honest than admitting the actor to a panel of uniformly
 * empty tables.
 *
 * Panel entry is still not record access. `Domain\CemeteryDirectory\Access
 * \CurrentCemeteryScope` (this same plan's Task 3) is what decides which
 * cemetery's rows an admitted actor actually sees, applied per query by
 * every Resource and Page in the panel.
 *
 * Widening either condition is an authorization change and carries
 * `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar.
 */
final class CemeteryOperatorPanelAccessPolicy implements PanelAccessPolicy
{
    private const string CEMETERY_SCOPE_PREFIX = ScopeEntityType::CEMETERY.':';

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $actor->hasRole(ActorRole::CEMETERY_OPERATOR)) {
            return false;
        }

        foreach ($actor->scopes as $scope) {
            if (str_starts_with($scope, self::CEMETERY_SCOPE_PREFIX)) {
                return true;
            }
        }

        return false;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter CemeteryOperatorPanelAccessPolicyTest
```
Expected: PASS, 6 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicy.php tests/Unit/Platform/IdentityAccess/Panel/CemeteryOperatorPanelAccessPolicyTest.php
git commit -m "feat(identity-access): add /operator panel access policy"
```

---

## Task 3: `CurrentCemeteryScope` + `ScopesToCurrentCemetery`

**Files:**
- Create: `app/Domain/CemeteryDirectory/Access/CurrentCemeteryScope.php`
- Create: `app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php`
- Create: `tests/Feature/Filament/Operator/OperatorPanelScopingTest.php`

**Interfaces:**
- Consumes: `App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver` (existing, `grantedEntityIds(int|string $actorIdentifier, string $entityType): array`, `currentActorIdentifier(): int|string|null`), `ScopeEntityType::CEMETERY` (existing).
- Produces: `CurrentCemeteryScope::grantedCemeteryIds(): list<string>`, `::hasAnyGrant(): bool`, `::allows(?string $cemeteryId): bool`, `::defaultCemeteryId(): ?string`, `::grantedCemeteryOptions(): array<string,string>`. `ScopesToCurrentCemetery::cemeteryScopeColumn(): string` (default `'cemetery_id'` — every existing cemetery-owned table in this codebase names its FK this way: `grave_records.cemetery_id`, `booking_drafts.cemetery_id`, `cemetery_packages.cemetery_id`, `cemetery_blocks.cemetery_id`; a Resource whose model reaches its cemetery indirectly overrides `applyCemeteryScope()`, exactly as `ScopesToCurrentVendor`'s own doc block anticipates), `::applyCemeteryScope(Builder $query): Builder`, `::getEloquentQuery(): Builder`.
- Note for later phases: no real `/operator` Resource consumes this trait yet in this task — Phase C's `CemeteryOrderResource` is the first real consumer, and per the roadmap it reaches its cemetery indirectly (`bookingDraft.cemetery_id`), so it will override `applyCemeteryScope()` rather than use the direct-column default.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Operator/OperatorPanelScopingTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Filament\Operator\Concerns\ScopesToCurrentCemetery;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
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
```

- [ ] **Step 2: Run test to verify it fails**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter OperatorPanelScopingTest
```
Expected: FAIL — `CurrentCemeteryScope`/`ScopesToCurrentCemetery` classes do not exist.

- [ ] **Step 3: Write minimal implementation**

Create `app/Domain/CemeteryDirectory/Access/CurrentCemeteryScope.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Access;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * "Which cemeteries is the current actor acting for?" — the single answer
 * every `/operator` panel surface reads before it builds a query.
 *
 * Mirrors `App\Domain\Marketplace\Access\CurrentVendorScope` exactly — see
 * that class's own doc block for the full reasoning: the answer comes from
 * `scope_assignments` rows of entity type `cemetery` and nothing else,
 * empty is the safe/deny-by-default answer for `whereIn(...)`, and the
 * enforcement point is the panel boundary (every Resource/Page under
 * `App\Filament\Operator`) rather than a model global scope — because, like
 * `vendor_listings`, some cemetery-owned data (e.g. the public cemetery
 * directory) is read by unauthenticated guests who by definition hold no
 * cemetery grant.
 */
final class CurrentCemeteryScope
{
    public function __construct(
        private readonly ScopeAssignmentResolver $scopes,
    ) {}

    /**
     * Cemetery ids (`cemeteries.id`, a UUID) the current actor holds an
     * active, non-revoked grant for. Empty for a guest and for an actor
     * with no cemetery grant — see the class doc block on why that is the
     * safe result and not an error.
     *
     * @return list<string>
     */
    public function grantedCemeteryIds(): array
    {
        $actorIdentifier = $this->scopes->currentActorIdentifier();

        if ($actorIdentifier === null) {
            return [];
        }

        return $this->scopes->grantedEntityIds($actorIdentifier, ScopeEntityType::CEMETERY);
    }

    public function hasAnyGrant(): bool
    {
        return $this->grantedCemeteryIds() !== [];
    }

    /**
     * The cemetery a newly created record should be stamped with when the
     * actor holds exactly one grant, or `null` when they hold none or
     * several — same "don't guess for the actor" reasoning as
     * `CurrentVendorScope::defaultVendorId()`.
     */
    public function defaultCemeteryId(): ?string
    {
        $granted = $this->grantedCemeteryIds();

        return count($granted) === 1 ? $granted[0] : null;
    }

    /**
     * `true` iff the current actor holds an active grant for `$cemeteryId`.
     * The server-side re-check every write path runs against client-
     * supplied input — see `CurrentVendorScope::allows()`'s own doc block.
     */
    public function allows(?string $cemeteryId): bool
    {
        if ($cemeteryId === null || $cemeteryId === '') {
            return false;
        }

        return in_array($cemeteryId, $this->grantedCemeteryIds(), true);
    }

    /**
     * Granted cemeteries as `id => name`, for a future create form's
     * cemetery picker.
     *
     * @return array<string, string>
     */
    public function grantedCemeteryOptions(): array
    {
        $granted = $this->grantedCemeteryIds();

        if ($granted === []) {
            return [];
        }

        return Cemetery::query()
            ->whereIn('id', $granted)
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
```

Create `app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Concerns;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Constrains an `/operator` Resource's base query to the cemeteries the
 * current actor holds an active `scope_assignments` grant for.
 *
 * Mirrors `App\Filament\Vendor\Concerns\ScopesToCurrentVendor` exactly — see
 * that trait's own doc block for why `getEloquentQuery()` (not a table
 * filter) is the right seam: it is the one query every page in a Resource
 * shares, so scoping here also makes a direct URL to another cemetery's
 * record a 404 rather than an open edit form.
 *
 * No `/operator` Resource consumes this trait yet — Phase A ships only the
 * mechanism (see `docs/superpowers/plans/2026-08-28-operator-panel-and-role.md`,
 * Task 3). Phase C's `CemeteryOrderResource` is the first real consumer;
 * per the roadmap it reaches its cemetery indirectly via
 * `bookingDraft.cemetery_id`, so it overrides `applyCemeteryScope()` rather
 * than relying on this trait's direct-column default.
 */
trait ScopesToCurrentCemetery
{
    /**
     * The column on this Resource's model that carries the owning
     * cemetery's id. Every cemetery-owned table in this codebase names it
     * `cemetery_id` (`grave_records`, `booking_drafts`, `cemetery_packages`,
     * `cemetery_blocks`); a Resource whose model reaches its cemetery
     * indirectly overrides `applyCemeteryScope()` instead.
     */
    public static function cemeteryScopeColumn(): string
    {
        return 'cemetery_id';
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return Builder<covariant \Illuminate\Database\Eloquent\Model>
     */
    public static function applyCemeteryScope(Builder $query): Builder
    {
        // whereIn() on an empty array compiles to an always-false clause, so
        // a guest and an actor with no cemetery grant both see nothing —
        // the deliberate closed default, see CurrentCemeteryScope's doc
        // block.
        return $query->whereIn(
            $query->qualifyColumn(static::cemeteryScopeColumn()),
            app(CurrentCemeteryScope::class)->grantedCemeteryIds(),
        );
    }

    public static function getEloquentQuery(): Builder
    {
        return static::applyCemeteryScope(parent::getEloquentQuery());
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter OperatorPanelScopingTest
```
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CemeteryDirectory/Access/CurrentCemeteryScope.php app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php tests/Feature/Filament/Operator/OperatorPanelScopingTest.php
git commit -m "feat(cemetery-directory): add cemetery-scoped query mechanism for the /operator panel"
```

---

## Task 4: `/operator` panel skeleton

**Files:**
- Create: `app/Providers/Filament/OperatorPanelProvider.php`
- Create: `app/Filament/Operator/Pages/Dashboard.php`
- Modify: `bootstrap/providers.php`
- Modify: `app/Models/User.php`
- Create: `tests/Feature/Filament/Operator/OperatorPanelAccessTest.php`

**Interfaces:**
- Consumes: `ActorRole::CEMETERY_OPERATOR` (Task 1), `CemeteryOperatorPanelAccessPolicy` (Task 2).
- Produces: panel id `'operator'`, path `/operator`, reachable only per `CemeteryOperatorPanelAccessPolicy::allows()`; `User::canAccessPanel()` gains an `'operator'` match arm.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Operator/OperatorPanelAccessTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CemeteryOperatorPanelAccessPolicy` reached the way a real request reaches
 * it: over HTTP, through Filament's `Authenticate` middleware and
 * `User::canAccessPanel()`. Mirrors `Tests\Feature\Filament\Vendor
 * \VendorPanelAccessTest` exactly — see that file's own doc block for why
 * this end-to-end wiring check is not redundant with the unit-level policy
 * test (Task 2): before a panel's `'operator'` match arm exists,
 * `canAccessPanel()` falls through to `default => false` and every actor is
 * refused, which no amount of unit-testing the policy in isolation reveals.
 */
final class OperatorPanelAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Asset builds run in CI, never on a dev host (this repo's
        // CLAUDE.md) — same reasoning as VendorPanelAccessTest.
        $this->withoutVite();
    }

    public function test_an_operator_with_an_active_grant_reaches_the_panel(): void
    {
        $this->actingAs($this->operatorUser(withRole: true, withGrant: true));

        $this->get('/operator')->assertSuccessful();
    }

    public function test_a_cemetery_operator_role_without_a_grant_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: true, withGrant: false));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_grant_without_the_cemetery_operator_role_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: false, withGrant: true));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_user_with_neither_is_refused(): void
    {
        $this->actingAs($this->operatorUser(withRole: false, withGrant: false));

        $this->get('/operator')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login_page(): void
    {
        $this->get('/operator')->assertRedirect('/operator/login');
    }

    private function operatorUser(bool $withRole, bool $withGrant): User
    {
        $user = User::factory()->create();

        if ($withRole) {
            ActorRoleAssignment::create([
                'actor_identifier' => (string) $user->id,
                'role' => ActorRole::CEMETERY_OPERATOR,
            ]);
        }

        if ($withGrant) {
            $cemetery = Cemetery::factory()->create();

            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => (string) $cemetery->id,
            ]);
        }

        return $user;
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter OperatorPanelAccessTest
```
Expected: FAIL — `/operator` route does not exist (no panel registered).

- [ ] **Step 3: Write minimal implementation**

Create `app/Filament/Operator/Pages/Dashboard.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use BackedEnum;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Icons\Heroicon;

final class Dashboard extends BaseDashboard
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $title = 'Dashboard Operator';

    protected static ?int $navigationSort = 10;
}
```

Create `app/Providers/Filament/OperatorPanelProvider.php`:

```php
<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Operator\Pages\Dashboard;
use App\Http\Middleware\AssignCorrelationId;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * `/operator` panel provider — the TPU/TPS operator dashboard roadmap's
 * Phase A skeleton (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`).
 *
 * Panel entry is decided by
 * `App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy`,
 * reached through `User::canAccessPanel()`'s `'operator'` arm. Filament's
 * `Authenticate` middleware below is what calls it. Record visibility is a
 * separate, later decision made per query by
 * `App\Filament\Operator\Concerns\ScopesToCurrentCemetery` — see AC4's
 * "SHALL NOT grant record access on panel membership alone".
 *
 * Follows `VendorPanelProvider`'s current (26 Aug 2026 reversal) shape: no
 * brand-token customisation. `docs/design/design-system.md` §8.3 records
 * that `/admin`/`/vendor` panels no longer follow the public site's brand
 * identity at all — this panel is built the same way from the start, so
 * there is no later reversal to make. Stock Filament colour scheme, stock
 * font stack, `->brandName()` only for functional identification.
 *
 * Ships only a placeholder Dashboard page and no discoverable Resources —
 * later phases (C: orders dashboard, D: plot availability) add real
 * Resources/Pages here.
 */
final class OperatorPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('operator')
            ->path('operator')
            ->login()
            ->brandName('Makam Operator')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                // Same reasoning as VendorPanelProvider's own copy: this
                // panel declares an explicit middleware array rather than
                // going through bootstrap/app.php's `web` group, so it
                // needs its own correlation-id origin point, first, before
                // anything that might want to reference one.
                AssignCorrelationId::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
```

Modify `bootstrap/providers.php` — add the import and the registration line immediately after `VendorPanelProvider::class`:

```php
use App\Providers\Filament\OperatorPanelProvider;
use App\Providers\Filament\VendorPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    VendorPanelProvider::class,
    // TPU/TPS operator dashboard roadmap Phase A
    // (docs/superpowers/plans/2026-08-28-operator-panel-and-role.md) —
    // registers the /operator panel skeleton. Same registration pattern as
    // AdminPanelProvider/VendorPanelProvider above.
    OperatorPanelProvider::class,
    FeatureGateServiceProvider::class,
    // ... (rest of the file unchanged)
```

Modify `app/Models/User.php`:

```php
use App\Platform\IdentityAccess\Panel\AdminPanelAccessPolicy;
use App\Platform\IdentityAccess\Panel\CemeteryOperatorPanelAccessPolicy;
use App\Platform\IdentityAccess\Panel\VendorPanelAccessPolicy;
```

And update the doc block + match expression:

```php
    /**
     * AC4's explicit-per-panel-access-check mechanism
     * (`App\Platform\IdentityAccess\Contracts\PanelAccessPolicy`), wired to
     * Filament through its `FilamentUser` contract. Resolves the candidate
     * user's `ActorContext` fresh (not the request's cached
     * `ActorContextResolver` instance — Filament calls this with an
     * arbitrary candidate `$this`, which the currently-cached request
     * context may or may not correspond to) and delegates the decision to
     * the named policy for that panel id. Unknown/future panel ids resolve
     * closed rather than falling through to an implicit allow.
     *
     * The `vendor` arm was added by lane L10. The `operator` arm was added
     * by the TPU/TPS operator dashboard roadmap's Phase A
     * (docs/superpowers/plans/2026-08-28-operator-panel-and-role.md). Until
     * then `operator` (the panel id — distinct from `ActorRole::OPERATOR`,
     * an unrelated existing role) fell to the `default => false` arm.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        $actorContext = app(IdentityAccessAdapter::class)->resolveActorContext($this);

        return match ($panel->getId()) {
            'admin' => app(AdminPanelAccessPolicy::class)->allows($actorContext),
            'vendor' => app(VendorPanelAccessPolicy::class)->allows($actorContext),
            'operator' => app(CemeteryOperatorPanelAccessPolicy::class)->allows($actorContext),
            default => false,
        };
    }
```

- [ ] **Step 4: Run test to verify it passes**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter OperatorPanelAccessTest
```
Expected: PASS, 5 tests. Also re-run `VendorPanelAccessTest` and `AdminPanelHttpAccessTest` (or equivalent) to confirm the new panel registration didn't regress the existing panels:
```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit tests/Feature/Filament/Vendor/VendorPanelAccessTest.php
```
Expected: PASS, unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Operator/Pages/Dashboard.php app/Providers/Filament/OperatorPanelProvider.php bootstrap/providers.php app/Models/User.php tests/Feature/Filament/Operator/OperatorPanelAccessTest.php
git commit -m "feat(filament): register the /operator panel skeleton"
```

---

## Task 5: `ReservePlotAction::ALLOWED_ROLES` extension

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`
- Create: `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php`

**Interfaces:**
- Consumes: `ActorRole::CEMETERY_OPERATOR` (Task 1).
- Produces: `ReservePlotAction::ALLOWED_ROLES` includes `ActorRole::CEMETERY_OPERATOR`. Documented, tested current behavior: a `cemetery_operator` is still refused end-to-end today (via the composed `BookingOrderResource::canAccess()` gate — see this plan's "Known, deliberate incompleteness" section), and this is a deliberate, non-regressing intermediate state, not a bug.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ReservePlotAction::ALLOWED_ROLES` now lists `ActorRole::CEMETERY_OPERATOR`
 * (this plan's Task 5) — necessary groundwork for Phase C, where the
 * `/operator` panel's own order resource reuses this action.
 *
 * It is NOT sufficient today: `ReservePlotAction::roleAllowed()` composes
 * `BookingOrderResource::canAccess()` FIRST, which is gated by
 * `MasterDataAdminAuthorizerContract` — a closed list of admin /
 * restricted_admin / operator / finance that does not include
 * `cemetery_operator`. This test documents that honestly rather than
 * asserting a false enablement; full enablement is Phase C's job, once a
 * real `/operator` resource exists to compose against instead of
 * `BookingOrderResource`. See this plan's "Known, deliberate
 * incompleteness carried into Phase C" section.
 */
final class ReservePlotActionCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_cemetery_operator_with_a_cemetery_grant_is_still_refused_today(): void
    {
        $cemetery = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);

        $action = ReservePlotAction::make($order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_existing_admitted_roles_are_unaffected_by_the_addition(): void
    {
        $cemetery = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $action = ReservePlotAction::make($order);

        $this->assertTrue($action->isAuthorized());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter ReservePlotActionCemeteryOperatorTest
```
Expected: `test_existing_admitted_roles_are_unaffected_by_the_addition` PASSES already (it exercises the pre-existing `ActorRole::OPERATOR` path, unaffected by this task). `test_a_cemetery_operator_with_a_cemetery_grant_is_still_refused_today` also currently reads `assertFalse(...)` as true — but for the wrong reason: `ActorRole::CEMETERY_OPERATOR` is not yet in `ALLOWED_ROLES`, so `roleAllowed()`'s foreach denies before it ever reaches the composed `canAccess()` check. That is a vacuous pass, not a real one. Step 3 adds the role to `ALLOWED_ROLES` specifically so this test starts exercising the `canAccess()` denial path it documents, rather than the role-list denial it does today.

- [ ] **Step 3: Write minimal implementation**

Modify `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`:

```php
    /**
     * The operational-actor admission list. Deliberately not finance: the
     * reservation is not a money-adjacent action, and finance's domain is
     * money — mirror of the plan's Task 5 role decision.
     *
     * `ActorRole::CEMETERY_OPERATOR` was added by the TPU/TPS operator
     * dashboard roadmap's Phase A
     * (docs/superpowers/plans/2026-08-28-operator-panel-and-role.md, Task
     * 5) as groundwork for Phase C, where a real `/operator` resource
     * reuses this action. Adding the role here alone is NOT sufficient
     * today: `roleAllowed()` below composes `BookingOrderResource
     * ::canAccess()` first, which still refuses `cemetery_operator`
     * unconditionally — see that plan's "Known, deliberate incompleteness"
     * section.
     *
     * @var list<string>
     */
    private const array ALLOWED_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
        ActorRole::CEMETERY_OPERATOR,
    ];
```

- [ ] **Step 4: Run test to verify it passes**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter ReservePlotActionCemeteryOperatorTest
```
Expected: PASS, both tests (`test_a_cemetery_operator_with_a_cemetery_grant_is_still_refused_today` passes because `roleAllowed()`'s foreach over `ALLOWED_ROLES` now returns true but `canAccess()` still returns false first; `test_existing_admitted_roles_are_unaffected_by_the_addition` still passes). Also re-run the existing `tests/Feature/Filament/BookingOrderTransitionActionTest.php` and any pre-existing plot-inventory admin test to confirm no regression:
```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit tests/Feature/Filament/PlotInventoryAdminTest.php
```
Expected: PASS, unchanged.

- [ ] **Step 5: Commit**

```bash
git add app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php
git commit -m "feat(booking-orders): add cemetery_operator to ReservePlotAction's role list"
```

---

## Task 6: `OrderTransitionAuthorizerContract` cemetery-scoped signature

**Files:**
- Modify: `app/Domain/OrderWorkflow/Authorization/Contracts/OrderTransitionAuthorizerContract.php`
- Modify: `app/Domain/OrderWorkflow/Authorization/OrderTransitionAuthorizer.php`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php`
- Modify: `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php`
- Modify: `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php`
- Modify: `app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php`
- Modify: `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`
- Modify: `tests/Feature/Filament/BookingOrderTransitionActionTest.php`
- Create: `tests/Feature/Domain/Renewal/RenewalTransitionAuthorizerCemeteryOperatorTest.php`

**Interfaces:**
- Consumes: `ActorRole::CEMETERY_OPERATOR` (Task 1), `App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver::grantedEntityIds()` (existing), `ScopeEntityType::CEMETERY` (existing).
- Produces: `OrderTransitionAuthorizerContract::authorizeTransition(ActorContext $actor, string $transition, ?string $cemeteryId = null): void` — the widened signature every later phase (C, F) builds on.

- [ ] **Step 1: Write the failing test**

Modify `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php` — add `use RefreshDatabase;` (the new cemetery_operator branch queries `scope_assignments`) and new test methods:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OrderTransitionAuthorizerTest extends TestCase
{
    use RefreshDatabase;

    private const string NON_MONEY = 'verify_order';

    private const string MONEY = 'mark_order_paid';

    private function actor(array $roles, ?string $lastAuth = null): ActorContext
    {
        return new ActorContext(
            identityReference: 'user:1',
            roles: $roles,
            scopes: [],
            lastAuthenticatedAt: $lastAuth === null ? null : CarbonImmutable::parse($lastAuth),
        );
    }

    public function test_operator_can_run_non_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_finance_cannot_run_plain_operator_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::NON_MONEY);
    }

    public function test_finance_can_run_money_transition(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::FINANCE]), self::MONEY);
        $this->assertTrue(true);
    }

    public function test_operator_cannot_run_money_transition(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::OPERATOR]), self::MONEY);
    }

    public function test_admin_can_run_everything(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::MONEY);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::ADMIN]), self::NON_MONEY);
        $this->assertTrue(true);
    }

    public function test_restricted_admin_cannot_issue_quote(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'issue_quote');
    }

    public function test_restricted_admin_can_verify_order(): void
    {
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([ActorRole::RESTRICTED_ADMIN]), 'verify_order');
        $this->assertTrue(true);
    }

    public function test_guest_is_denied(): void
    {
        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition($this->actor([]), self::NON_MONEY);
    }

    // -----------------------------------------------------------------
    // cemetery_operator — the widened, cemetery-scoped branch (Task 6)
    // -----------------------------------------------------------------

    public function test_cemetery_operator_can_run_non_money_transition_for_their_own_cemetery(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            'cemetery-a',
        );
        $this->assertTrue(true);
    }

    public function test_cemetery_operator_cannot_run_a_transition_for_a_cemetery_they_are_not_granted(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            'cemetery-b',
        );
    }

    public function test_cemetery_operator_with_no_cemetery_id_is_denied(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::NON_MONEY,
            null,
        );
    }

    public function test_cemetery_operator_cannot_run_a_money_transition_even_for_their_own_cemetery(): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => 'user:1',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => 'cemetery-a',
        ]);

        $this->expectException(OrderActionNotAuthorisedException::class);
        app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
            $this->actor([ActorRole::CEMETERY_OPERATOR]),
            self::MONEY,
            'cemetery-a',
        );
    }
}
```

Modify `tests/Feature/Filament/BookingOrderTransitionActionTest.php` — add a cemetery cross-tenant test using the real `TransitionOrderAction` call site:

```php
    public function test_a_cemetery_operator_cannot_transition_another_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $cemeteryB = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryB->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_a_cemetery_operator_can_transition_their_own_cemeterys_order(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemeteryA->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = TransitionOrderAction::make(OrderStatus::DIVERIFIKASI, $order);
        $action->call();

        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->fresh()->status());
    }
```

Add the needed imports at the top of that file:
```php
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
```

Create `tests/Feature/Domain/Renewal/RenewalTransitionAuthorizerCemeteryOperatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\Actions\ExpireRenewalAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `RecordExternalRenewalPaymentAction`/`ExpireRenewalAction` resolve their
 * cemetery id via `Renewal->graveRecord->cemetery_id` (Task 6). This proves
 * the non-money `expire_renewal` transition end-to-end through the real
 * Filament Action call site — the money-transition case
 * (`record_external_renewal_payment`) is already covered at the authorizer
 * level by `OrderTransitionAuthorizerTest
 * ::test_cemetery_operator_cannot_run_a_money_transition_even_for_their_own_cemetery`,
 * since cemetery_operator can never pass a MONEY_TRANSITIONS check
 * regardless of cemetery.
 */
final class RenewalTransitionAuthorizerCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    public function test_a_cemetery_operator_cannot_expire_another_cemeterys_renewal(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $cemeteryB = Cemetery::factory()->create();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemeteryB->id]);
        $renewal = Renewal::query()->create([
            'grave_record_id' => $grave->id,
            'target_due_period' => now()->addYear()->format('Y-m-d'),
            'reference' => 'PPJ-'.random_int(1_000, 99_999),
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = ExpireRenewalAction::make($renewal);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_a_cemetery_operator_can_expire_their_own_cemeterys_renewal(): void
    {
        $cemeteryA = Cemetery::factory()->create();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemeteryA->id]);
        $renewal = Renewal::query()->create([
            'grave_record_id' => $grave->id,
            'target_due_period' => now()->addYear()->format('Y-m-d'),
            'reference' => 'PPJ-'.random_int(1_000, 99_999),
            'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
            'source' => RenewalSource::ONLINE,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemeteryA->id,
        ]);
        $this->actingAs($user);

        $action = ExpireRenewalAction::make($renewal);

        $this->assertTrue($action->isAuthorized());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter "OrderTransitionAuthorizerTest|BookingOrderTransitionActionTest|RenewalTransitionAuthorizerCemeteryOperatorTest"
```
Expected: FAIL — `authorizeTransition()` does not accept a third argument yet; the new cemetery_operator branch does not exist, so cemetery_operator is denied everywhere including the "own cemetery" positive cases.

- [ ] **Step 3: Write minimal implementation**

Modify `app/Domain/OrderWorkflow/Authorization/Contracts/OrderTransitionAuthorizerContract.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization\Contracts;

use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;

interface OrderTransitionAuthorizerContract
{
    /**
     * @param  ?string  $cemeteryId  The cemetery the record being
     *                               transitioned belongs to, when the caller
     *                               has one to resolve — `null` for records
     *                               with no cemetery concept (e.g.
     *                               `MarketplaceOrder`). Required for a
     *                               `cemetery_operator` actor to ever be
     *                               authorized; every other role's
     *                               authorization is unaffected by this
     *                               parameter.
     *
     * @throws OrderActionNotAuthorisedException
     */
    public function authorizeTransition(ActorContext $actor, string $transition, ?string $cemeteryId = null): void;
}
```

Modify `app/Domain/OrderWorkflow/Authorization/OrderTransitionAuthorizer.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Authorization;

use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

final class OrderTransitionAuthorizer implements OrderTransitionAuthorizerContract
{
    /** Transitions that create a binding quote — restricted_admin excluded. */
    private const array QUOTE_ISSUING_TRANSITIONS = ['issue_quote'];

    /** Transitions that touch money or authorize payment opening — finance/admin only. */
    private const array MONEY_TRANSITIONS = [
        'authorize_payment_opening',
        'manual_payment_verification',
        'mark_order_paid',
        'mark_marketplace_order_paid',
        'record_external_renewal_payment',
    ];

    public function __construct(
        private readonly ScopeAssignmentResolver $scopes,
    ) {}

    public function authorizeTransition(ActorContext $actor, string $transition, ?string $cemeteryId = null): void
    {
        if ($actor->identityReference === null || $actor->roles === []) {
            throw OrderActionNotAuthorisedException::forActorContext();
        }

        if (in_array(ActorRole::ADMIN, $actor->roles, true)) {
            return;
        }

        if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
            if (in_array(ActorRole::FINANCE, $actor->roles, true)) {
                return;
            }

            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array($transition, self::QUOTE_ISSUING_TRANSITIONS, true)
            && in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)) {
            throw OrderActionNotAuthorisedException::forTransition($transition);
        }

        if (in_array(ActorRole::OPERATOR, $actor->roles, true)
            || in_array(ActorRole::RESTRICTED_ADMIN, $actor->roles, true)) {
            return;
        }

        // TPU/TPS operator dashboard roadmap Phase A
        // (docs/superpowers/plans/2026-08-28-operator-panel-and-role.md,
        // Task 6): a cemetery_operator is authorized for a non-money
        // transition ONLY when the caller resolved a cemetery id AND that
        // id is among the actor's active cemetery grants. A MarketplaceOrder
        // call site passes no cemetery id at all (it has no cemetery
        // concept), so cemetery_operator can never pass this branch there —
        // correct, since marketplace orders are outside a cemetery
        // operator's remit.
        if (in_array(ActorRole::CEMETERY_OPERATOR, $actor->roles, true)
            && $cemeteryId !== null
            && in_array($cemeteryId, $this->scopes->grantedEntityIds($actor->identityReference, ScopeEntityType::CEMETERY), true)) {
            return;
        }

        throw OrderActionNotAuthorisedException::forActorContext();
    }
}
```

Modify `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php` — both call sites resolve `$order->bookingDraft?->cemetery_id`:

```php
    private static function authorized(Order $order, OrderStatus $to): bool
    {
        $transition = self::TRANSITION_NAME[$to->value] ?? null;

        if ($transition === null) {
            return false;
        }

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                $transition,
                $order->bookingDraft?->cemetery_id,
            );
        } catch (OrderActionNotAuthorisedException) {
            return false;
        }

        return true;
    }
```

```php
    private static function run(Order $order, OrderStatus $to, ?string $reason): void
    {
        $actor = app(ActorContext::class);
        $transition = self::TRANSITION_NAME[$to->value];

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                $actor,
                $transition,
                $order->bookingDraft?->cemetery_id,
            );

            if (in_array($transition, self::MONEY_TRANSITIONS, true)) {
                app(ReauthenticationGuard::class)->assertFresh($actor);
            }
        } catch (ReauthenticationRequiredException) {
            // ... unchanged
```

(Only the two `authorizeTransition(...)` call expressions change; everything else in the method body is unchanged.)

Modify `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php` — no call-site change needed (both existing calls already pass exactly `($actor, 'mark_marketplace_order_paid')`, and the new third parameter defaults to `null`), but add a one-line doc-comment note next to both call sites confirming this is deliberate:

```php
                try {
                    // No cemetery id is resolved here on purpose — a
                    // MarketplaceOrder has no cemetery concept, so this call
                    // correctly excludes cemetery_operator from marketplace
                    // payment transitions (Phase A, Task 6).
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition($actor, 'mark_marketplace_order_paid');
```

(Apply the same one-line comment above the `authorized()` method's call too, for consistency — no behavioral change to this file.)

Modify `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php` — both call sites resolve `$renewal->graveRecord?->cemetery_id`:

```php
    public static function make(Renewal $renewal): Action
    {
        return Action::make('record_external_payment')
            // ... unchanged schema/labels ...
            ->authorize(fn (): bool => self::authorized($renewal))
            ->visible(fn (Renewal $record): bool => $record->status === RenewalStatus::MENUNGGU_PEMBAYARAN)
            ->action(function (array $data) use ($renewal): void {
                $actor = app(ActorContext::class);
                $actorRef = $actor->identityReference;
                $actorRole = RenewalOrderResource::auditRoleFor($actor);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                        $actor,
                        self::TRANSITION,
                        $renewal->graveRecord?->cemetery_id,
                    );
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    // ... unchanged
```

```php
    private static function authorized(Renewal $renewal): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                self::TRANSITION,
                $renewal->graveRecord?->cemetery_id,
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
```

Note: `authorized()` did not previously take a `$renewal` parameter (`private static function authorized(): bool`) — it must now, since `->authorize(fn (): bool => self::authorized())` needs the renewal in scope to resolve its cemetery. Update the `->authorize()` closure at the call site to `fn (): bool => self::authorized($renewal)` accordingly (the closure already captures `$renewal` via `use` implicitly through the outer `make(Renewal $renewal)` scope — since `->authorize()` is registered inside `make()`, `$renewal` is already in closure scope without an explicit `use`; only `self::authorized()`'s own signature needs the added parameter).

Modify `app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php` — same treatment:

```php
    public static function make(Renewal $renewal): Action
    {
        return Action::make('expire_renewal')
            // ... unchanged schema/labels ...
            ->authorize(fn (): bool => self::authorized($renewal))
            ->visible(fn (Renewal $record): bool => $record->status === RenewalStatus::MENUNGGU_PEMBAYARAN)
            ->action(function (array $data) use ($renewal): void {
                $actor = app(ActorContext::class);

                try {
                    app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                        $actor,
                        self::TRANSITION,
                        $renewal->graveRecord?->cemetery_id,
                    );
                } catch (\Throwable $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();

                    return;
                }
                // ... unchanged
```

```php
    private static function authorized(Renewal $renewal): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                self::TRANSITION,
                $renewal->graveRecord?->cemetery_id,
            );

            return true;
        } catch (\Throwable) {
            return false;
        }
    }
```

- [ ] **Step 4: Run tests to verify they pass**

```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit --filter "OrderTransitionAuthorizerTest|BookingOrderTransitionActionTest|RenewalTransitionAuthorizerCemeteryOperatorTest"
```
Expected: PASS — 12 `OrderTransitionAuthorizerTest` tests, 5 `BookingOrderTransitionActionTest` tests, 2 `RenewalTransitionAuthorizerCemeteryOperatorTest` tests.

Then run the full existing regression suite for every touched call site to confirm zero breakage:
```
docker run --rm -v "$(pwd)":/app -w /app <pinned-image> vendor/bin/phpunit tests/Feature/Filament/RenewalOrderResourceTest.php tests/Feature/Filament/MarketplaceOrderResourceTest.php tests/Feature/Filament/BookingOrderTransitionActionTest.php
```
Expected: PASS, unchanged pre-existing tests plus the new ones.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/OrderWorkflow/Authorization/Contracts/OrderTransitionAuthorizerContract.php app/Domain/OrderWorkflow/Authorization/OrderTransitionAuthorizer.php app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php app/Filament/Admin/Resources/RenewalOrders/Actions/ExpireRenewalAction.php tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php tests/Feature/Filament/BookingOrderTransitionActionTest.php tests/Feature/Domain/Renewal/RenewalTransitionAuthorizerCemeteryOperatorTest.php
git commit -m "feat(order-workflow): scope OrderTransitionAuthorizerContract to cemetery_operator's granted cemeteries"
```

---

## Final whole-branch review

After Task 6, this plan's scope is complete. Per `superpowers:subagent-driven-development`, dispatch the final whole-branch review (most capable available model) before opening a PR, covering at minimum:
- Every one of the 8 `authorizeTransition()` call points across the 4 modified Action classes actually passes the correct cemetery id expression (not just the 2 tested end-to-end in Task 6 — `MarkMarketplaceOrderPaidAction`'s 2 call points must be re-read to confirm they still compile and still pass no third argument).
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` clean on the full branch diff.
- `bash ci/verify-docs.sh` run from the worktree root (expect the same pre-existing `python3` gap on this pinned image noted in Phase B's own finishing skill — not a regression, confirm identical to trunk).
- A full regression sweep: `tests/Unit/Platform/IdentityAccess`, `tests/Feature/Filament/Vendor`, `tests/Feature/Filament/Operator`, `tests/Feature/Filament/BookingOrderTransitionActionTest.php`, `tests/Feature/Filament/RenewalOrderResourceTest.php`, `tests/Feature/Filament/MarketplaceOrderResourceTest.php`, `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php`, `tests/Feature/Domain/Renewal`, `tests/Unit/Domain/OrderWorkflow`.

## Verification (roadmap-level, restated from the Spec)

- `OperatorPanelAccessTest`/`CemeteryOperatorPanelAccessPolicyTest` pass and a `cemetery_operator` with zero grants gets a genuinely empty/denied panel, not an error page (Task 2, 4).
- `OperatorPanelScopingTest` proves a granted operator sees only their own cemetery's data at the query level (Task 3) — extend, never replace, when Phase C adds the first real Resource.
- The load-bearing authorizer-level proof: a `cemetery_operator` granted only cemetery A cannot invoke `TransitionOrderAction`/`ExpireRenewalAction`/`RecordExternalRenewalPaymentAction` against a record belonging to cemetery B, even when the record id is known directly — proven in Task 6, not merely assumed from list-view scoping.
- `MarkMarketplaceOrderPaidAction` still denies `cemetery_operator` entirely (Task 6) — marketplace orders have no cemetery, so the actor can never pass the cemetery check there.
