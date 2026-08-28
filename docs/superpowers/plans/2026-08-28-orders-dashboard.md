# Orders Dashboard (Phase C) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give a `cemetery_operator` a real, cemetery-scoped orders dashboard at `/operator`, reusing the existing `/admin` order table, infolist and row actions, and close the three authorization/attribution gaps Phase A deliberately deferred to this phase.

**Architecture:** `BookingOrdersTable::configure()` (a panel-agnostic static builder) is redesigned in place toward a hotel-reservations-list feel and then reused verbatim by a new `App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource`. That resource carries its own cemetery-scoped access gate (never the platform-wide `MasterDataAdminAuthorizer`) and reaches its cemetery indirectly through `bookingDraft.cemetery_id`, so it overrides `ScopesToCurrentCemetery::applyCemeteryScope()` with a subquery instead of the trait's direct-column default. The row actions those two panels share are then made panel-aware (dynamic redirect target), operator-aware (`ReservePlotAction` and `PlotReservationLifecycleActions` currently compose an admin-only `canAccess()` first), and audit-correct (`auditRoleFor()` learns `cemetery_operator`).

**Tech Stack:** PHP 8.5 (CI container; this host runs 8.3, see Global Constraints), Laravel 13, Filament 5.7.3, Livewire 4, PostgreSQL 18, Redis 8.2, PHPUnit, Pint, PHPStan.

**Spec:** The TPU/TPS operator dashboard roadmap, "Orders dashboard (Phase C — depends on A, B)" section. Phase A's plan, which this one continues and whose deferrals it closes, is [`docs/superpowers/plans/2026-08-28-operator-panel-and-role.md`](2026-08-28-operator-panel-and-role.md) — read its "Known, deliberate incompleteness carried into Phase C" section (lines 23–26) before starting.

## Global Constraints

- `declare(strict_types=1);` on every new and modified PHP file. Every new class is `final`.
- Style gate: `vendor/bin/pint --test` must pass.
- Static analysis gate: `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` must pass. The explicit memory limit is required — the 128M default crashes on this repo's analysis set.
- Docs gate: `bash ci/verify-docs.sh` must pass (repository-wide, no build needed; it also scans `app/` and `resources/` for hardcoded design values).
- Tests run against **real PostgreSQL 18 + Redis 8.2, never SQLite** — SQLite masks the `uuid`-typed column behaviour this app's migrations rely on. Follow [`docs/operations/local-test-recipe.md`](../../operations/local-test-recipe.md) exactly: disposable containers on non-default ports with a worktree-specific name prefix, and every command run *inside* the pinned `ghcr.io/andrianm28/makam-app` container (this host's PHP 8.3.6 cannot even load the app; it needs >= 8.5.0).
- **Never run `composer install` or `npm run build` on the bare host.** `vendor/` is already hard-linked into this worktree. If an autoload error appears, run `composer install` *inside* the pinned container per §2 of the test recipe.
- No hardcoded design values and no arbitrary Tailwind values. `resources/css/tokens.css` is the single source of truth; `docs/design/design-system.md` governs. This plan adds no new CSS — every colour used is a Filament semantic colour name routed through the existing `BookingOrderStatusBadge` / `StatusIntent` mapping, which is **not touched**.
- Never report `PASS` for a check that was not executed. Use `BLOCKED` or `NOT TESTED` explicitly (`AGENTS.md` §Infrastructure-agent execution).
- **Human review is mandatory before merge.** This branch is squarely security/authorization work: it introduces a new cross-tenant-scoped resource and changes the authorization gate of three existing action classes. `AGENTS.md` §Infrastructure-agent execution: "AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization, financial, privacy, destructive migration, DNS, firewall, or production-affecting changes."
- Indonesian UI copy throughout (labels, notifications, modal headings) and Indonesian URL slugs — the convention `docs/superpowers/plans/2026-08-24-url-indonesianization.md` established.

---

## Corrections to the research brief (verified against the branch on 2026-08-28)

Everything below was checked against the real files in this worktree. Where the brief differed, the file wins and the plan follows the file.

1. **The hardcoded `/admin` redirect appears at FOUR call sites in THREE files, not two sites in two files.** Verified with `grep -rn "filament\.admin\." app/Filament/Admin/Resources/BookingOrders/`:
   - `Actions/ReservePlotAction.php:244` — success redirect.
   - `Actions/TransitionOrderAction.php:201` — `session()->put('url.intended', route(...))` on the re-authentication path (not a `redirect()` call, but the same hardcoded route name and the same bug).
   - `Actions/TransitionOrderAction.php:240` — success redirect.
   - `Actions/PlotReservationLifecycleActions.php:133` — success redirect. **The brief did not mention this file at all.** It is rendered by `ViewBookingOrder::getHeaderActions()` alongside `ReservePlotAction`, so the operator's view page will render it too.
2. **`BookingOrdersTable` has no "package/class" column today** — it has a `product_type` column. The roadmap's target column list names package/class, so Task 2 adds one (`bookingDraft.cemeteryPackage.name`) and keeps `product_type`.
3. **`Order` has NO relation to `PlotReservation`.** It defines only `bookingDraft()`, `statusEvents()` and `parties()`. `PlotReservation::activeForOrder()` is a static that runs one query per order, so using it from a table column would be a textbook N+1. Task 1 adds the relation the roadmap's "no N+1" requirement needs.
4. **`ReservePlotAction` is not the only action composing the admin-only gate.** `PlotReservationLifecycleActions::roleAllowed()` (line 92) has the identical `if (! BookingOrderResource::canAccess()) { return false; }` composition, and its `ALLOWED_ROLES` does *not* yet include `CEMETERY_OPERATOR`. Task 7 fixes it. **This is beyond the brief's explicit list** — see that task's rationale, and drop the task if the controller rules it out of Phase C scope. Leaving it unfixed ships an operator who can place a plot hold but cannot release or confirm it.
5. **Phase A's `ReservePlotActionCemeteryOperatorTest` is a second tripwire the brief did not name.** Its `test_a_cemetery_operator_with_a_cemetery_grant_is_still_refused_today` is misnamed: it grants the *role* but never creates a `ScopeAssignment`, so it holds no cemetery grant at all. After Task 6 the assertion still passes (correctly — no grant means no access), but the name and doc block become false. Task 6 rewrites the file rather than leaving a lying test name.
6. **`SelectFilter::relationship()` DOES support dot-notation nesting in Filament 5.7.3** — verified in `vendor/filament/tables/src/Filters/Concerns/HasRelationship.php:63-84` (`getRelationship()` explodes the name on `.` and walks each hop) and `vendor/filament/tables/src/Filters/SelectFilter.php:202` (`apply()` passes the dotted name straight to Eloquent's `whereHas()`, which is nested-path aware). So `->relationship('bookingDraft.cemetery', 'name')` is safe; no `->query()` closure workaround is needed.
7. **`Filament::getCurrentPanel()` and `Panel::getModelResource()` both exist and are public** — `vendor/filament/filament/src/FilamentManager.php:122` and `vendor/filament/filament/src/Panel/Concerns/HasComponents.php:204`. `Resource::getUrl()` accepts a `panel:` argument (`vendor/filament/filament/src/Resources/Resource/Concerns/CanGenerateUrls.php:16`). `BookingOrderResource` is the **only** resource in the codebase whose `$model` is `Order::class` (verified with `grep -rln 'model = Order::class' app/Filament/`), so `getModelResource(Order::class)` is unambiguous within each panel.
8. **`OperatorPanelProvider` does not call `->discoverResources()`** — it registers pages by explicit array only. The new resource must be registered with an explicit `->resources([...])` call (Task 4).
9. **`CemeteryOperatorPanelAccessPolicy` is a plain concrete class resolved via `app()`, with no contract interface** (`app/Models/User.php:87`). The new resource-level gate follows that same shape — no new contract interface — rather than `BookingOrderResource`'s contract-backed shape, because it has exactly one consumer. Flagged here so a reviewer can push back on it explicitly.
10. **`docs/security/rbac-matrix.md` is deliberately NOT edited by this plan.** It has no `cemetery_operator` column, and its own body says "The columns above are capability groupings for review, not the role list itself — read the closed list from `ActorRole::KNOWN_ROLES` rather than inferring it from this table." Its existing `Operator` column already reads `Assigned cemetery` / `Assigned authority` for the plot and availability rows. Adding a role column would duplicate canonical data the class already masters, which `AGENTS.md` §Documentation forbids.

## File Structure

**Created:**
- `app/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicy.php` — the resource-level "may this actor use the `/operator` orders surface at all" gate. Role + any-grant, nothing per-record.
- `app/Filament/Operator/Resources/CemeteryOrders/CemeteryOrderResource.php` — the `/operator` orders resource.
- `app/Filament/Operator/Resources/CemeteryOrders/Pages/ListCemeteryOrders.php`
- `app/Filament/Operator/Resources/CemeteryOrders/Pages/ViewCemeteryOrder.php`
- `app/Filament/Support/OrderViewUrl.php` — the one place that answers "what is the current panel's URL for this order's view page".
- `tests/Unit/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicyTest.php`
- `tests/Feature/Filament/Operator/CemeteryOrderResourceTest.php`
- `tests/Feature/Filament/BookingOrdersTableTest.php`
- `tests/Feature/Filament/OrderViewUrlTest.php`

**Modified:**
- `app/Domain/PlotReservation/PlotReservationState.php` — add the `ACTIVE_STATES` closed list.
- `app/Domain/PlotReservation/Models/PlotReservation.php` — extract `incumbentOf()` from `activeForOrder()` so the head-row-plus-active-state rule lives in exactly one place.
- `app/Domain/OrderWorkflow/Models/Order.php` — add the `plotReservations()` relation.
- `app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php` — the column/filter redesign and the eager loads.
- `app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php` — `auditRoleFor()` learns `cemetery_operator`.
- `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php` — operator-aware + per-order cemetery check + panel-aware redirect.
- `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php` — panel-aware redirect (×2 sites).
- `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php` — operator-aware + per-order cemetery check + panel-aware redirect.
- `app/Providers/Filament/OperatorPanelProvider.php` — register the resource.
- `tests/Feature/Filament/BookingOrderTransitionActionTest.php` — flip the Phase A audit tripwire.
- `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php` — rewrite for the now-enabled path.
- `tests/Feature/Filament/Operator/OperatorPanelScopingTest.php` — add the structural scoping walk Phase A's own doc block asks Phase C to add.

## Task ordering and dependencies

```
Task 1 (reservation primitives)  ──┐
                                   ├──> Task 2 (table redesign)
Task 3 (access policy) ────────────┴──> Task 4 (CemeteryOrderResource) ──┬──> Task 5 (panel-aware redirect)
                                                                         ├──> Task 6 (ReservePlotAction)
                                                                         ├──> Task 7 (lifecycle actions)
                                                                         └──> Task 9 (structural scoping walk)
Task 8 (auditRoleFor) — independent of all of the above, sequenced late so the operator write paths it attributes are already live.
```

Tasks 1 and 3 are mutually independent and may run in parallel. Tasks 5, 6 and 7 all require Task 4 (they reference `CemeteryOrderResource` or need a second registered panel to test against). Task 6 must land before Task 7 — Task 7 reuses the exact enforcement shape Task 6 establishes.

---

### Task 1: Active-reservation primitives (`ACTIVE_STATES`, `incumbentOf()`, `Order::plotReservations()`)

The table's plot column must show, per order, the slot and state of the *active* reservation — "active" meaning the head of the order's append-only reservation chain, when that head row is `held` or `confirmed`. That is exactly `PlotReservation::activeForOrder()`'s rule, but calling it per row is one query per order. This task extracts the rule so it can be applied to an already-eager-loaded chain, and adds the relation that makes eager-loading possible. No behaviour changes.

**Files:**
- Modify: `app/Domain/PlotReservation/PlotReservationState.php`
- Modify: `app/Domain/PlotReservation/Models/PlotReservation.php:167-181` (`activeForOrder()`)
- Modify: `app/Domain/OrderWorkflow/Models/Order.php` (add a relation next to `statusEvents()`, around line 304)
- Test: `tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php` (append to the existing file)

**Interfaces:**
- Produces: `PlotReservationState::ACTIVE_STATES` — `list<string>`, exactly `['held', 'confirmed']`.
- Produces: `PlotReservation::incumbentOf(?PlotReservation $head): ?PlotReservation` — returns `$head` when it is non-null and its `state` is in `ACTIVE_STATES`, else `null`.
- Produces: `Order::plotReservations(): HasMany<PlotReservation, $this>` — the order's full reservation chain, ordered newest-first (`created_at DESC, id DESC`), so `$order->plotReservations->first()` is always the chain head whether the relation was lazy- or eager-loaded.
- Consumes: nothing.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php` (inside the existing class, keeping its existing imports; add `use App\Domain\PlotReservation\Models\PlotReservation;` and `use App\Domain\PlotReservation\PlotReservationState;` if not already present):

```php
    public function test_active_states_is_the_closed_held_confirmed_pair(): void
    {
        $this->assertSame(['held', 'confirmed'], PlotReservationState::ACTIVE_STATES);
    }

    public function test_incumbent_of_returns_the_head_row_when_it_is_active(): void
    {
        $reservation = new PlotReservation(['state' => PlotReservationState::HELD]);

        $this->assertSame($reservation, PlotReservation::incumbentOf($reservation));
    }

    public function test_incumbent_of_returns_null_for_a_released_head_row(): void
    {
        $reservation = new PlotReservation(['state' => PlotReservationState::RELEASED]);

        $this->assertNull(PlotReservation::incumbentOf($reservation));
    }

    public function test_incumbent_of_returns_null_for_a_null_head_row(): void
    {
        $this->assertNull(PlotReservation::incumbentOf(null));
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Start the containers first, per `docs/operations/local-test-recipe.md` §1 (use a unique prefix, e.g. `ordersdash`, and free ports found with `ss -ltn`). Then, with `<img>` standing for the pinned image tag from `docker images --digests | grep makam-app`:

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<pg-port> \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<redis-port> \
  -v "$(pwd)":/var/www/html -w /var/www/html <img> \
  php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php
```

Expected: FAIL — `Undefined constant App\Domain\PlotReservation\PlotReservationState::ACTIVE_STATES` and `Call to undefined method ...PlotReservation::incumbentOf()`.

- [ ] **Step 3: Add `ACTIVE_STATES` to `PlotReservationState`**

In `app/Domain/PlotReservation/PlotReservationState.php`, immediately after the `KNOWN_STATES` constant:

```php
    /**
     * The states in which a reservation still holds its plot. The class doc
     * block above has always said "`held` and `confirmed` are the active
     * states" in prose; this constant is that sentence as code, so the
     * pair lives in exactly one place instead of being re-typed at each
     * call site.
     *
     * @var list<string>
     */
    public const array ACTIVE_STATES = [
        self::HELD,
        self::CONFIRMED,
    ];
```

- [ ] **Step 4: Extract `incumbentOf()` in `PlotReservation`**

In `app/Domain/PlotReservation/Models/PlotReservation.php`, replace the body of `activeForOrder()` (currently lines 167–181) and add the new static beside it:

```php
    public static function activeForOrder(Order $order): ?self
    {
        return self::incumbentOf(
            self::query()
                ->where('order_id', $order->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()
        );
    }

    /**
     * The incumbency rule of `activeForOrder()`, applied to a head row the
     * caller already has. A table listing eager-loads whole chains through
     * `Order::plotReservations()` (ordered newest-first) and calls this on
     * `->first()`, which is the same head row `activeForOrder()` would
     * select — one query for the page instead of one per row, with the
     * rule itself not duplicated.
     */
    public static function incumbentOf(?self $head): ?self
    {
        if ($head === null || ! in_array($head->state, PlotReservationState::ACTIVE_STATES, true)) {
            return null;
        }

        return $head;
    }
```

Also replace the identical inline pair at line 200 (in `activeForPlot()`) with `PlotReservationState::ACTIVE_STATES`, and at `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php:67` with the same constant. Do **not** touch `app/Domain/PlotReservation/Actions/ReleasePlotReservation.php:86` — it is inside a locked transaction whose reasoning is independently documented; leave that call site alone and note it in the commit message.

- [ ] **Step 5: Add the `plotReservations()` relation to `Order`**

In `app/Domain/OrderWorkflow/Models/Order.php`, immediately after `statusEvents()` (around line 307), add — and add `use App\Domain\PlotReservation\Models\PlotReservation;` to the imports:

```php
    /**
     * The order's full append-only reservation chain, newest row first —
     * the same `created_at DESC, id DESC` order `PlotReservation
     * ::activeForOrder()` selects on, so `->first()` on an eager-loaded
     * chain IS the head row that method would return.
     *
     * Ordering lives in the relation rather than at each call site
     * precisely because eager loading is the point: `->with('plotReservations')`
     * carries the ordering with it, a `->with(['plotReservations' => fn (...)])`
     * closure at each call site would not.
     *
     * @return HasMany<PlotReservation, $this>
     */
    public function plotReservations(): HasMany
    {
        return $this->hasMany(PlotReservation::class, 'order_id')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }
```

- [ ] **Step 6: Prove the relation orders newest-first against real rows**

Append to the same test file:

```php
    public function test_plot_reservations_relation_returns_the_chain_newest_first(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-C1',
            'name' => 'Blok C1',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => 'C1-001',
            'plot_state' => PlotState::AVAILABLE,
        ]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $held = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now()->subHour(),
            'created_at' => CarbonImmutable::now()->subHour(),
            'updated_at' => CarbonImmutable::now()->subHour(),
        ]);
        $released = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now(),
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $chain = $order->fresh()->plotReservations;

        $this->assertSame((string) $released->id, (string) $chain->first()->id);
        $this->assertSame((string) $held->id, (string) $chain->last()->id);
        $this->assertNull(PlotReservation::incumbentOf($chain->first()));
    }
```

Add whatever of these imports the file lacks: `App\Domain\CemeteryDirectory\Models\Cemetery`, `App\Domain\OrderWorkflow\Models\Order`, `App\Domain\OrderWorkflow\OrderStatus`, `App\Domain\OrderWorkflow\ProductType`, `App\Domain\PlotInventory\Models\CemeteryBlock`, `App\Domain\PlotInventory\Models\GravePlot`, `App\Domain\PlotInventory\PlotState`, `Carbon\CarbonImmutable`, `Illuminate\Support\Str`.

- [ ] **Step 7: Run the tests to verify they pass**

Run the same command as Step 2, plus the untouched-behaviour guard:

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Domain/PlotReservation/ tests/Feature/Filament/BookingOrderReservationTest.php
```

Expected: PASS, no failures. `BookingOrderReservationTest` is the existing coverage of `activeForOrder()`'s behaviour — it must be green unchanged, because this task is a refactor.

- [ ] **Step 8: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/PlotReservation/PlotReservationState.php \
        app/Domain/PlotReservation/Models/PlotReservation.php \
        app/Domain/OrderWorkflow/Models/Order.php \
        app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php \
        tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php
git commit -m "refactor(plot-reservation): extract the incumbency rule and add Order::plotReservations()

Phase C's orders table must render each order's active reservation without
one query per row. Extracts activeForOrder()'s head-row-plus-active-state
rule into PlotReservation::incumbentOf() so it can be applied to an
eager-loaded chain, adds PlotReservationState::ACTIVE_STATES so the
held/confirmed pair stops being re-typed, and adds the newest-first
Order::plotReservations() relation eager loading needs. No behaviour change."
```

---

### Task 2: Redesign `BookingOrdersTable::configure()`

Move the shared, panel-agnostic table builder toward the roadmap's hotel-reservations-list shape: reference, customer, cemetery, package, product type, plot slot + state, status badge, submitted date; plus a cemetery filter and a "has reserved plot" toggle. The status badge column and `BookingOrderStatusBadge`/`StatusIntent` are **not touched**.

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php` (whole file)
- Test: `tests/Feature/Filament/BookingOrdersTableTest.php` (create)

**Interfaces:**
- Consumes: `PlotReservation::incumbentOf(?PlotReservation): ?PlotReservation`, `PlotReservationState::ACTIVE_STATES`, `Order::plotReservations(): HasMany` (all Task 1).
- Produces: `BookingOrdersTable::configure(Table $table): Table` — signature unchanged; still a plain panel-agnostic static, still safe to call from any panel's `Resource::table()`.

**Design notes for the implementer:**

- **Eager loading lives in `modifyQueryUsing`, not in a resource's `getEloquentQuery()`.** `BookingOrderResource::getEloquentQuery()` does `->with('bookingDraft')`, but `CemeteryOrderResource` (Task 4) will not — its query comes from the `ScopesToCurrentCemetery` trait, which calls `parent::getEloquentQuery()`. Putting every eager load in the shared table builder means both panels get identical, N+1-free behaviour from one place.
- **The plot state is rendered from the eager-loaded chain**, via `PlotReservation::incumbentOf($record->plotReservations->first())`. Never call `PlotReservation::activeForOrder()` from a column closure.
- **The "has reserved plot" filter cannot be a naive `whereHas('plotReservations', state IN (...))`.** Reservation rows are append-only: a released chain still *contains* a `held` row, so the naive form would match released orders. The correct predicate is "there exists an active row for this order with no newer row in the same chain", expressed with a row-value comparison. Row-value `>` on `(timestamp, uuid)` is supported by PostgreSQL (both columns have btree operator classes); this app is PostgreSQL-only by policy, so the raw fragment is acceptable — but it contains no user input, so there is no injection surface.
- **On `/operator`, the cemetery filter's option list will show every cemetery, not just granted ones.** `SelectFilter::getRelationshipQuery()` builds options from the terminal relation without the outer query's scope. This is acceptable: cemetery names are already public directory data, and selecting a non-granted cemetery yields zero rows because `applyCemeteryScope()` still applies. Record this in the class doc block so a reviewer does not mistake it for a leak that was missed.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/BookingOrdersTableTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\Pages\ListBookingOrders;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The redesigned shared order table — columns, the two new filters, and the
 * roadmap's explicit "no N+1" requirement, proven against real Postgres rows
 * through the real Livewire table component rather than by inspecting the
 * builder's configuration.
 */
final class BookingOrdersTableTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create(['name' => 'TPU Alpha']);
        $this->cemeteryB = Cemetery::factory()->create(['name' => 'TPU Beta']);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
    }

    private function orderFor(Cemetery $cemetery, string $customer): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->id,
            'customer_full_name' => $customer,
        ]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    private function holdAPlotFor(Order $order, Cemetery $cemetery, string $slot): PlotReservation
    {
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-'.Str::upper(Str::random(4)),
            'name' => 'Blok uji',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => $slot,
            'plot_state' => PlotState::RESERVED,
        ]);

        return PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_the_table_renders_the_cemetery_and_plot_columns(): void
    {
        $order = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->holdAPlotFor($order, $this->cemeteryA, 'A-001');

        Livewire::test(ListBookingOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertTableColumnStateSet('bookingDraft.cemetery.name', 'TPU Alpha', $order)
            ->assertSee('A-001');
    }

    public function test_the_plot_column_is_empty_for_an_order_whose_reservation_was_released(): void
    {
        $order = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $reservation = $this->holdAPlotFor($order, $this->cemeteryA, 'A-002');
        PlotReservation::query()->create([
            'plot_id' => $reservation->plot_id,
            'order_id' => $order->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now()->addMinute(),
        ]);

        Livewire::test(ListBookingOrders::class)
            ->assertCanSeeTableRecords([$order])
            ->assertDontSee('A-002');
    }

    public function test_the_cemetery_filter_narrows_to_one_cemeterys_orders(): void
    {
        $alpha = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $beta = $this->orderFor($this->cemeteryB, 'Citra Dewi');

        Livewire::test(ListBookingOrders::class)
            ->filterTable('cemetery', $this->cemeteryA->id)
            ->assertCanSeeTableRecords([$alpha])
            ->assertCanNotSeeTableRecords([$beta]);
    }

    public function test_the_has_reserved_plot_filter_keeps_only_actively_reserved_orders(): void
    {
        $reserved = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->holdAPlotFor($reserved, $this->cemeteryA, 'A-003');

        $unreserved = $this->orderFor($this->cemeteryA, 'Citra Dewi');

        $released = $this->orderFor($this->cemeteryA, 'Dewi Lestari');
        $releasedHold = $this->holdAPlotFor($released, $this->cemeteryA, 'A-004');
        PlotReservation::query()->create([
            'plot_id' => $releasedHold->plot_id,
            'order_id' => $released->id,
            'state' => PlotReservationState::RELEASED,
            'reserved_by_ref' => '1',
            'released_at' => CarbonImmutable::now()->addMinute(),
        ]);

        Livewire::test(ListBookingOrders::class)
            ->filterTable('has_reserved_plot')
            ->assertCanSeeTableRecords([$reserved])
            ->assertCanNotSeeTableRecords([$unreserved, $released]);
    }

    public function test_the_reservation_chain_is_eager_loaded_not_queried_per_row(): void
    {
        foreach (['Budi', 'Citra', 'Dewi', 'Eka'] as $index => $name) {
            $order = $this->orderFor($this->cemeteryA, $name);
            $this->holdAPlotFor($order, $this->cemeteryA, 'A-10'.$index);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        Livewire::test(ListBookingOrders::class)->assertOk();

        $reservationQueries = collect(DB::getQueryLog())
            ->filter(fn (array $entry): bool => str_contains($entry['query'], 'plot_reservations'))
            ->count();

        DB::disableQueryLog();

        // One eager load for the whole page. Four orders rendered through a
        // per-row `activeForOrder()` call would make this 4 (or 5 with the
        // filter's own subquery), so this assertion fails loudly the moment
        // the N+1 is reintroduced.
        $this->assertSame(1, $reservationQueries);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/BookingOrdersTableTest.php
```

Expected: FAIL — the cemetery/plot columns and both filters do not exist yet (`assertTableColumnStateSet` reports an unknown column; `filterTable('cemetery', ...)` reports an unregistered filter).

- [ ] **Step 3: Rewrite `BookingOrdersTable`**

Replace `app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php` entirely with:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Tables;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderProductTypeLabel;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared, panel-agnostic order list — a plain static builder with no
 * panel, resource or authorization coupling of any kind, which is what lets
 * BOTH `App\Filament\Admin\Resources\BookingOrders\BookingOrderResource`
 * (`/admin`, unscoped) and `App\Filament\Operator\Resources\CemeteryOrders
 * \CemeteryOrderResource` (`/operator`, cemetery-scoped) call it verbatim.
 * This is the codebase's first cross-panel reuse of a table builder — there
 * was no prior precedent for it — so the rule is stated explicitly: nothing
 * in this class may consult the current panel, the current actor, or a
 * Resource class. Row visibility is each Resource's `getEloquentQuery()`'s
 * job, and it alone.
 *
 * ---------------------------------------------------------------------------
 * Eager loading lives here, not in a Resource
 * ---------------------------------------------------------------------------
 * `modifyQueryUsing` carries every relation the columns read.
 * `BookingOrderResource::getEloquentQuery()` happens to eager-load
 * `bookingDraft` as well, but `CemeteryOrderResource`'s query comes from
 * `ScopesToCurrentCemetery` and does not — so putting the loads here is what
 * makes the two panels behave identically, and is what satisfies the
 * roadmap's explicit "no N+1" requirement for the plot column.
 *
 * ---------------------------------------------------------------------------
 * The cemetery filter's OPTIONS are unscoped, on both panels
 * ---------------------------------------------------------------------------
 * Filament builds a relationship filter's option list from the terminal
 * relation (`SelectFilter::getRelationshipQuery()`), without the outer
 * query's scope, so on `/operator` the dropdown lists every cemetery rather
 * than only granted ones. That is deliberate and not a leak: cemetery names
 * are already public directory data (the public cemetery directory serves
 * them to unauthenticated guests), and choosing a non-granted cemetery
 * returns zero rows because `CemeteryOrderResource::applyCemeteryScope()`
 * still applies to the outer query. Recorded so a reviewer does not read it
 * as an oversight.
 */
final class BookingOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'bookingDraft.cemetery',
                    'bookingDraft.cemeteryPackage',
                    'plotReservations.plot.block',
                ])
                ->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('reference')->label('Referensi')->searchable(),
                TextColumn::make('bookingDraft.customer_full_name')->label('Pemesan')->searchable(),
                TextColumn::make('bookingDraft.cemetery.name')
                    ->label('Makam')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('bookingDraft.cemeteryPackage.name')
                    ->label('Paket')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('product_type')
                    ->label('Jenis Layanan')
                    ->formatStateUsing(fn (string $state): string => BookingOrderProductTypeLabel::label(ProductType::from($state)))
                    ->toggleable(),
                TextColumn::make('plot')
                    ->label('Plot')
                    ->placeholder('Belum direservasi')
                    ->state(fn (Order $record): ?string => self::plotLabel($record))
                    ->description(fn (Order $record): ?string => self::plotStateLabel($record)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                    ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),
                TextColumn::make('created_at')->label('Diajukan')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn (OrderStatus $status): array => [$status->value => BookingOrderStatusBadge::label($status)]
                    )->all()),

                SelectFilter::make('cemetery')
                    ->label('Makam')
                    // Dot-notation nesting IS supported: Filament walks each
                    // hop in `HasRelationship::getRelationship()` to build
                    // the options, and hands the dotted name straight to
                    // Eloquent's nested-path-aware `whereHas()` to apply the
                    // filter. Verified against filament/filament v5.7.3.
                    ->relationship('bookingDraft.cemetery', 'name'),

                Filter::make('has_reserved_plot')
                    ->label('Punya plot direservasi')
                    ->query(fn (Builder $query): Builder => self::whereHasActiveReservation($query)),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }

    /**
     * The active reservation's plot, read from the ALREADY-EAGER-LOADED
     * chain — never through `PlotReservation::activeForOrder()`, which
     * would be one query per rendered row.
     */
    private static function plotLabel(Order $record): ?string
    {
        $reservation = PlotReservation::incumbentOf($record->plotReservations->first());

        if ($reservation === null) {
            return null;
        }

        return "{$reservation->plot->block->code} — {$reservation->plot->slot}";
    }

    private static function plotStateLabel(Order $record): ?string
    {
        $reservation = PlotReservation::incumbentOf($record->plotReservations->first());

        return match ($reservation?->state) {
            PlotReservationState::HELD => 'Ditahan',
            PlotReservationState::CONFIRMED => 'Dikonfirmasi',
            default => null,
        };
    }

    /**
     * "The order's reservation chain HEAD is active" as SQL.
     *
     * A naive `whereHas('plotReservations', state IN (held, confirmed))`
     * would be WRONG: the chain is append-only, so a released reservation
     * still leaves its original `held` row in the table and the naive
     * predicate would match it. The correct reading — the same one
     * `PlotReservation::incumbentOf()` applies in PHP — is "an active row
     * exists with no newer row in the same chain", where "newer" is the
     * `created_at DESC, id DESC` ordering the model already uses.
     *
     * The row-value comparison is PostgreSQL syntax, which is fine: this
     * app is PostgreSQL-only by policy (`docs/operations/local-test-recipe.md`
     * forbids SQLite even for tests). It interpolates no caller input, so
     * there is no injection surface.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function whereHasActiveReservation(Builder $query): Builder
    {
        return $query->whereHas('plotReservations', function (Builder $chain): void {
            $chain
                ->whereIn('plot_reservations.state', PlotReservationState::ACTIVE_STATES)
                ->whereNotExists(function ($newer): void {
                    $newer->selectRaw('1')
                        ->from('plot_reservations as newer_reservations')
                        ->whereColumn('newer_reservations.order_id', 'plot_reservations.order_id')
                        ->whereRaw(
                            '(newer_reservations.created_at, newer_reservations.id) '
                            .'> (plot_reservations.created_at, plot_reservations.id)'
                        );
                });
        });
    }
}
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/BookingOrdersTableTest.php \
  tests/Feature/Filament/BookingOrderListLocalizationTest.php \
  tests/Feature/Filament/BookingOrderResourceAccessTest.php
```

Expected: PASS. The two existing `/admin` order tests must be green unchanged — if `BookingOrderListLocalizationTest` asserts on the old `Dibuat` label for `created_at`, update that assertion to `Diajukan` and say so in the commit message (the roadmap calls the column "submitted date").

- [ ] **Step 5: Prove the N+1 test actually fails when the N+1 is present**

Temporarily change `plotLabel()`'s first line to `$reservation = PlotReservation::activeForOrder($record);`, re-run only `test_the_reservation_chain_is_eager_loaded_not_queried_per_row`, and confirm it FAILS with an actual count greater than 1. Then revert the line. A passing performance assertion that cannot fail is worthless; this step is what makes it load-bearing.

- [ ] **Step 6: Run the style, static-analysis and docs gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bash ci/verify-docs.sh
```

Expected: all three PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Resources/BookingOrders/Tables/BookingOrdersTable.php \
        tests/Feature/Filament/BookingOrdersTableTest.php \
        tests/Feature/Filament/BookingOrderListLocalizationTest.php
git commit -m "feat(orders): redesign the shared order table toward the reservations-list shape

Adds cemetery, package and active-plot columns, a cemetery filter and a
'has reserved plot' toggle, per the Phase C roadmap. Every relation the
columns read is eager-loaded in modifyQueryUsing so both the /admin and the
coming /operator panel get the same N+1-free query; a query-count assertion
pins that. The has-reserved-plot predicate matches the chain HEAD rather
than any active row, because the append-only chain keeps the original held
row after a release. Status badge and StatusIntent mapping untouched."
```

---

### Task 3: `CemeteryOrderAccessPolicy` — the operator resource's own gate

`CemeteryOrderResource` must NOT reuse `MasterDataAdminAuthorizerContract`, and `ActorRole::CEMETERY_OPERATOR` must NOT be added to `MasterDataAdminAuthorizer::AUTHORISED_ROLES`. That authorizer's own doc block says master data "is platform-wide: there is no record scope to check" — it performs no scoping whatsoever. Admitting `cemetery_operator` there would make every composed authorization check (including `ReservePlotAction`'s and `PlotReservationLifecycleActions`') answer "yes" for orders belonging to *every* cemetery, and `BookingOrderResource::getEloquentQuery()` — which is unscoped by design — would not stop it. That is a real cross-tenant exposure, not a cosmetic gap. This ruling is settled; do not re-litigate it.

This task ships the replacement gate: role **and** at least one active cemetery grant, the identical two-condition shape `CemeteryOperatorPanelAccessPolicy` already uses at the panel boundary. It is deliberately **not** a per-record check — record-level correctness comes from `ScopesToCurrentCemetery`'s query scoping in Task 4, so an unreachable record 404s rather than being caught by a merged authorizer. That split (role/grant-level gate + separately enforced query scope) is how `BookingOrderResource`, `VisitationBookingsResource` and `CemeteryOperatorPanelAccessPolicy` are all already structured.

**Files:**
- Create: `app/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicy.php`
- Test: `tests/Unit/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicyTest.php` (create)

**Interfaces:**
- Consumes: `ActorContext` (`isAuthenticated(): bool`, `hasRole(string): bool`), `CurrentCemeteryScope::hasAnyGrant(): bool`.
- Produces: `CemeteryOrderAccessPolicy::allows(ActorContext $actor): bool`. Resolved with `app(CemeteryOrderAccessPolicy::class)` — a plain concrete class with **no** contract interface, exactly like `CemeteryOperatorPanelAccessPolicy`, which `User::canAccessPanel()` resolves the same way. One consumer, no seam needed (YAGNI); if a second consumer ever appears, extracting a contract is a one-line binding change.

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicyTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Cemetery;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The `/operator` orders resource's own access gate. Deliberately NOT
 * `MasterDataAdminAuthorizer`: that authorizer performs no record scoping at
 * all (its own doc block: "there is no record scope to check"), so admitting
 * `cemetery_operator` there would answer "yes" for every cemetery's orders.
 * Both conditions are required and neither substitutes for the other — the
 * same argument `CemeteryOperatorPanelAccessPolicy` makes at the panel
 * boundary.
 */
final class CemeteryOrderAccessPolicyTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private function actor(): ActorContext
    {
        $this->app->forgetScopedInstances();

        return app(ActorContext::class);
    }

    public function test_a_guest_is_refused(): void
    {
        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_cemetery_operator_without_any_grant_is_refused(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_grant_without_the_role_is_refused(): void
    {
        $cemetery = Cemetery::factory()->create();
        $user = User::factory()->create();
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_an_admin_is_refused_because_this_gate_is_not_the_admin_gate(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertFalse(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }

    public function test_a_cemetery_operator_with_a_grant_is_admitted(): void
    {
        $cemetery = Cemetery::factory()->create();
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);

        $this->assertTrue(app(CemeteryOrderAccessPolicy::class)->allows($this->actor()));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Unit/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicyTest.php
```

Expected: FAIL — `Class "App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy" not found`.

- [ ] **Step 3: Write the policy**

Create `app/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicy.php`:

```php
<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Cemetery;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;

/**
 * Resource-level access check for `App\Filament\Operator\Resources
 * \CemeteryOrders\CemeteryOrderResource` — the `/operator` panel's orders
 * surface.
 *
 * ---------------------------------------------------------------------------
 * Why this exists instead of reusing `MasterDataAdminAuthorizer`
 * ---------------------------------------------------------------------------
 * `BookingOrderResource` (the `/admin` twin) delegates to
 * `MasterDataAdminAuthorizerContract`, whose implementation admits a fixed
 * four-role list and — by its own doc block — performs NO record scoping,
 * because master data "is platform-wide: there is no record scope to
 * check". Adding `cemetery_operator` to that list would therefore make
 * every composed authorization check in the order actions answer "yes" for
 * orders belonging to every cemetery, with nothing downstream to narrow it:
 * `BookingOrderResource::getEloquentQuery()` is deliberately unscoped. That
 * is cross-tenant exposure, so the operator surface gets its own gate.
 *
 * ---------------------------------------------------------------------------
 * Two conditions, and why this is NOT a per-record check
 * ---------------------------------------------------------------------------
 * Role AND at least one active cemetery grant — the same pair, for the same
 * reasons, as `Panel\CemeteryOperatorPanelAccessPolicy`: neither condition
 * substitutes for the other, and refusing an actor with an empty grant list
 * is more honest than admitting them to a uniformly empty table.
 *
 * Which cemetery's rows they then see is a different question, answered per
 * query by `App\Filament\Operator\Concerns\ScopesToCurrentCemetery` — so a
 * direct URL to another cemetery's order 404s at record resolution rather
 * than being refused here. This gate is deliberately grant-level, not
 * record-level; merging the two would duplicate an enforcement that already
 * has one correct home, and the duplicate is the copy that drifts.
 *
 * Widening either condition is an authorization change and carries
 * `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar.
 */
final class CemeteryOrderAccessPolicy
{
    public function __construct(
        private readonly CurrentCemeteryScope $cemeteries,
    ) {}

    public function allows(ActorContext $actor): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $actor->hasRole(ActorRole::CEMETERY_OPERATOR)) {
            return false;
        }

        return $this->cemeteries->hasAnyGrant();
    }
}
```

This also retires `CurrentCemeteryScope::hasAnyGrant()`'s "no production call site today" note — see Step 5.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Unit/Platform/IdentityAccess/
```

Expected: PASS.

- [ ] **Step 5: Update the now-stale `hasAnyGrant()` doc block**

In `app/Domain/CemeteryDirectory/Access/CurrentCemeteryScope.php`, replace the doc block above `hasAnyGrant()` — which currently reads "No production call site today — only `OperatorPanelScopingTest` calls this... a conscious decision to carry speculative API" — with:

```php
    /**
     * Read by `App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy`
     * as the grant half of the `/operator` orders resource's access gate.
     * Was speculative API when Phase A shipped it (mirroring
     * `CurrentVendorScope::hasAnyGrant()` exactly); Phase C is the consumer
     * that was anticipated.
     */
```

- [ ] **Step 6: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicy.php \
        app/Domain/CemeteryDirectory/Access/CurrentCemeteryScope.php \
        tests/Unit/Platform/IdentityAccess/Cemetery/CemeteryOrderAccessPolicyTest.php
git commit -m "feat(identity-access): add CemeteryOrderAccessPolicy for the /operator orders surface

Role + any-active-cemetery-grant, the same two-condition shape
CemeteryOperatorPanelAccessPolicy uses at the panel boundary. Deliberately
NOT a widening of MasterDataAdminAuthorizer: that authorizer performs no
record scoping at all, so admitting cemetery_operator there would pass
authorization for every cemetery's orders. Grant-level only — which rows an
admitted actor sees stays ScopesToCurrentCemetery's job.

Authorization change: requires human review before merge (AGENTS.md)."
```

---

### Task 4: `CemeteryOrderResource` — the `/operator` orders dashboard

**Files:**
- Create: `app/Filament/Operator/Resources/CemeteryOrders/CemeteryOrderResource.php`
- Create: `app/Filament/Operator/Resources/CemeteryOrders/Pages/ListCemeteryOrders.php`
- Create: `app/Filament/Operator/Resources/CemeteryOrders/Pages/ViewCemeteryOrder.php`
- Modify: `app/Providers/Filament/OperatorPanelProvider.php`
- Modify: `app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php` (doc block only — it currently says "No `/operator` Resource consumes this trait yet")
- Test: `tests/Feature/Filament/Operator/CemeteryOrderResourceTest.php` (create)

**Interfaces:**
- Consumes: `CemeteryOrderAccessPolicy::allows(ActorContext): bool` (Task 3); `BookingOrdersTable::configure(Table): Table` (Task 2); `BookingOrderInfolist::configure(Schema): Schema` (unchanged); `ScopesToCurrentCemetery::applyCemeteryScope(Builder): Builder` / `getEloquentQuery(): Builder`; `CurrentCemeteryScope::grantedCemeteryIds(): list<string>`.
- Produces: `CemeteryOrderResource::canAccess(): bool`; `CemeteryOrderResource::getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response`; `CemeteryOrderResource::applyCemeteryScope(Builder $query): Builder`. Tasks 5, 6 and 7 all call `CemeteryOrderResource::canAccess()`.

**Design notes:**

- **No edit page.** `BookingOrderResource` exposes one only because of a legacy minimal non-financial edit form whose save button is hidden; the roadmap frames `/operator` as read-mostly with row actions as the write path, and `Order`'s own write guard makes `update()` throw for every caller except the two status/paid-source doors. List + View only, matching `WorkOrdersResource` (the vendor panel's read-mostly precedent).
- **`applyCemeteryScope()` must be overridden, not inherited.** The trait's default `whereIn($query->qualifyColumn('cemetery_id'), ...)` would target `orders.cemetery_id`, a column that does not exist. `Order` reaches its cemetery two hops away: `orders.booking_draft_id -> booking_drafts.id`, and `booking_drafts.cemetery_id` is a real direct column with a `cemetery()` relation. The trait's own doc block already anticipates exactly this override.
- **A subquery, not a join.** `whereIn('booking_draft_id', BookingDraft::query()->whereIn('cemetery_id', ...)->select('id'))` keeps `orders` as the single base table, so Filament's record resolution, sorting, searching and the table's own `whereHas` filters all continue to work against unambiguous column names. A join would require qualifying every subsequent column reference in the shared table builder — coupling that builder to this one resource, which Task 2's contract forbids.
- **Orders with a NULL `booking_draft_id` are excluded**, and that is correct: an order with no draft has no cemetery, so no cemetery operator owns it.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/Operator/CemeteryOrderResourceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ListCemeteryOrders;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ViewCemeteryOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The cross-cemetery denial this resource exists to enforce, proven against
 * real Postgres rows: an operator granted cemetery A must not see, and must
 * not be able to reach by guessed record id, an order belonging to cemetery
 * B. Same rigor as Phase A Task 6's `OrderTransitionAuthorizerContract`
 * test — a "cannot see it in the list" assertion alone would not catch a
 * resource whose list is scoped but whose view page is not.
 */
final class CemeteryOrderResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    private Order $orderInA;

    private Order $orderInB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel('operator');

        $this->cemeteryA = Cemetery::factory()->create(['name' => 'TPU Alpha']);
        $this->cemeteryB = Cemetery::factory()->create(['name' => 'TPU Beta']);
        $this->orderInA = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->orderInB = $this->orderFor($this->cemeteryB, 'Citra Dewi');
    }

    private function orderFor(Cemetery $cemetery, string $customer): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->id,
            'customer_full_name' => $customer,
        ]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    private function actingAsOperatorGrantedTo(Cemetery $cemetery): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }

    public function test_the_list_shows_only_the_granted_cemeterys_orders(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        Livewire::test(ListCemeteryOrders::class)
            ->assertCanSeeTableRecords([$this->orderInA])
            ->assertCanNotSeeTableRecords([$this->orderInB]);
    }

    public function test_the_scoped_query_excludes_another_cemeterys_order_entirely(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $visible = CemeteryOrderResource::getEloquentQuery()->pluck('id')->map(strval(...))->all();

        $this->assertContains((string) $this->orderInA->id, $visible);
        $this->assertNotContains((string) $this->orderInB->id, $visible);
    }

    public function test_an_order_with_no_booking_draft_is_invisible_to_every_operator(): void
    {
        $draftless = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $visible = CemeteryOrderResource::getEloquentQuery()->pluck('id')->map(strval(...))->all();

        $this->assertNotContains((string) $draftless->id, $visible);
    }

    public function test_a_guessed_record_id_for_another_cemetery_is_not_reachable(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        // The view page resolves its record from the scoped query, so an id
        // the actor has no grant for resolves nothing at all. This is the
        // assertion that would catch a resource whose LIST is scoped but
        // whose VIEW page is reachable by direct URL.
        Livewire::test(ViewCemeteryOrder::class, ['record' => (string) $this->orderInB->id])
            ->assertNotFound();
    }

    public function test_the_operators_own_order_is_reachable_by_id(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        Livewire::test(ViewCemeteryOrder::class, ['record' => (string) $this->orderInA->id])
            ->assertOk()
            ->assertSee('Budi Santoso');
    }

    public function test_the_resource_refuses_an_operator_holding_no_grant(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(CemeteryOrderResource::canAccess());
    }

    public function test_the_resource_refuses_an_admin(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(CemeteryOrderResource::canAccess());
        $this->assertTrue(CemeteryOrderResource::getAuthorizationResponse('view')->denied());
    }

    public function test_the_resource_admits_a_granted_operator(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(CemeteryOrderResource::canAccess());
        $this->assertTrue(CemeteryOrderResource::getAuthorizationResponse('view')->allowed());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/Operator/CemeteryOrderResourceTest.php
```

Expected: FAIL — `Class "App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource" not found`.

- [ ] **Step 3: Write the Resource**

Create `app/Filament/Operator/Resources/CemeteryOrders/CemeteryOrderResource.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\Schemas\BookingOrderInfolist;
use App\Filament\Admin\Resources\BookingOrders\Tables\BookingOrdersTable;
use App\Filament\Operator\Concerns\ScopesToCurrentCemetery;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ListCemeteryOrders;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ViewCemeteryOrder;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Cemetery\CemeteryOrderAccessPolicy;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * `/operator/pesanan` — the cemetery operator's orders dashboard, the TPU/TPS
 * operator dashboard roadmap's Phase C.
 *
 * ---------------------------------------------------------------------------
 * Its own gate, deliberately NOT `BookingOrderResource`'s
 * ---------------------------------------------------------------------------
 * The `/admin` twin delegates to `MasterDataAdminAuthorizerContract`, which
 * performs no record scoping (its own doc block: master data "is
 * platform-wide: there is no record scope to check") and whose
 * `getEloquentQuery()` is correspondingly unscoped. Reusing either here —
 * or adding `cemetery_operator` to that authorizer's role list — would grant
 * an operator authorization over every cemetery's orders. So this resource
 * carries `CemeteryOrderAccessPolicy` (role + at least one active cemetery
 * grant) instead. See that class's doc block for the full argument.
 *
 * `getAuthorizationResponse()` is overridden alongside `canAccess()` for the
 * reason `BookingOrderResource` documents: in Filament 5 every row-action
 * predicate routes through it and, without the override, would fall through
 * to Filament's no-policy allow. Both answer from the same policy, so they
 * cannot disagree.
 *
 * ---------------------------------------------------------------------------
 * The scope column is two hops away, so `applyCemeteryScope()` is overridden
 * ---------------------------------------------------------------------------
 * `orders` has no `cemetery_id`. The chain is
 * `orders.booking_draft_id -> booking_drafts.id`, and `booking_drafts
 * .cemetery_id` is the real column. A subquery rather than a join, on
 * purpose: it keeps `orders` as the single base table, so record
 * resolution, sorting, searching and `BookingOrdersTable`'s own `whereHas`
 * filters all keep working against unqualified column names. A join would
 * force the SHARED table builder to qualify every column, coupling it to
 * this one resource.
 *
 * An order with a NULL `booking_draft_id` is excluded by construction, which
 * is correct: with no draft it has no cemetery, so no cemetery operator owns
 * it.
 *
 * ---------------------------------------------------------------------------
 * First cross-panel reuse of the `/admin` table and infolist builders
 * ---------------------------------------------------------------------------
 * `BookingOrdersTable::configure()` and `BookingOrderInfolist::configure()`
 * are plain panel-agnostic statics with no panel, actor or Resource
 * coupling, so they are reused VERBATIM here. There is no prior precedent in
 * this codebase for a `/operator` or `/vendor` resource calling an
 * `App\Filament\Admin\...` builder — this is the first, and it is safe
 * exactly because of that absence of coupling. Any future change to either
 * builder that reads the current panel or an actor would silently break this
 * resource's scoping guarantees; both classes' doc blocks say so.
 */
final class CemeteryOrderResource extends Resource
{
    use ScopesToCurrentCemetery;

    protected static ?string $model = Order::class;

    protected static ?string $slug = 'pesanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 10;

    public static function canAccess(): bool
    {
        return app(CemeteryOrderAccessPolicy::class)->allows(app(ActorContext::class));
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        return static::canAccess()
            ? Response::allow()
            : Response::deny('Anda tidak berwenang mengelola pesanan makam ini.');
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    public static function applyCemeteryScope(Builder $query): Builder
    {
        // whereIn() on an empty grant list compiles to an always-false
        // clause, so a guest and an ungranted actor both see nothing — the
        // deliberate closed default `CurrentCemeteryScope` documents.
        return $query->whereIn(
            $query->qualifyColumn('booking_draft_id'),
            BookingDraft::query()
                ->whereIn('cemetery_id', app(CurrentCemeteryScope::class)->grantedCemeteryIds())
                ->select('id'),
        );
    }

    public static function infolist(Schema $schema): Schema
    {
        return BookingOrderInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return BookingOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCemeteryOrders::route('/'),
            'view' => ViewCemeteryOrder::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan';
    }
}
```

- [ ] **Step 4: Write the two Pages**

Create `app/Filament/Operator/Resources/CemeteryOrders/Pages/ListCemeteryOrders.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders\Pages;

use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListCemeteryOrders extends ListRecords
{
    protected static string $resource = CemeteryOrderResource::class;
}
```

Create `app/Filament/Operator/Resources/CemeteryOrders/Pages/ViewCemeteryOrder.php`. It mirrors `ViewBookingOrder::getHeaderActions()` exactly — same factories, same order — because the roadmap's design is that the row actions are "reused unchanged in mechanism", and each factory owns its own state and actor gates:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Resources\CemeteryOrders\Pages;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Filament\Admin\Resources\BookingOrders\Actions\TransitionOrderAction;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The `/operator` order view page. Its header actions are the SAME factories
 * `App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder` builds,
 * in the same order — the roadmap's "reused unchanged in mechanism". Each
 * factory carries its own order-state gate (`->visible()`) and its own actor
 * gate (`->authorize()`), and each re-checks the actor gate inside its run
 * path, so nothing panel-specific belongs here.
 *
 * The record itself arrives already scoped: Filament resolves `{record}`
 * through `CemeteryOrderResource::getEloquentQuery()`, so an order belonging
 * to a cemetery this actor holds no grant for 404s before any action is
 * built.
 *
 * @see \App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder
 */
final class ViewCemeteryOrder extends ViewRecord
{
    protected static string $resource = CemeteryOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        $actions = [];

        /** @var Order $record */
        $record = $this->getRecord();

        foreach (OrderTransition::allowedFrom($record->status()) as $to) {
            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $record);
        }

        $actions[] = ReservePlotAction::make($record);

        $reservation = PlotReservation::activeForOrder($record);

        if ($reservation !== null) {
            $actions[] = PlotReservationLifecycleActions::confirm($record, $reservation);
            $actions[] = PlotReservationLifecycleActions::release($record, $reservation);
            $actions[] = PlotReservationLifecycleActions::expire($record, $reservation);
        }

        return $actions;
    }
}
```

- [ ] **Step 5: Register the resource on the panel**

In `app/Providers/Filament/OperatorPanelProvider.php`, add `use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;` to the imports and insert a `->resources([...])` call immediately before the existing `->pages([...])` call:

```php
            // Explicit registration, not `->discoverResources()`: this panel
            // names every surface it exposes, so a class appearing under
            // `App\Filament\Operator\Resources` cannot become reachable
            // without an intentional edit here.
            ->resources([
                CemeteryOrderResource::class,
            ])
```

Also update the provider's class doc block: replace "Ships only a placeholder Dashboard page and no discoverable Resources — later phases (C: orders dashboard, D: plot availability) add real Resources/Pages here." with "Ships the placeholder Dashboard page plus Phase C's `CemeteryOrderResource` (the orders dashboard). Phase D adds plot availability."

- [ ] **Step 6: Update the `ScopesToCurrentCemetery` doc block**

In `app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php`, replace the paragraph beginning "No `/operator` Resource consumes this trait yet" with:

```
 * `App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource`
 * (Phase C) is the first and currently only consumer. As anticipated here
 * when Phase A shipped the mechanism, it reaches its cemetery indirectly via
 * `bookingDraft.cemetery_id` and therefore overrides `applyCemeteryScope()`
 * with a subquery rather than relying on this trait's direct-column default;
 * `cemeteryScopeColumn()` is unused by it. A future Resource on a table with
 * a real `cemetery_id` column inherits both defaults unchanged.
```

- [ ] **Step 7: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/Operator/
```

Expected: PASS, including Phase A's `OperatorPanelAccessTest` and `OperatorPanelScopingTest` unchanged.

- [ ] **Step 8: Mutation-check the scoping assertion**

Temporarily replace `applyCemeteryScope()`'s body with `return $query;` and re-run `test_the_scoped_query_excludes_another_cemeterys_order_entirely` and `test_a_guessed_record_id_for_another_cemetery_is_not_reachable`. Both must FAIL. Then revert. A cross-tenant denial test that still passes with the scope removed is proving nothing, and this is the single most important assertion on the branch.

- [ ] **Step 9: Run the full gate set**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/ tests/Unit/Platform/
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bash ci/verify-docs.sh
```

Expected: all PASS.

- [ ] **Step 10: Commit**

```bash
git add app/Filament/Operator/Resources/ app/Filament/Operator/Concerns/ScopesToCurrentCemetery.php \
        app/Providers/Filament/OperatorPanelProvider.php \
        tests/Feature/Filament/Operator/CemeteryOrderResourceTest.php
git commit -m "feat(operator): add the cemetery-scoped CemeteryOrderResource at /operator/pesanan

The first real /operator Resource. Reuses BookingOrdersTable::configure() and
BookingOrderInfolist::configure() verbatim (the codebase's first cross-panel
reuse of those builders — safe because both are panel- and actor-agnostic
statics), and gates access with CemeteryOrderAccessPolicy rather than the
unscoped MasterDataAdminAuthorizer.

Order reaches its cemetery two hops away via bookingDraft.cemetery_id, so
applyCemeteryScope() is overridden with a booking_drafts subquery instead of
the trait's direct-column default, as the trait's own doc block anticipated.
Cross-cemetery denial is proven both for the list and for a directly
addressed record id, and mutation-checked.

Authorization change: requires human review before merge (AGENTS.md)."
```

---

### Task 5: Panel-aware order-view redirect

All four order-action redirect sites hardcode `route('filament.admin.resources.pesanan-pemakaman.view', ...)`. Now that `/operator` renders the same actions, a successful action taken by a `cemetery_operator` would redirect them into `/admin`, which `AdminPanelAccessPolicy` refuses — a real, user-visible dead end, not a cosmetic issue.

**Files:**
- Create: `app/Filament/Support/OrderViewUrl.php`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php:244`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php:201,240`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php:133`
- Test: `tests/Feature/Filament/OrderViewUrlTest.php` (create)

**Interfaces:**
- Consumes: `CemeteryOrderResource` (Task 4) — only indirectly, as the resource the operator panel registers for `Order::class`.
- Produces: `OrderViewUrl::for(Order $order): string` — an absolute URL to the current panel's view page for `$order`, falling back to `/admin`'s when no panel is current or the current panel registers no resource for `Order`.

**Verified API notes:** `Filament::getCurrentPanel(): ?Panel` (`FilamentManager.php:122`) returns null outside a panel request rather than throwing — unlike `getCurrentOrDefaultPanel()`, which dereferences a possibly-unset default. `Panel::getModelResource(string|Model): ?string` (`Panel/Concerns/HasComponents.php:204`) returns the resource class registered for a model in that panel, or null. `Resource::getUrl(?string $name, array $parameters, bool $isAbsolute, ?string $panel, ...)` (`Resources/Resource/Concerns/CanGenerateUrls.php:16`) accepts an explicit panel id. `BookingOrderResource` is the only resource in the codebase whose `$model` is `Order::class`, so per-panel resolution is unambiguous.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/OrderViewUrlTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Support\OrderViewUrl;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The order actions are shared by `/admin` and `/operator`; a hardcoded
 * `filament.admin.*` redirect would land a cemetery_operator on a panel
 * `AdminPanelAccessPolicy` refuses. These assertions pin that the redirect
 * target follows the panel the action was invoked from.
 */
final class OrderViewUrlTest extends TestCase
{
    use RefreshDatabase;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    public function test_it_resolves_the_admin_panels_url_inside_the_admin_panel(): void
    {
        Filament::setCurrentPanel('admin');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }

    public function test_it_resolves_the_operator_panels_url_inside_the_operator_panel(): void
    {
        Filament::setCurrentPanel('operator');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/operator/pesanan/'.$this->order->getKey(), $url);
        $this->assertStringNotContainsString('/admin/', $url);
    }

    public function test_it_falls_back_to_the_admin_panel_when_no_panel_is_current(): void
    {
        Filament::setCurrentPanel(null);

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }

    public function test_it_falls_back_to_the_admin_panel_when_the_current_panel_has_no_order_resource(): void
    {
        // The vendor panel registers no resource whose model is Order, so
        // getModelResource() returns null and the fallback must engage
        // rather than throwing.
        Filament::setCurrentPanel('vendor');

        $url = OrderViewUrl::for($this->order);

        $this->assertStringContainsString('/admin/pesanan-pemakaman/'.$this->order->getKey(), $url);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/OrderViewUrlTest.php
```

Expected: FAIL — `Class "App\Filament\Support\OrderViewUrl" not found`.

- [ ] **Step 3: Write the helper**

Create `app/Filament/Support/OrderViewUrl.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Domain\OrderWorkflow\Models\Order;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use Filament\Facades\Filament;
use Filament\Resources\Resource;

/**
 * "Where does this order's view page live, for the panel I am currently
 * in?" — the one answer the shared order actions redirect to.
 *
 * ---------------------------------------------------------------------------
 * Why this exists
 * ---------------------------------------------------------------------------
 * `ReservePlotAction`, `TransitionOrderAction` and
 * `PlotReservationLifecycleActions` all live under `App\Filament\Admin` but
 * are rendered by BOTH `/admin`'s `ViewBookingOrder` and `/operator`'s
 * `ViewCemeteryOrder` (Phase C). Each of them hardcoded
 * `route('filament.admin.resources.pesanan-pemakaman.view', ...)`, which
 * would bounce a successful `cemetery_operator` action into `/admin` — a
 * panel `AdminPanelAccessPolicy` refuses. One shared helper rather than four
 * near-identical inline resolutions, so the four sites cannot drift.
 *
 * ---------------------------------------------------------------------------
 * Resolution and fallback
 * ---------------------------------------------------------------------------
 * `Filament::getCurrentPanel()` (deliberately not
 * `getCurrentOrDefaultPanel()`, which dereferences a possibly-unset default)
 * returns null outside a panel request — a queued job, a console command, a
 * test that never entered a panel. `Panel::getModelResource()` returns null
 * when the current panel registers no resource for `Order` (the `/vendor`
 * panel, for instance). Either way this falls back to `/admin`'s resource,
 * which is the historical behaviour and therefore the safe default: it
 * cannot make any pre-Phase-C call site worse.
 */
final class OrderViewUrl
{
    public static function for(Order $order): string
    {
        $panel = Filament::getCurrentPanel();

        /** @var class-string<Resource>|null $resource */
        $resource = $panel?->getModelResource(Order::class);

        if ($panel !== null && $resource !== null) {
            return $resource::getUrl('view', ['record' => $order->getKey()], panel: $panel->getId());
        }

        return BookingOrderResource::getUrl('view', ['record' => $order->getKey()], panel: 'admin');
    }
}
```

- [ ] **Step 4: Replace all four call sites**

In `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`, add `use App\Filament\Support\OrderViewUrl;` and replace line 244:

```php
            redirect()->to(OrderViewUrl::for($order));
```

In `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php`, add the same import and replace line 201:

```php
            session()->put('url.intended', OrderViewUrl::for($order));
```

and line 240:

```php
            redirect()->to(OrderViewUrl::for($order));
```

In `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php`, add the same import and replace line 133:

```php
            redirect()->to(OrderViewUrl::for($order));
```

Note for the `url.intended` site: `PasswordReauthentication::ROUTE_NAME` on the line below it is still an `/admin`-panel page. That is left alone on purpose — the money transitions that reach it are `finance`/`admin` only (`OrderTransitionAuthorizer::MONEY_TRANSITIONS`), and `cemetery_operator` is refused by that authorizer before the re-authentication guard is ever consulted, so a cemetery operator cannot reach this branch. Add that sentence as a comment above the `redirect()->route(PasswordReauthentication::ROUTE_NAME);` line so the asymmetry is documented rather than looking like a missed site.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/OrderViewUrlTest.php \
  tests/Feature/Filament/BookingOrderTransitionActionTest.php \
  tests/Feature/Filament/BookingOrderReservationTest.php \
  tests/Feature/Filament/Operator/
```

Expected: PASS. Note `BookingOrderTransitionActionTest`'s audit tripwire is still asserting `'authenticated_actor'` at this point — that is correct until Task 8.

- [ ] **Step 6: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Support/OrderViewUrl.php \
        app/Filament/Admin/Resources/BookingOrders/Actions/ \
        tests/Feature/Filament/OrderViewUrlTest.php
git commit -m "fix(orders): redirect to the CURRENT panel's order view, not a hardcoded /admin route

The three shared order-action classes hardcoded
filament.admin.resources.pesanan-pemakaman.view at four call sites (one more
than the Phase C brief recorded — PlotReservationLifecycleActions was also
affected). Now that /operator renders the same actions, a successful action
by a cemetery_operator would have bounced them into a panel
AdminPanelAccessPolicy refuses.

OrderViewUrl::for() resolves the current panel's resource for Order and
falls back to /admin when there is no current panel or no registered
resource, so no pre-Phase-C behaviour changes. The re-authentication
redirect keeps its /admin target on purpose: money transitions are
finance/admin only, so cemetery_operator cannot reach it."
```

---

### Task 6: Make `ReservePlotAction` operator-aware and cemetery-scoped

Phase A's own plan (lines 23–25) records this as a "known, deliberate incompleteness" deferred to Phase C: `ALLOWED_ROLES` already lists `CEMETERY_OPERATOR`, but `roleAllowed()` composes `BookingOrderResource::canAccess()` first, which refuses that role unconditionally. Two things must change:

1. The composition must admit the `/operator` path as well as the `/admin` path.
2. **More importantly:** unlike `TransitionOrderAction` — which routes through the cemetery-aware `OrderTransitionAuthorizerContract` and is therefore already Phase-C-ready — `ReservePlotAction` has **no domain authorizer at all**. Its entire authorization lives in `roleAllowed()`, which today performs no per-order cemetery check whatsoever. Simply admitting `cemetery_operator` to the composition would let an operator granted only cemetery A reserve a plot for an order belonging to cemetery B. The per-order check is the load-bearing half of this task.

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`
- Modify: `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php` (rewrite)

**Interfaces:**
- Consumes: `CemeteryOrderResource::canAccess(): bool` (Task 4); `CurrentCemeteryScope::allows(?string $cemeteryId): bool`; `BookingOrderResource::canAccess(): bool` (unchanged).
- Produces: `ReservePlotAction::roleAllowed(Order $order): bool` — **signature changed** from the no-argument form; it now needs the record to answer the cemetery question. Both existing callers (`make()`'s `->authorize()` closure and `run()`) already have `$order` in scope. `ALLOWED_ROLES` is replaced by `PLATFORM_WIDE_ROLES` (`[OPERATOR, RESTRICTED_ADMIN, ADMIN]`); `CEMETERY_OPERATOR` moves out of the list and into its own explicit branch. Task 7 mirrors this exact shape.

- [ ] **Step 1: Rewrite the Phase A test to describe the new contract**

Replace `tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php` entirely:

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
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ReservePlotAction`'s actor gate, now that Phase C has made it
 * operator-aware.
 *
 * Phase A shipped this file asserting the opposite — that a
 * `cemetery_operator` was refused end to end — because `roleAllowed()`
 * composed the admin-only `BookingOrderResource::canAccess()` first. That
 * was a deliberate, documented intermediate state (see that plan's "Known,
 * deliberate incompleteness carried into Phase C", item 1), and this file
 * is its designed replacement, not a regression.
 *
 * The load-bearing assertion here is the cross-cemetery denial. Unlike
 * `TransitionOrderAction`, which routes through the cemetery-aware
 * `OrderTransitionAuthorizerContract`, this action has NO domain authorizer
 * — all of its authorization lives in `roleAllowed()`. Admitting the role
 * without the per-order cemetery check would let an operator granted
 * cemetery A reserve a plot against cemetery B's order.
 */
final class ReservePlotActionCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create();
        $this->cemeteryB = Cemetery::factory()->create();
    }

    private function orderFor(Cemetery $cemetery): Order
    {
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    private function actingAsCemeteryOperatorGrantedTo(?Cemetery $cemetery): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);

        if ($cemetery !== null) {
            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => (string) $cemetery->id,
            ]);
        }

        $this->actingAs($user);
        $this->app->forgetScopedInstances();
    }

    public function test_a_cemetery_operator_may_reserve_against_their_own_cemeterys_order(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }

    public function test_a_cemetery_operator_may_not_reserve_against_another_cemeterys_order(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryB))->isAuthorized());
    }

    public function test_a_cemetery_operator_holding_no_grant_at_all_is_refused(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo(null);

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }

    public function test_a_cemetery_operator_is_refused_for_an_order_with_no_booking_draft(): void
    {
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $draftless = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $this->assertFalse(ReservePlotAction::make($draftless)->isAuthorized());
    }

    public function test_the_platform_wide_roles_are_unaffected_and_still_cross_cemetery(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        // A platform-wide operator holds no cemetery grants at all, so
        // applying the cemetery check to them would deny every order. Both
        // cemeteries must stay reachable.
        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
        $this->assertTrue(ReservePlotAction::make($this->orderFor($this->cemeteryB))->isAuthorized());
    }

    public function test_finance_is_still_refused(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(ReservePlotAction::make($this->orderFor($this->cemeteryA))->isAuthorized());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php
```

Expected: FAIL — `test_a_cemetery_operator_may_reserve_against_their_own_cemeterys_order` asserts true but gets false, because `BookingOrderResource::canAccess()` still refuses the role.

- [ ] **Step 3: Rewrite `roleAllowed()`**

In `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`:

Add imports: `use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;` and `use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;`.

Replace the `ALLOWED_ROLES` constant and its doc block with:

```php
    /**
     * The PLATFORM-WIDE operational admission list — actors whose authority
     * is not scoped to any cemetery. Deliberately not finance: reserving a
     * plot is not a money-adjacent action, and finance's domain is money.
     *
     * `ActorRole::CEMETERY_OPERATOR` is deliberately NOT on this list even
     * though it is admitted by `roleAllowed()`. It is a cemetery-scoped
     * role, so it is answered by its own branch, which additionally
     * requires the order's cemetery to be among the actor's grants. Folding
     * it into this list would have meant either applying the cemetery check
     * to the platform-wide roles (which hold no grants, so every order would
     * be denied) or skipping it for everyone (cross-tenant exposure).
     *
     * @var list<string>
     */
    private const array PLATFORM_WIDE_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
    ];
```

Replace `roleAllowed()` entirely:

```php
    /**
     * The actor gate — two mutually independent paths, either of which
     * admits.
     *
     * 1. The `/admin` path, unchanged since P3: the actor passes
     *    `BookingOrderResource::canAccess()` (the platform-wide master-data
     *    gate) AND holds one of `PLATFORM_WIDE_ROLES`. These roles are not
     *    cemetery-scoped and hold no cemetery grants, so no cemetery check
     *    applies to them — applying one would deny them every order.
     *
     * 2. The `/operator` path, new in Phase C: the actor passes
     *    `CemeteryOrderResource::canAccess()` (role + at least one active
     *    cemetery grant) AND the order's own cemetery is among their
     *    grants.
     *
     * The per-order check in path 2 is the load-bearing half. Unlike
     * `TransitionOrderAction`, which delegates to the cemetery-aware
     * `OrderTransitionAuthorizerContract`, this action has NO domain
     * authorizer — all of its authorization lives here. Without the
     * `CurrentCemeteryScope::allows()` call, an operator granted cemetery A
     * could reserve a plot against cemetery B's order, because nothing
     * downstream re-checks.
     *
     * An actor holding BOTH an admin-tier role and `cemetery_operator` is
     * admitted by path 1 — correctly: they genuinely hold platform-wide
     * authority, and the narrower role does not subtract from it.
     *
     * `run()` re-checks this as its first act, because "the button was not
     * rendered" is not a security property.
     */
    private static function roleAllowed(Order $order): bool
    {
        $actor = app(ActorContext::class);

        if (BookingOrderResource::canAccess()) {
            foreach (self::PLATFORM_WIDE_ROLES as $role) {
                if ($actor->hasRole($role)) {
                    return true;
                }
            }
        }

        return CemeteryOrderResource::canAccess()
            && $actor->hasRole(ActorRole::CEMETERY_OPERATOR)
            && app(CurrentCemeteryScope::class)->allows($order->bookingDraft?->cemetery_id);
    }
```

Update the two callers to pass the record: in `make()`, `->authorize(fn (): bool => self::roleAllowed($order))`; in `run()`, `if (! self::roleAllowed($order)) {`.

Finally, update the class doc block's "`->authorize()` is the ACTOR gate" paragraph to name both paths, replacing the sentence "the admission list is operator / restricted_admin / admin — finance is deliberately excluded" with "two paths admit: an `/admin` actor holding one of the platform-wide operational roles, or a `cemetery_operator` whose grants include this order's own cemetery. Finance is deliberately excluded from both (finance's domain is money)." Delete the now-obsolete paragraph in `ALLOWED_ROLES`' old doc block that described the Phase A incompleteness.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php \
  tests/Feature/Filament/BookingOrderReservationTest.php \
  tests/Feature/OrderWorkflow/AdminOperatorActionsTest.php
```

Expected: PASS, with the two existing suites green unchanged (this task must not alter any `/admin` behaviour).

- [ ] **Step 5: Mutation-check the cross-cemetery denial**

Temporarily drop the `&& app(CurrentCemeteryScope::class)->allows(...)` clause and re-run `test_a_cemetery_operator_may_not_reserve_against_another_cemeterys_order`. It must FAIL. Then revert. This is the assertion the whole task exists for.

- [ ] **Step 6: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php \
        tests/Feature/Filament/ReservePlotActionCemeteryOperatorTest.php
git commit -m "feat(orders): make ReservePlotAction operator-aware and cemetery-scoped

Closes Phase A's documented deferral (its plan's 'Known, deliberate
incompleteness', item 1). roleAllowed() now admits either the /admin path
(BookingOrderResource::canAccess() + a platform-wide operational role,
unchanged) or the /operator path (CemeteryOrderResource::canAccess() +
cemetery_operator + the order's own cemetery among the actor's grants).

The per-order cemetery check is the load-bearing half: unlike
TransitionOrderAction, this action has no domain authorizer, so without it
an operator granted cemetery A could reserve against cemetery B's order.
Mutation-checked. The Phase A test file is rewritten rather than deleted —
its old name asserted a state this task deliberately ends.

Authorization change: requires human review before merge (AGENTS.md)."
```

---

### Task 7: Make `PlotReservationLifecycleActions` operator-aware and cemetery-scoped

**Scope note — this task is beyond the Phase C brief's explicit list.** The brief named `ReservePlotAction` as "the one action that composes `BookingOrderResource::canAccess()` first". That is not accurate: `PlotReservationLifecycleActions::roleAllowed()` (line 92) has the byte-identical composition, and its `ALLOWED_ROLES` does not include `CEMETERY_OPERATOR` at all. Since `ViewCemeteryOrder` (Task 4) renders these three actions alongside `ReservePlotAction`, leaving them unfixed ships an operator who can place a plot hold but cannot confirm, release, or expire it — a hold that only an admin can clear. **If the controller rules this out of Phase C scope, drop this task and file the gap explicitly; do not ship the half-feature silently.**

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php`
- Test: `tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php` (create)

**Interfaces:**
- Consumes: exactly what Task 6 consumes — `CemeteryOrderResource::canAccess()`, `CurrentCemeteryScope::allows(?string)`, `BookingOrderResource::canAccess()`.
- Produces: `PlotReservationLifecycleActions::roleAllowed(Order $order): bool` — signature changed from the no-argument form, identical shape to Task 6's. `ALLOWED_ROLES` becomes `PLATFORM_WIDE_ROLES` with the same three entries.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\Actions\PlotReservationLifecycleActions;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The three reservation lifecycle actions carry the SAME actor gate as
 * `ReservePlotAction` — including the per-order cemetery check — because
 * `/operator`'s `ViewCemeteryOrder` renders all four together. An operator
 * who can place a hold but cannot release it leaves a plot locked until an
 * admin intervenes.
 */
final class PlotReservationLifecycleCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->cemeteryA = Cemetery::factory()->create();
        $this->cemeteryB = Cemetery::factory()->create();
    }

    /**
     * @return array{Order, PlotReservation}
     */
    private function heldReservationIn(Cemetery $cemetery): array
    {
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->id,
            'code' => 'BLOK-'.Str::upper(Str::random(4)),
            'name' => 'Blok uji',
            'capacity' => 5,
            'is_active' => true,
        ]);
        $plot = GravePlot::query()->create([
            'block_id' => $block->id,
            'slot' => 'S-'.Str::upper(Str::random(4)),
            'plot_state' => PlotState::RESERVED,
        ]);
        $reservation = PlotReservation::query()->create([
            'plot_id' => $plot->id,
            'order_id' => $order->id,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => '1',
            'reserved_at' => CarbonImmutable::now(),
        ]);

        return [$order, $reservation];
    }

    private function actingAsCemeteryOperatorGrantedTo(Cemetery $cemetery): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();
    }

    public function test_an_operator_may_run_all_three_lifecycle_actions_on_their_own_cemeterys_hold(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryA);
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
        $this->assertTrue(PlotReservationLifecycleActions::release($order, $reservation)->isAuthorized());
        $this->assertTrue(PlotReservationLifecycleActions::expire($order, $reservation)->isAuthorized());
    }

    public function test_an_operator_may_not_run_them_on_another_cemeterys_hold(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryB);
        $this->actingAsCemeteryOperatorGrantedTo($this->cemeteryA);

        $this->assertFalse(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::release($order, $reservation)->isAuthorized());
        $this->assertFalse(PlotReservationLifecycleActions::expire($order, $reservation)->isAuthorized());
    }

    public function test_the_platform_wide_roles_are_unaffected(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryB);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertTrue(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
    }

    public function test_finance_is_still_refused(): void
    {
        [$order, $reservation] = $this->heldReservationIn($this->cemeteryA);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(PlotReservationLifecycleActions::confirm($order, $reservation)->isAuthorized());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php
```

Expected: FAIL — `test_an_operator_may_run_all_three_lifecycle_actions_on_their_own_cemeterys_hold` asserts true but gets false.

- [ ] **Step 3: Apply the same fix Task 6 applied**

In `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php`:

Add imports `use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;` and `use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;`.

Replace the `ALLOWED_ROLES` constant with:

```php
    /**
     * The platform-wide operational admission list — identical to
     * `ReservePlotAction::PLATFORM_WIDE_ROLES`, deliberately, because these
     * are the same class of non-money reservation action. Not finance.
     *
     * `cemetery_operator` is answered by its own branch in `roleAllowed()`,
     * which additionally requires the order's cemetery to be among the
     * actor's grants — see `ReservePlotAction::roleAllowed()`'s doc block
     * for the full argument, which applies here unchanged.
     *
     * @var list<string>
     */
    private const array PLATFORM_WIDE_ROLES = [
        ActorRole::OPERATOR,
        ActorRole::RESTRICTED_ADMIN,
        ActorRole::ADMIN,
    ];
```

Replace `roleAllowed()`:

```php
    /**
     * Structurally identical to `ReservePlotAction::roleAllowed()` — the
     * `/admin` path (master-data gate + a platform-wide role) or the
     * `/operator` path (cemetery gate + `cemetery_operator` + the order's
     * own cemetery among the actor's grants). See that method's doc block
     * for the reasoning; the two are kept the same shape on purpose,
     * because `/operator`'s `ViewCemeteryOrder` renders all four actions
     * together and a divergence between them would show up as an operator
     * able to place a hold they cannot clear.
     */
    private static function roleAllowed(Order $order): bool
    {
        $actor = app(ActorContext::class);

        if (BookingOrderResource::canAccess()) {
            foreach (self::PLATFORM_WIDE_ROLES as $role) {
                if ($actor->hasRole($role)) {
                    return true;
                }
            }
        }

        return CemeteryOrderResource::canAccess()
            && $actor->hasRole(ActorRole::CEMETERY_OPERATOR)
            && app(CurrentCemeteryScope::class)->allows($order->bookingDraft?->cemetery_id);
    }
```

Update all four callers to pass `$order`: the three `->authorize(fn (): bool => self::roleAllowed($order))` closures in `confirm()`, `release()` and `expire()`, and the `if (! self::roleAllowed($order)) {` guard at the top of `run()`.

- [ ] **Step 4: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php \
  tests/Feature/Filament/BookingOrderReservationTest.php \
  tests/Feature/Domain/PlotReservation/
```

Expected: PASS.

- [ ] **Step 5: Mutation-check the cross-cemetery denial**

Temporarily drop the `&& app(CurrentCemeteryScope::class)->allows(...)` clause and re-run `test_an_operator_may_not_run_them_on_another_cemeterys_hold`. It must FAIL. Then revert.

- [ ] **Step 6: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php \
        tests/Feature/Filament/PlotReservationLifecycleCemeteryOperatorTest.php
git commit -m "feat(orders): make the reservation lifecycle actions operator-aware and cemetery-scoped

Beyond the Phase C brief's explicit list, and deliberately so: this class
carries the byte-identical BookingOrderResource::canAccess() composition the
brief attributed to ReservePlotAction alone, and /operator's ViewCemeteryOrder
renders all four actions together. Without this, an operator could place a
plot hold but not confirm, release or expire it — a hold only an admin could
clear.

Same two-path gate and same per-order CurrentCemeteryScope::allows() check as
ReservePlotAction, kept structurally identical on purpose. Mutation-checked.

Authorization change: requires human review before merge (AGENTS.md)."
```

---

### Task 8: Teach `auditRoleFor()` about `cemetery_operator` and flip Phase A's tripwire

`BookingOrderResource::auditRoleFor(ActorContext $actor): string` walks `[ADMIN, RESTRICTED_ADMIN, OPERATOR, FINANCE]` and falls through to `'authenticated_actor'`. Both `TransitionOrderAction` and `ReservePlotAction` (and `PlotReservationLifecycleActions`) call it directly to populate the audit trail's `actor_role`, so a `cemetery_operator` transition is currently recorded as the generic sentinel — a real misattribution in the audit trail.

Phase A knew this and left a deliberately-failing tripwire: `BookingOrderTransitionActionTest::test_a_cemetery_operator_can_transition_their_own_cemeterys_order` asserts the *wrong* current value with a comment saying "it must start FAILING the moment Phase C teaches `auditRoleFor()` about `cemetery_operator`, which is the intended tripwire." **Flipping that assertion is this task's designed outcome, not an accidental regression.** The ruling that it is now safe to do so is settled: the `cemetery_operator` code paths that reach `auditRoleFor()` are, after Tasks 4/6/7, all cemetery-scoped.

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php` (`auditRoleFor()`, ~line 143)
- Modify: `tests/Feature/Filament/BookingOrderTransitionActionTest.php` (the tripwire assertion and its comment)

**Interfaces:**
- Consumes: `ActorContext::hasRole(string): bool`, `ActorRole::CEMETERY_OPERATOR`.
- Produces: `BookingOrderResource::auditRoleFor(ActorContext $actor): string` — signature unchanged; now returns `'cemetery_operator'` for an actor holding only that role, instead of `'authenticated_actor'`.

- [ ] **Step 1: Flip the tripwire assertion**

In `tests/Feature/Filament/BookingOrderTransitionActionTest.php`, replace the comment block and assertion at the end of `test_a_cemetery_operator_can_transition_their_own_cemeterys_order` with:

```php
        // Phase A left this assertion pinned to the WRONG value
        // ('authenticated_actor') as a deliberate tripwire, with a comment
        // saying it must start failing the moment Phase C taught
        // `auditRoleFor()` about `cemetery_operator`. Phase C did exactly
        // that, so this now asserts the correct attribution. The flip is the
        // tripwire working as designed, not a regression.
        $event = OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIVERIFIKASI->value)
            ->sole();
        $this->assertSame(ActorRole::CEMETERY_OPERATOR, $event->actor_role);
```

Add a test proving precedence is unchanged for the existing roles, and that a dual-role actor still reports the more privileged one:

```php
    public function test_audit_role_prefers_the_platform_wide_role_for_a_dual_role_actor(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertSame(ActorRole::ADMIN, BookingOrderResource::auditRoleFor(app(ActorContext::class)));
    }

    public function test_audit_role_still_falls_through_for_an_actor_with_no_recognised_role(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CUSTOMER);
        $this->actingAs($user);

        $this->assertSame('authenticated_actor', BookingOrderResource::auditRoleFor(app(ActorContext::class)));
    }
```

Add the imports the file lacks: `App\Filament\Admin\Resources\BookingOrders\BookingOrderResource` and `App\Platform\IdentityAccess\ActorContext`.

- [ ] **Step 2: Run the test to verify it fails**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/BookingOrderTransitionActionTest.php
```

Expected: FAIL — two failures: the flipped assertion (`Failed asserting that 'authenticated_actor' is identical to 'cemetery_operator'`) and `test_audit_role_still_falls_through_for_an_actor_with_no_recognised_role` should PASS already. The dual-role test should also PASS already. Confirm exactly one failure, the flipped one.

- [ ] **Step 3: Extend `auditRoleFor()`**

In `app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php`, replace `auditRoleFor()`:

```php
    /**
     * The single `actor_role` value recorded in an order's audit trail —
     * the first match in a fixed precedence order, most privileged first.
     *
     * `ActorRole::CEMETERY_OPERATOR` sits last, after the four platform-wide
     * roles, matching its position relative to them in
     * `ActorRole::KNOWN_ROLES` (whose declaration order IS precedence
     * order): a cemetery-scoped role is less privileged than any of the
     * back-office roles, so an actor holding both is attributed to the
     * broader one.
     *
     * It was added by the TPU/TPS operator dashboard roadmap's Phase C.
     * Phase A deliberately did not add it — at that point a
     * `cemetery_operator` reaching this method would have been attributed
     * without any cemetery scoping behind the call, so the honest record was
     * the `'authenticated_actor'` sentinel. Phase C's `CemeteryOrderResource`
     * and the cemetery-scoped gates on `ReservePlotAction` and
     * `PlotReservationLifecycleActions` are what made the attribution
     * truthful, and only then was it added.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        $precedence = [
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
            ActorRole::CEMETERY_OPERATOR,
        ];

        foreach ($precedence as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/BookingOrderTransitionActionTest.php \
  tests/Feature/Filament/BookingOrderResourceAccessTest.php \
  tests/Feature/OrderWorkflow/
```

Expected: PASS.

- [ ] **Step 5: Confirm no other `auditRoleFor()` was silently changed**

```bash
grep -rn "function auditRoleFor" app/
```

Expected: `RenewalOrderResource` and `MarketplaceOrderResource` also define one and are **not** modified by this task. Phase A's deferral item 2 named all three; only `BookingOrderResource` has a `cemetery_operator`-reachable call site after Phase C (renewals route through `RenewalTransitionAuthorizer`, and marketplace orders have no cemetery concept at all — `OrderTransitionAuthorizer`'s own doc block says a marketplace call site passes no cemetery id, so `cemetery_operator` can never pass there). State this explicitly in the commit message so the remaining two are a recorded, reasoned non-change rather than an oversight.

- [ ] **Step 6: Run the style and static-analysis gates**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: both PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php \
        tests/Feature/Filament/BookingOrderTransitionActionTest.php
git commit -m "fix(audit): attribute cemetery_operator order actions to the real role

auditRoleFor() fell through to the 'authenticated_actor' sentinel for
cemetery_operator, so every /operator order transition and reservation was
misattributed in the audit trail. The role now sits last in the precedence
walk, matching its position in ActorRole::KNOWN_ROLES, so a dual-role actor
is still attributed to the broader platform-wide role.

This DELIBERATELY flips Phase A's tripwire assertion in
BookingOrderTransitionActionTest, which pinned the known-wrong value with a
comment saying it must start failing the moment Phase C made this change.
The flip is the tripwire working as designed, not a regression.

RenewalOrderResource::auditRoleFor() and
MarketplaceOrderResource::auditRoleFor() are deliberately unchanged: neither
has a cemetery_operator-reachable call site after Phase C."
```

---

### Task 9: Extend the operator scoping test with the structural walk

`OperatorPanelScopingTest`'s own doc block instructs Phase C to do this: "Phase C, which adds the first real `/operator` Resource (`CemeteryOrderResource`), must EXTEND this file with that structural walk rather than replace it." The walk is what makes a *future* unscoped `/operator` resource fail CI, rather than relying on someone remembering to scope it. `VendorPanelScopingTest::test_every_resource_in_the_panel_applies_the_vendor_scope` is the model.

**Files:**
- Modify: `tests/Feature/Filament/Operator/OperatorPanelScopingTest.php`

**Interfaces:**
- Consumes: `ScopesToCurrentCemetery` (trait), `CemeteryOrderResource` (Task 4).
- Produces: nothing consumed by other tasks. This is the branch's regression net.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Filament/Operator/OperatorPanelScopingTest.php` (inside the existing class), adding imports `Filament\Resources\Resource`, `Filament\Pages\Page`, `Filament\Tables\Contracts\HasTable`, `Symfony\Component\Finder\Finder` and `App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource`:

```php
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
        // A Resource's own List/View pages are Page subclasses that render a
        // table, but their query comes from the Resource, which the walk
        // above already checks — so they are excluded here. Only standalone
        // table pages, which own their query, are walked.
        $pages = array_filter(
            $this->panelClassesThatAre(Page::class),
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
```

Also update the class doc block: replace the paragraph beginning "This is deliberately NOT the full resource-walking structural test" with a note that Phase C added the walk, and that `CemeteryBlock` remains the stand-in model for the trait's direct-column default path (which `CemeteryOrderResource` overrides).

- [ ] **Step 2: Run the test to verify it fails when the scope is removed**

The walk should pass immediately, since Task 4 already applied the trait — so the failing-first proof is a mutation instead. Temporarily remove `use ScopesToCurrentCemetery;` from `CemeteryOrderResource`, run:

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/Operator/OperatorPanelScopingTest.php
```

Expected: FAIL on both `test_the_orders_resource_is_registered_and_scoped` and `test_every_resource_in_the_operator_panel_applies_the_cemetery_scope`, with the explanatory message. Then restore the trait.

- [ ] **Step 3: Run the tests to verify they pass**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Filament/Operator/
```

Expected: PASS.

- [ ] **Step 4: Run the whole affected suite plus every gate**

```bash
... <img> php -d memory_limit=512M vendor/bin/phpunit \
  tests/Feature/Filament/ tests/Feature/Domain/PlotReservation/ tests/Feature/OrderWorkflow/ tests/Unit/Platform/
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html <img> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bash ci/verify-docs.sh
```

Expected: all PASS. Record the exact test/assertion counts in the task report — do not report `PASS` for anything not actually executed.

- [ ] **Step 5: Tear down the test containers**

```bash
docker rm -f ordersdash-pg ordersdash-redis
```

- [ ] **Step 6: Commit**

```bash
git add tests/Feature/Filament/Operator/OperatorPanelScopingTest.php
git commit -m "test(operator): add the structural cemetery-scope walk to the panel scoping test

Phase A's OperatorPanelScopingTest doc block asked Phase C to extend it with
the resource-walking test once a real /operator Resource existed, mirroring
VendorPanelScopingTest. Every concrete Resource and standalone table page
under app/Filament/Operator must apply ScopesToCurrentCemetery; the walk is
filesystem-driven, so a future unscoped surface fails CI without anyone
remembering to register it here."
```

---

## Verification before opening the PR

- [ ] Full affected-suite run against real Postgres 18 + Redis 8.2, with the actual test and assertion counts recorded. No SQLite.
- [ ] `vendor/bin/pint --test` — PASS.
- [ ] `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — PASS.
- [ ] `bash ci/verify-docs.sh` — PASS.
- [ ] `grep -rn "filament\.admin\." app/Filament/Admin/Resources/BookingOrders/` returns only the `PasswordReauthentication::ROUTE_NAME` line, which is intentionally left admin-bound (money transitions are finance/admin only).
- [ ] Every mutation check in Tasks 2, 4, 6, 7 and 9 was actually run and actually failed before being reverted. A green authorization test that passes with the check removed proves nothing.
- [ ] Push and confirm the CI result rather than running composer/npm builds on this host.
- [ ] **Request human review before merge.** This branch introduces a new cross-tenant-scoped resource and changes the authorization gate of three existing action classes — squarely inside `AGENTS.md` §Infrastructure-agent execution's mandatory-human-review bar. The reviewer should focus on: (a) that `MasterDataAdminAuthorizer::AUTHORISED_ROLES` is genuinely unchanged; (b) that `CemeteryOrderResource::applyCemeteryScope()`'s subquery cannot be bypassed by any page in the resource; (c) that the two-path `roleAllowed()` in Tasks 6 and 7 cannot admit a `cemetery_operator` for an order outside their grants by any combination of roles.

## Self-review notes

**Spec coverage.** Every item in the roadmap's Phase C section and the research brief's numbered list maps to a task: table redesign → Task 2 (with Task 1 as its N+1-free foundation); `CemeteryOrderResource` with the join/subquery scope override → Task 4 (gate in Task 3); row actions reused unchanged in mechanism → Tasks 4/6/7; `ReservePlotAction` fix → Task 6; redirect fix → Task 5; `auditRoleFor()` fix plus Phase A test flip → Task 8; real Postgres feature tests for all six named behaviours → distributed across Tasks 2, 4, 5, 6, 7, 8, plus the structural net in Task 9. Two coverage items were added beyond the brief and are flagged as such in "Corrections": the fourth redirect call site (`PlotReservationLifecycleActions`, Task 5) and that same class's identical authorization gap (Task 7, explicitly droppable if ruled out of scope). One brief item was deliberately not actioned: the `docs/security/rbac-matrix.md` update, with the reason given in Corrections item 10.

**Placeholder scan.** No "TBD", no "similar to Task N", no "add appropriate error handling". Every code step carries the literal code to write; every test step carries the literal test. The one intentionally parameterised value is `<img>` in the docker commands (the pinned image tag, which the implementer resolves with `docker images --digests | grep makam-app` per §2 of the test recipe) and the container ports, which the recipe requires to be chosen per-worktree rather than fixed.

**Type and signature consistency.** `roleAllowed(Order $order): bool` has the same changed signature in Tasks 6 and 7, and both tasks list it in their Interfaces block with the change called out. `PLATFORM_WIDE_ROLES` replaces `ALLOWED_ROLES` in both, with the same three entries. `PlotReservation::incumbentOf(?PlotReservation): ?PlotReservation` is defined in Task 1 and consumed with that exact signature in Task 2. `CemeteryOrderResource::canAccess(): bool` is produced in Task 4 and consumed in Tasks 6 and 7. `OrderViewUrl::for(Order): string` is produced in Task 5 and used at four call sites in that same task. `CemeteryOrderAccessPolicy::allows(ActorContext): bool` is produced in Task 3 and consumed in Task 4. `PlotReservationState::ACTIVE_STATES` is produced in Task 1 and consumed in Tasks 1 and 2. No task references a symbol no task defines.
