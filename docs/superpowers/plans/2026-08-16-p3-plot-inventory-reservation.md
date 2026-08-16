# P3 — Plot Inventory + Reservation Module Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Authoritative plot inventory (blocks + bulk-generated plots with states) and an atomic, operator-initiated reservation module wired into the P1 admin order journey.

**Architecture:** Three file-disjoint lanes on trunk. PlotInventory: `CemeteryBlock` + `GravePlot` models, `PlotState` constants, `CreateCemeteryBlock` action (bulk generation), admin surfaces (BlocksRelationManager + GravePlotsResource). PlotReservation: append-only `PlotReservation` rows, four actions (`ReservePlot` with plot-row lock + `plot_state` aggregate enforcing one active hold per plot — a partial unique index was tried and rejected, see Global Constraints — plus Confirm/Release/Expire), new catalogued event. Booking integration: reservation header actions + infolist section on `ViewBookingOrder`. Spec: `docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md`.

**Tech Stack:** Laravel 13 / PHP 8.5 / Filament 5 / Livewire 4 / PostgreSQL 18 + SQLite (tests).

## Global Constraints

- Plot state changes happen ONLY through the reservation actions or the admin state-override action; never via bare model writes in resources (the `RecordOrderStatusChange` sole-writer discipline, same shape).
- `ReservePlot` transaction order: `lockForUpdate` plot → assert `available` → insert HELD row → flip plot state → audit → outbox, one transaction (AC4).
- One active hold per plot: enforced by the plot-row `lockForUpdate()` serialization (every reservation action locks the plot row FIRST) + the plot's `plot_state` aggregate asserted under that lock (available → reserved → available on release/expire), with order-level idempotency via `activeForOrder()`. **Rejected alternative (recorded):** the original design used a partial unique index `plot_reservations_active_hold` on `(plot_id) WHERE state = 'held'`; it was proven on PG18 + SQLite to never release (append-only rows keep the first `held` row's entry forever — a plot could only ever be held once), so it was dropped by `2026_08_16_100030_drop_plot_reservations_active_hold_index.php`.
- Order-level idempotency: an order with an active reservation (state held OR confirmed) returns the incumbent.
- Append-only reservations: every transition inserts a new row; no updates/deletes on `plot_reservations`.
- Audit actions (new constants): `CEMETERY_BLOCK_CREATED`, `GRAVE_PLOTS_GENERATED`, `GRAVE_PLOT_STATE_CHANGED`, `PLOT_RESERVATION_CREATED`, `PLOT_RESERVATION_CONFIRMED`, `PLOT_RESERVATION_RELEASED`, `PLOT_RESERVATION_EXPIRED` — none on `SensitiveActions::ACTIONS` (machine/operator routine, same rationale as the marketplace constants).
- New catalogued event `plot_reservation.state_changed.v1` (append to `docs/contracts/event-catalog.md`; payload reservation_id/plot_id/from_state/to_state; outbox idempotency key `plot_reservation:{$reservationId}`; `OutboxClassification::Internal`).
- Slots zero-padded `001..N` per block (capacity rows), all `available` on generation; `(block_id, slot)` unique.
- `GravePlot` delete blocked while reservations exist or state ≠ available (honest refusal); `CemeteryBlock` delete blocked while plots exist.
- Admin surfaces gated by `MasterDataAdminAuthorizerContract` + `auditRoleFor`; Indonesian labels; every write in `Audit::wrap` with `AuditSource::Panel` except the reservation actions' own self-audit (like the package actions — do NOT double-wrap).
- Public flow and Order schema unchanged (the reservation carries `order_id`).
- Gates: `composer lint`, `composer analyse`, `php artisan test` per lane; CI (incl. PG18) gates every merge; the two-connection concurrency test driver-guards `pgsql` (skip otherwise — the RecordOrderStatusChangeTwoConnectionTest pattern).
- Worktree execution: branch per lane from `docs/design-system-and-planning`; ledger at `.superpowers/sdd/2026-08-16-plot-inventory-reservation/progress.md`.

---

## Task 1: PlotInventory models + CreateCemeteryBlock (Lane 1)

**Files:**
- Create: `database/migrations/<ts>_create_cemetery_blocks_table.php`
- Create: `database/migrations/<ts>_create_grave_plots_table.php`
- Create: `app/Domain/PlotInventory/PlotState.php`
- Create: `app/Domain/PlotInventory/PlotInventoryAuditActions.php`
- Create: `app/Domain/PlotInventory/Models/CemeteryBlock.php`
- Create: `app/Domain/PlotInventory/Models/GravePlot.php`
- Create: `app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php`
- Create: `app/Domain/PlotInventory/Providers/PlotInventoryServiceProvider.php` (empty register — reserved; or skip if no bindings needed — skip; provider only if a contract exists)
- Test: `tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php`, `tests/Unit/Domain/PlotInventory/CemeteryBlockModelTest.php`

**Interfaces:**
- Consumes: `Cemetery` (id), `CemeteryPackage` (optional class link), `Audit::wrap`, `AuditSubject`, `AuditOutcome`, `AuditSource::Panel`, `MasterDataAdminAuthorizerContract` (resource gates in Task 2).
- Produces:
  - `PlotState`: `AVAILABLE='available'`, `RESERVED='reserved'`, `OCCUPIED='occupied'`, `MAINTENANCE='maintenance'` + `KNOWN_STATES` + `isKnown`/`assertKnown`.
  - `PlotInventoryAuditActions`: `CEMETERY_BLOCK_CREATED='CEMETERY_BLOCK_CREATED'`, `GRAVE_PLOTS_GENERATED='GRAVE_PLOTS_GENERATED'`.
  - `CemeteryBlock` (table `cemetery_blocks`): fillable `['cemetery_id','code','name','capacity','is_active']`; casts is_active→boolean, capacity→integer; `booted()` saving: code = `Str::upper(trim($code))` + assert non-blank; capacity ≥ 1 (InvalidArgumentException); `plots(): HasMany`, `cemetery(): BelongsTo`.
  - `GravePlot` (table `grave_plots`): fillable `['block_id','slot','plot_state','cemetery_package_id']`; casts plot_state→string; `booted()` saving asserts `PlotState::assertKnown`; `deleting()` refuses when reservations exist (query `plot_reservations` where plot_id) or `plot_state !== available` (PlotInventoryDeleteBlockedException or plain InvalidArgumentException — choose InvalidArgumentException with an honest message); `block(): BelongsTo`, `cemeteryPackage(): BelongsTo`, `reservations(): HasMany`.
  - `CreateCemeteryBlock::__invoke(Cemetery $cemetery, string $code, string $name, int $capacity, int|string $actorReference, ?string $actorRole = 'admin', ?int $cemeteryPackageId = null, AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): CemeteryBlock` — one `DB::transaction` + `Audit::wrap`: create block; generate `$capacity` plot rows with slots `str_pad((string) $i, 3, '0', STR_PAD_LEFT)` for `$i = 1..$capacity`, all `plot_state = available`, `cemetery_package_id` = the optional class link; audit `CEMETERY_BLOCK_CREATED` (subject `cemetery_block`, metadata `['capacity' => $capacity]` — verify `capacity` is MetadataAllowlist-allowed, else omit) + `GRAVE_PLOTS_GENERATED` (subject `cemetery_block`, metadata `['plot_count' => $capacity]`); return the block with `load('plots')`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotInventory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CreateCemeteryBlockTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::factory()->create();
    }

    public function test_creates_block_and_generates_capacity_plots(): void
    {
        $cemetery = $this->cemetery();

        $block = app(CreateCemeteryBlock::class)(
            $cemetery,
            'BLOK-A',
            'Blok A',
            3,
            'user:1',
            'operator',
        );

        $this->assertSame('BLOK-A', $block->code);
        $this->assertSame(3, $block->capacity);
        $this->assertSame(3, $block->plots()->count());
        $this->assertSame(['001', '002', '003'], $block->plots()->orderBy('slot')->pluck('slot')->all());
        foreach ($block->plots as $plot) {
            $this->assertSame(PlotState::AVAILABLE, $plot->plot_state);
        }
        $this->assertDatabaseHas('audit_events', ['action' => 'CEMETERY_BLOCK_CREATED']);
        $this->assertDatabaseHas('audit_events', ['action' => 'GRAVE_PLOTS_GENERATED']);
    }

    public function test_code_is_normalized_to_uppercase(): void
    {
        $block = app(CreateCemeteryBlock::class)('placeholder', 'blok-b', 'Blok B', 1, 'user:1', 'operator');
        $this->assertSame('BLOK-B', $block->code);
    }

    public function test_capacity_must_be_positive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(CreateCemeteryBlock::class)($this->cemetery(), 'BLOK-C', 'Blok C', 0, 'user:1', 'operator');
    }

    public function test_class_link_is_applied_to_generated_plots(): void
    {
        $cemetery = $this->cemetery();
        $package = $cemetery->packages()->create(['name' => 'Paket Utama', 'is_active' => true]);

        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-D', 'Blok D', 2, 'user:1', 'operator', $package->getKey());

        $this->assertSame($package->getKey(), $block->plots()->first()->cemetery_package_id);
    }
}
```

`tests/Unit/Domain/PlotInventory/CemeteryBlockModelTest.php` — block code normalization + capacity guard + plot state guard + delete-blocked:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\PlotInventory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class CemeteryBlockModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_plot_delete_blocked_when_not_available(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => PlotState::OCCUPIED]);

        $this->expectException(\InvalidArgumentException::class);
        $plot->delete();
    }

    public function test_plot_state_must_be_known(): void
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        $this->expectException(\InvalidArgumentException::class);
        GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'bogus']);
    }
}
```

Note: verify `Cemetery::factory()` exists and its required fields (check `database/factories/CemeteryFactory.php`); adjust the fixture to the real factory shape (e.g. `Cemetery::factory()->create([...required...])`) if fields are required. `CemeteryPackage` belongs to `App\Domain\CemeteryCapability\Models\CemeteryPackage` (the cemetery_packages table — the P2 PackagesRelationManager model); create it via `$cemetery->packages()->create(['name' => ..., 'is_active' => true])` with any other required fields from its fillable.

- [ ] **Step 2: Run to verify they fail** — `composer dump-autoload --no-scripts` then `APP_BASE_PATH=<worktree> php artisan test tests/Feature/Domain/PlotInventory tests/Unit/Domain/PlotInventory` → FAIL (classes not found).

- [ ] **Step 3: Implement the models, constants, action**

`PlotState.php` (constants class per the repo convention), `PlotInventoryAuditActions.php`, `CemeteryBlock`, `GravePlot` per the Produces block (guard message wording honest + Indonesian-free in code; Indonesian only in UI). `CreateCemeteryBlock` per the Produces block — verify `MetadataAllowlist` allows the metadata keys you use (check `app/Platform/Audit/MetadataAllowlist.php`; if `capacity`/`plot_count` aren't allowed, omit metadata or use `Audit::record` reason text).

- [ ] **Step 4: Run the tests** → PASS. Fix factory shapes against reality.
- [ ] **Step 5: Gates + commit** — `composer lint`, `composer analyse`, the two test files green. Commit: `feat(plot-inventory): cemetery blocks with bulk-generated plots (P3 lane 1)`.

---

## Task 2: Admin surfaces for PlotInventory (Lane 1)

**Files:**
- Create: `app/Filament/Admin/Resources/CemeteryResource/RelationManagers/BlocksRelationManager.php`
- Create: `app/Filament/Admin/Resources/GravePlots/GravePlotsResource.php`
- Create: `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php`
- Create: `app/Filament/Admin/Resources/GravePlots/Schemas/GravePlotForm.php`
- Create: `app/Filament/Admin/Resources/GravePlots/Pages/ListGravePlots.php`
- Modify: `app/Filament/Admin/Resources/CemeteryResource/CemeteryResource.php` (register `BlocksRelationManager` in `getRelations()`)
- Test: `tests/Feature/Filament/PlotInventoryAdminTest.php`

**Interfaces:**
- Consumes: Task 1's models/action/constants; `MasterDataAdminAuthorizerContract`; the PackagesRelationManager pattern.
- Produces: `BlocksRelationManager` (relationship `blocks` on Cemetery — add `blocks(): HasMany` to Cemetery if missing; title 'Blok Makam'; create form: code, name, capacity, optional cemetery_package_id select from the cemetery's packages, is_active; CreateAction `->using()` → `CreateCemeteryBlock`); `GravePlotsResource` (table: block.cemetery.name, block.code, slot, plot_state badge, cemetery_package name; filters cemetery/block/state; state-override actions 'Tandai Terisi'/'Tandai Perawatan'/'Tandai Tersedia' per-state visible, each `Audit::wrap` + `GRAVE_PLOT_STATE_CHANGED`, writing `plot_state` only through a small `ChangePlotState` action? — NO: keep it in the resource's `->using()` with `Audit::wrap` + the model guard; honest errors surface; no delete action).

- [ ] **Step 1: Write the failing test** — access matrix (4 roles admitted, vendor denied) for both surfaces; block create via the RM route (`callTableAction` with cemetery owner) generates plots with audit; plot state override flips state + audits; occupied plot override to available allowed, delete blocked.
- [ ] **Step 2: Run to verify it fails** → FAIL.
- [ ] **Step 3: Implement** — PackagesRelationManager pattern (instance form/makeTable, canViewForRecord, ->authorize()); GravePlotsResource per the CemeteryResource gate + auditRoleFor shape. Verify Cemetery has no `blocks` relation yet — add `blocks(): HasMany` to `app/Domain/CemeteryDirectory/Models/Cemetery.php` (one-line, disclosed).
- [ ] **Step 4: Run test + gates + commit** — `feat(filament): plot inventory admin surfaces (P3 lane 1)`.

---

## Task 3: PlotReservation model + ReservePlot (Lane 2 — the concurrency core)

**Files:**
- Create: `database/migrations/<ts>_create_plot_reservations_table.php`
- Create: `app/Domain/PlotReservation/PlotReservationState.php`
- Create: `app/Domain/PlotReservation/PlotReservationAuditActions.php`
- Create: `app/Domain/PlotReservation/Models/PlotReservation.php`
- Create: `app/Domain/PlotReservation/Exceptions/PlotNotAvailableException.php`
- Create: `app/Domain/PlotReservation/Actions/ReservePlot.php`
- Modify: `docs/contracts/event-catalog.md` (add `plot_reservation.state_changed.v1`)
- Test: `tests/Feature/Domain/PlotReservation/ReservePlotTest.php`, `tests/Feature/Domain/PlotReservation/ReservePlotTwoConnectionTest.php`

**Interfaces:**
- Consumes: Task 1's `GravePlot`/`PlotState`; `Order` (App\Domain\OrderWorkflow\Models\Order); `Outbox::record` (signature: eventName, eventVersion, aggregateType, aggregateId, data, classification, idempotencyKey); `Audit::wrap`; `CorrelationContext::current()?->value`.
- Produces:
  - `PlotReservationState`: `HELD='held'`, `CONFIRMED='confirmed'`, `RELEASED='released'`, `EXPIRED='expired'` + KNOWN + isKnown/assertKnown.
  - `PlotReservationAuditActions`: `PLOT_RESERVATION_CREATED='PLOT_RESERVATION_CREATED'`, `PLOT_RESERVATION_CONFIRMED`, `PLOT_RESERVATION_RELEASED`, `PLOT_RESERVATION_EXPIRED`.
  - `PlotNotAvailableException extends RuntimeException` with `static forPlot(int|string $plotId): self`.
  - `PlotReservation` (table `plot_reservations`): fillable `['plot_id','order_id','state','reserved_by_ref','reason','reserved_at','confirmed_at','released_at','expired_at']`; casts the four timestamps→immutable_datetime; append-only (`update()`/`delete()` throw `PlotReservationIsAppendOnlyException` — new exception); `plot(): BelongsTo`, `order(): BelongsTo`; `static activeForOrder(Order $order): ?self` (state in held|confirmed, latest first); `static activeForPlot(GravePlot $plot): ?self`.
  - `ReservePlot::__invoke(GravePlot $plot, Order $order, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation`:
    1. `PlotReservation::activeForOrder($order)` non-null → return incumbent.
    2. `Audit::wrap` + `DB::transaction`: re-read plot `lockForUpdate()->findOrFail` → assert `plot_state === PlotState::AVAILABLE` else `PlotNotAvailableException::forPlot` → insert row (plot_id, order_id, state held, reserved_by_ref, reserved_at now, reason) → `$plot->update(['plot_state' => PlotState::RESERVED])` — wait, GravePlot has NO write guard (plain model) — fine → audit `PLOT_RESERVATION_CREATED` (subject plot_reservation, reason, correlationId) → `Outbox::record('plot_reservation.state_changed.v1', 1, 'plot_reservation', (string) $row->id, ['reservation_id' => ..., 'plot_id' => ..., 'from_state' => null, 'to_state' => 'held'], OutboxClassification::Internal, "plot_reservation:{$row->id}")`.
    3. (No duplicate-hold classifier — the partial unique index was removed; see Global Constraints. The plot-row lock + `plot_state` assert are the invariant.)
  - Migration: table in `2026_08_16_100020`; the index (created in that migration's `up()`) is dropped by the follow-up `2026_08_16_100030_drop_plot_reservations_active_hold_index.php` (`down()` recreates it for reversibility).

- [ ] **Step 1: Write the failing tests**

`ReservePlotTest.php` (happy path, idempotency, occupied refusal, audit/outbox):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservePlotTest extends TestCase
{
    use RefreshDatabase;

    private function plot(PlotState $state = PlotState::AVAILABLE): GravePlot
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => $state->value]);
    }

    private function order(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
    }

    public function test_reserves_an_available_plot(): void
    {
        $plot = $this->plot();
        $order = $this->order();

        $reservation = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey(), 'order_id' => $order->getKey(), 'state' => 'held']);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CREATED']);
        $this->assertDatabaseHas('outbox_events', ['event_name' => 'plot_reservation.state_changed.v1']);
    }

    public function test_order_idempotency_returns_incumbent(): void
    {
        $plot = $this->plot();
        $order = $this->order();
        $first = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $second = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PlotReservation::query()->count());
    }

    public function test_occupied_plot_is_refused(): void
    {
        $plot = $this->plot(PlotState::OCCUPIED);
        $order = $this->order();

        $this->expectException(PlotNotAvailableException::class);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');
    }
}
```

`ReservePlotTwoConnectionTest.php` — the PG two-connection race (RecordOrderStatusChangeTwoConnectionTest pattern):

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReservePlotTwoConnectionTest extends TestCase
{
    public function test_a_second_reservation_is_refused_after_the_first_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $outcomes = [];
        try {
            foreach (['pgsql', 'pgsql_race'] as $connectionName) {
                DB::setDefaultConnection($connectionName);
                try {
                    app(ReservePlot::class)(
                        GravePlot::query()->findOrFail($plot->getKey()),
                        Order::query()->findOrFail($order->getKey()),
                        'actor:system',
                        'system',
                    );
                    $outcomes[] = 'ok';
                } catch (PlotNotAvailableException) {
                    $outcomes[] = 'blocked';
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
        }

        $this->assertSame(['ok', 'blocked'], $outcomes);
        $this->assertSame(1, PlotReservation::query()->count());
    }
}
```

- [ ] **Step 2: Run to verify they fail** → FAIL (classes not found).
- [ ] **Step 3: Implement** per the Produces block. `PlotReservationIsAppendOnlyException` in `app/Domain/PlotReservation/Exceptions/`.
- [ ] **Step 4: Run the tests** → PASS (the two-connection test skips on SQLite). Verify the corrected one-active-hold mechanism on BOTH engines (the partial unique index was dropped — see Global Constraints): `test_reserved_plot_without_reservation_row_is_refused` (plot manually `reserved` with no reservation row → `PlotNotAvailableException` — the state-assert path) and `test_plot_can_be_reserved_again_after_expire` (held → expire → re-hold the SAME plot succeeds, old chain preserved append-only — the regression that was impossible with the index).
- [ ] **Step 5: Event catalog** — append the `plot_reservation.state_changed.v1` row to `docs/contracts/event-catalog.md` in the same style as the existing rows.
- [ ] **Step 6: Gates + commit** — `feat(plot-reservation): atomic plot reservation with concurrency backstop (P3 lane 2)`.

---

## Task 4: Reservation lifecycle actions (Lane 2)

**Files:**
- Create: `app/Domain/PlotReservation/Actions/ConfirmPlotReservation.php`
- Create: `app/Domain/PlotReservation/Actions/ReleasePlotReservation.php`
- Create: `app/Domain/PlotReservation/Actions/ExpirePlotReservation.php`
- Create: `app/Domain/PlotReservation/Exceptions/PlotReservationTransitionException.php`
- Test: `tests/Feature/Domain/PlotReservation/PlotReservationLifecycleTest.php`

**Interfaces:**
- Consumes: Task 3's model/state/audit actions/outbox; `PlotReservation::activeForOrder`.
- Produces (each `__invoke(PlotReservation $reservation, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation`):
  - `ConfirmPlotReservation` — `held`→`confirmed` (plot stays `reserved`); inserts a new CONFIRMED row; audit `PLOT_RESERVATION_CONFIRMED`; outbox (from held to confirmed).
  - `ReleasePlotReservation` — `held`/`confirmed`→`released`, plot → `available`; audit `PLOT_RESERVATION_RELEASED`; outbox.
  - `ExpirePlotReservation` — `held`→`expired`, plot → `available`; audit `PLOT_RESERVATION_EXPIRED`; outbox.
  - `PlotReservationTransitionException extends RuntimeException` with `static forTransition(string $from, string $to): self` — thrown on late/terminal transitions (confirmed→expired refused, released/expired terminal).

- [ ] **Step 1: Write the failing lifecycle test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function held(): array
    {
        $cemetery = Cemetery::factory()->create();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => PlotState::AVAILABLE->value]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);
        $reservation = app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        return [$plot, $order, $reservation];
    }

    public function test_confirm_keeps_plot_reserved(): void
    {
        [$plot, , $reservation] = $this->held();
        $confirmed = app(ConfirmPlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::CONFIRMED, $confirmed->state);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_CONFIRMED']);
    }

    public function test_release_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $released = app(ReleasePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::RELEASED, $released->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_RELEASED']);
    }

    public function test_expire_restores_availability(): void
    {
        [$plot, , $reservation] = $this->held();
        $expired = app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $this->assertSame(PlotReservationState::EXPIRED, $expired->state);
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PLOT_RESERVATION_EXPIRED']);
    }

    public function test_terminal_reservation_refuses_further_transitions(): void
    {
        [$plot, , $reservation] = $this->held();
        app(ExpirePlotReservation::class)($reservation, 'user:1', 'operator');
        $latest = PlotReservation::query()->where('plot_id', $plot->getKey())->latest()->first();
        $this->expectException(PlotReservationTransitionException::class);
        app(ConfirmPlotReservation::class)($latest, 'user:1', 'operator');
    }
}
```

- [ ] **Step 2: Run to verify it fails** → FAIL.
- [ ] **Step 3: Implement** per the Produces block (each action re-reads the reservation's latest row under `lockForUpdate` in its transaction, asserts the allowed from-state, inserts the new row, flips the plot state where applicable, audits + outbox; `PlotReservationTransitionException` otherwise).
- [ ] **Step 4: Run tests + gates + commit** — `feat(plot-reservation): confirm/release/expire lifecycle actions (P3 lane 2)`.

---

## Task 5: Booking integration — reservation actions on the order view (Lane 3)

**Files:**
- Modify: `app/Filament/Admin/Resources/BookingOrders/Pages/ViewBookingOrder.php` (header actions)
- Modify: `app/Filament/Admin/Resources/BookingOrders/Schemas/BookingOrderInfolist.php` (Reservasi section)
- Create: `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`
- Create: `app/Filament/Admin/Resources/BookingOrders/Actions/PlotReservationLifecycleActions.php` (the three lifecycle action factories)
- Test: `tests/Feature/Filament/BookingOrderReservationTest.php`

**Interfaces:**
- Consumes: Tasks 1–4 (GravePlot/PlotState/CemeteryBlock, ReservePlot + lifecycle, PlotReservationState); `Order` + `bookingDraft` (cemetery_id, cemetery_package_id); `MasterDataAdminAuthorizerContract`; `ReauthenticationGuard` NOT needed (reservation is not a money-adjacent action — operator role via `OrderTransitionAuthorizerContract`? NO — the reservation actions are NOT order-status transitions; gate them with the resource's `getAuthorizationResponse` + `->authorize(fn (): bool => BookingOrderResource::canAccess())` + role check in the closure — operator/restricted_admin/admin allowed, finance denied? DECISION: the reservation is an operational action — operator + restricted_admin + admin; finance excluded (not money-adjacent; finance's domain is money). Enforce in the closure: `in_array` check against [OPERATOR, RESTRICTED_ADMIN, ADMIN]).
- Produces: on `ViewBookingOrder`:
  - **'Reservasi Plot'** header action — visible when `order.status ∈ {DIVERIFIKASI, MENUNGGU_KETERSEDIAAN}` AND `PlotReservation::activeForOrder($order) === null` AND the draft's cemetery resolves; modal `Select` of available plots: `GravePlot::query()->whereIn('block_id', CemeteryBlock::query()->where('cemetery_id', $draft->cemetery_id)->pluck('id'))->where('plot_state', PlotState::AVAILABLE)->when($draft->cemetery_package_id, fn ($q) => $q->where('cemetery_package_id', $draft->cemetery_package_id))->get()` → options `"{$block->code} — {$plot->slot}"`; action closure: role check → `ReservePlot` → success notification + redirect-to-view-route (the P1 pattern) / honest error notification.
  - **'Konfirmasi Reservasi'** (visible when an active HELD reservation exists), **'Lepaskan Reservasi'** (HELD/CONFIRMED), **'Kedaluwarsakan Reservasi'** (HELD) — each routes to the lifecycle action, role-checked, notification + redirect.
  - Infolist 'Reservasi' section: the active reservation (plot slot/block/cemetery, reservation state, reserved_by, timestamps) via `PlotReservation::activeForOrder($record)`.

- [ ] **Step 1: Write the failing integration test**

`tests/Feature/Filament/BookingOrderReservationTest.php`: fixture (cemetery + block + plots + draft-linked order at DIVERIFIKASI — the P1 `AdminOperatorActionsTest` fixture shapes); assert the reserve action's option list (class-filtered when the draft has a package); invoke the reserve action → reservation row + plot state; lifecycle actions visible per state; finance role denied on the reserve action; operator + admin allowed.

- [ ] **Step 2: Run to verify it fails** → FAIL.
- [ ] **Step 3: Implement** per the Produces block (P1 `TransitionOrderAction` + `TransitionOrderAction::make` patterns for the actions; the infolist section per the P1 infolist pattern).
- [ ] **Step 4: Run test + gates + commit** — `feat(admin): operator plot reservation on the booking order view (P3 lane 3)`.

---

## Task 6: Docs + deploy + browser UAT + whole-branch review (post-merge)

**Files:**
- Modify: `docs/product/screen-inventory.md` (Blok Makam relation manager + Plot resource + the order-view reservation actions), `docs/domain/traceability-matrix.md` (P3 rows Covered with the real test files), `docs/contracts/event-catalog.md` (done in Task 3 — verify).
- Test: Playwright additions (dev).

- [ ] **Step 1: Update screen inventory + traceability** (in-place; commit `docs: screen inventory and traceability for P3 plot inventory + reservation`).
- [ ] **Step 2: Deploy to dev** (digest → compose update → migrate → health check).
- [ ] **Step 3: Browser UAT** (admin → cemetery → Blok Makam create + generate; plots list + state override; a live order at DIVERIFIKASI → Reservasi Plot → infolist shows it → Konfirmasi; public flow unchanged smoke).
- [ ] **Step 4: Whole-branch review** (full phase diff, ledger minors triage, bounded fix wave + scoped re-review) then final merge + deploy.

---

## Self-review notes

- **Spec coverage:** §4.1 → Tasks 1–2; §4.2 → Tasks 3–4 (+ event catalog); §4.3 → Task 5; §5/§6 → per-task; §7 → per-task tests + Task 6; §8 → Task 6.
- **Type consistency:** `PlotState`/`PlotReservationState` constants used consistently; `ReservePlot::__invoke` signature identical in Tasks 3, 4 (fixture) and 5 (integration); audit action names consistent across Tasks 1–4.
- **Known drift risks to resolve at implementation time:** `Cemetery::factory()` shape; `CemeteryPackage` fillable fields; whether `MetadataAllowlist` admits `capacity`/`plot_count` keys; the partial-unique classifier message strings on PG vs SQLite; Filament 5 Select/`callTableAction` API details; `BookingDraft`'s `cemetery_package_id` nullability on the integration path; whether `Order` at MENUNGGU_KETERSEDIAAN with a draft lacking `cemetery_id` must hide the reserve action (handle: visible only when the cemetery resolves).
