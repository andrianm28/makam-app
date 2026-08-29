# Booking-Flow Shortening (Phase F) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Once a customer's held plot has successfully converted onto an order's `plot_reservations` chain (Phase E), an operator can skip straight from `DIVERIFIKASI` to `PENAWARAN_TERKIRIM` with no manual availability-confirmation step — because the plot is already, verifiably, theirs.

**Architecture:** Widen `OrderTransition`'s pure adjacency list to make the edge reachable, add a new domain action (`IssueQuoteFromReservedPlot`) that re-asserts the qualifying condition at run time and then delegates to the existing `IssueOrderQuote`, and add a new, distinctly-visible header button on both order-view pages (`/admin` and `/operator`) — alongside, not replacing, the existing `request_availability` button.

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL 18, PHPUnit.

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` §"Booking-flow shortening (Phase F)" — this plan's own text resolves one significant thing that section's brief sketch did not spell out: simply widening `OrderTransition::ALLOWED['DIVERIFIKASI']` is NOT sufficient by itself, because both order-view pages auto-generate a generic transition button for EVERY entry in `OrderTransition::allowedFrom($order->status())`. Without a corresponding UI change, that auto-generation would create a SECOND, wrongly-permissive `PENAWARAN_TERKIRIM` button on a `DIVERIFIKASI` order — one that reuses the existing `IssueOrderQuote` action with NO reservation check at all, since `TransitionOrderAction`'s `TRANSITION_NAME` map is keyed by target status only, not by (from, to) pair. This plan's Task 2 exists specifically to close that gap; do not treat "widen the matrix" as the whole task.

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- No AWS references, no hardcoded design/color values.
- Every new PHP class needs real Feature or Unit test coverage run against real PostgreSQL 18 (never SQLite) via the pinned CI image (`ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3`). This phase's core logic has no new locking/concurrency surface (it reuses `PlotReservation::activeForOrder()`, an existing read), so SQLite is acceptable for the pure-logic tests, but at least one Feature-level test per task must run against real Postgres to catch any Postgres-specific behavior (uuid comparisons, etc.) — mirror this repo's own established discipline.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` must stay clean throughout.
- Composer/npm builds do not run on this host outside the pinned container — CI only.
- This phase adds a new reachable order-status edge and a new operator-facing action on real commercial orders — per `AGENTS.md` §Infrastructure-agent execution, human review is mandatory before merge. Flag this; it does not block writing/testing the code.
- The new action must reuse the EXISTING `'issue_quote'` authorization transition name in `OrderTransitionAuthorizerContract`/`OrderTransitionAuthorizer` — do NOT add a new transition name or touch the authorizer's matrix. The shortcut is authorization-equivalent to the normal quote-issuing transition (same actors may do it); only the precondition differs.
- `PlotReservation::activeForOrder($order)` already returns non-null for BOTH `held` and `confirmed` states (its `ACTIVE_STATES` list) — this already matches the roadmap's decision #6 ("a successfully-converted `HELD` reservation is sufficient, no operator `CONFIRMED` gate required"). Do not add a state-specific check on top of it; a plain non-null check is correct and complete.
- Both `/admin` (`ViewBookingOrder.php`) and `/operator` (`ViewCemeteryOrder.php`) order-view pages have IDENTICAL `getHeaderActions()` bodies today (verified by direct comparison) — this is pre-existing duplication, not something this plan introduces or should refactor away. Apply the same fix to both files, matching the codebase's own established pattern of parallel `/admin`/`/operator` implementations rather than introducing a new shared abstraction unprompted.

---

### Task 1: `IssueQuoteFromReservedPlot` domain action + widened transition matrix

**Files:**
- Modify: `app/Domain/OrderWorkflow/OrderTransition.php`
- Create: `app/Domain/OrderWorkflow/Actions/IssueQuoteFromReservedPlot.php`
- Test: `tests/Feature/OrderWorkflow/IssueQuoteFromReservedPlotTest.php`

**Interfaces:**
- Consumes: `IssueOrderQuote::__invoke(Order $order, CarbonInterface $expiresAt, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` (pre-existing, unchanged), `PlotReservation::activeForOrder(Order $order): ?PlotReservation` (pre-existing, unchanged), `OrderTransition::assertAllowed()` (pre-existing, unchanged — this task only adds one entry to its data).
- Produces: `IssueQuoteFromReservedPlot::__invoke(Order $order, CarbonInterface $expiresAt, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` — Task 2's new Filament Action calls this exact signature.

- [ ] **Step 1: Write the failing tests**

Read `app/Domain/OrderWorkflow/OrderTransition.php` and `app/Domain/OrderWorkflow/Actions/IssueOrderQuote.php` in full first (both are short — reproduced in this plan's own grounding, but re-read the live files, they may have shifted).

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Actions\IssueQuoteFromReservedPlot;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

final class IssueQuoteFromReservedPlotTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(string $trackingMode): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => $trackingMode,
        ]);
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    public function test_the_matrix_allows_diverifikasi_direct_to_penawaran_terkirim(): void
    {
        $this->assertTrue(OrderTransition::isAllowed(OrderStatus::DIVERIFIKASI, OrderStatus::PENAWARAN_TERKIRIM));
        // The normal path stays reachable too — this is a widening, not a replacement.
        $this->assertTrue(OrderTransition::isAllowed(OrderStatus::DIVERIFIKASI, OrderStatus::MENUNGGU_KETERSEDIAAN));
    }

    public function test_it_issues_a_quote_and_transitions_when_a_reservation_exists(): void
    {
        $service = $this->makePricedService();
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $event = app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM->value, $event->to_status);
        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->status());
        $quote = Quote::currentFor($order);
        $this->assertNotNull($quote);
        $this->assertCount(1, $quote->lines);
    }

    public function test_it_refuses_when_the_order_is_not_at_diverifikasi(): void
    {
        $service = $this->makePricedService();
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::MASUK, $draft);
        app(ReservePlot::class)($plot, $order, 'user:1', 'operator');

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_it_refuses_when_there_is_no_active_reservation(): void
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_an_aggregate_tier_order_can_never_qualify(): void
    {
        // Structural, not a special case in the action: an aggregate-tier
        // cemetery never has GravePlot rows, so PlotReservation::activeForOrder()
        // is always null for such an order — verified directly here as an
        // explicit regression, not left to the structural argument alone.
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
        ]);
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);

        $this->assertNull(\App\Domain\PlotReservation\Models\PlotReservation::activeForOrder($order));

        $this->expectException(InvalidArgumentException::class);
        app(IssueQuoteFromReservedPlot::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (real Postgres — per `docs/operations/local-test-recipe.md`):
```
vendor/bin/phpunit tests/Feature/OrderWorkflow/IssueQuoteFromReservedPlotTest.php
```
Expected: FAIL — `OrderTransition::isAllowed(DIVERIFIKASI, PENAWARAN_TERKIRIM)` is currently `false`; `IssueQuoteFromReservedPlot` does not exist.

- [ ] **Step 3: Widen the transition matrix**

In `app/Domain/OrderWorkflow/OrderTransition.php`, change:
```php
        'DIVERIFIKASI' => ['MENUNGGU_KETERSEDIAAN', 'DITOLAK', 'DIBATALKAN'],
```
to:
```php
        'DIVERIFIKASI' => ['MENUNGGU_KETERSEDIAAN', 'PENAWARAN_TERKIRIM', 'DITOLAK', 'DIBATALKAN'],
```
Add one sentence to the class doc block's existing bullet list (matching its own style) noting the new edge and pointing at `IssueQuoteFromReservedPlot` as the only writer of it — e.g.: "`DIVERIFIKASI -> PENAWARAN_TERKIRIM` is a second edge added by the TPU/TPS operator dashboard roadmap's Phase F (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`): reachable only when a plot reservation already exists, enforced by `Actions\IssueQuoteFromReservedPlot`, not by this matrix — the matrix only makes the edge possible, per this class's own two-layer discipline."

- [ ] **Step 4: Write `IssueQuoteFromReservedPlot.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotReservation\Models\PlotReservation;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * TPU/TPS operator dashboard roadmap, Phase F
 * (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`) — the
 * `DIVERIFIKASI -> PENAWARAN_TERKIRIM` shortcut: once a customer's plot
 * hold has converted onto this order's own `plot_reservations` chain
 * (Phase E, `ConvertDraftHoldToOrderReservation`), or an operator has
 * reserved a plot directly (`ReservePlotAction`), the manual
 * `MENUNGGU_KETERSEDIAAN` confirmation step is redundant — the plot is
 * already, verifiably, theirs.
 *
 * Deliberately a thin precondition wrapper around the EXISTING
 * `IssueOrderQuote`, not a reimplementation: quote composition, quote
 * issuance, and the actual `PENAWARAN_TERKIRIM` write all stay in exactly
 * one place. This class's only job is to refuse the shortcut when its
 * two preconditions are not both true, BEFORE `IssueOrderQuote` ever
 * runs — re-asserted here at call time, never trusted from the caller
 * (the same "the button was not rendered is not a security property"
 * discipline `ReservePlotAction`/`TransitionOrderAction` already follow;
 * this class is the domain-layer half of that pair, Task 2 of this plan
 * is the Filament-layer half).
 *
 * Precondition 1 — order status. `OrderTransition::ALLOWED['DIVERIFIKASI']`
 * now permits `PENAWARAN_TERKIRIM` (this plan's Task 1, same commit), but
 * that only makes the edge POSSIBLE — this class is what makes it
 * CONDITIONAL. An order anywhere else (already past DIVERIFIKASI, or not
 * yet verified) is refused here, before `IssueOrderQuote`'s own
 * `RecordOrderStatusChange` call would otherwise have to fail on its own
 * closed-set of allowed transitions.
 *
 * Precondition 2 — an active plot reservation.
 * `PlotReservation::activeForOrder($order)` returns non-null for BOTH
 * `held` and `confirmed` states (its `ACTIVE_STATES`) — matching the
 * roadmap's decision #6 verbatim ("a successfully-converted HELD
 * reservation is sufficient, no operator CONFIRMED gate required"). No
 * state-specific check is added here; a plain non-null read already
 * carries that decision. An aggregate-tier cemetery never has `GravePlot`
 * rows at all, so this is always null for such an order — the shortcut is
 * structurally unreachable for aggregate-tier orders, not specially
 * excluded.
 */
final readonly class IssueQuoteFromReservedPlot
{
    public function __construct(private IssueOrderQuote $issueOrderQuote) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __invoke(
        Order $order,
        CarbonInterface $expiresAt,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        if ($order->status() !== OrderStatus::DIVERIFIKASI) {
            throw new InvalidArgumentException(
                'Order must be at DIVERIFIKASI to skip straight to a quote via a reserved plot; current status: '.$order->status()->value.'.'
            );
        }

        if (PlotReservation::activeForOrder($order) === null) {
            throw new InvalidArgumentException(
                'Order has no active plot reservation to skip the availability step with.'
            );
        }

        return ($this->issueOrderQuote)($order, $expiresAt, $actorRef, $actorRole, $reason, $metadata);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/IssueQuoteFromReservedPlotTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Run the full order-workflow regression suite**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/ tests/Feature/Filament/BookingOrderTransitionActionTest.php`
Expected: all green — confirms the widened matrix does not change any EXISTING transition's behavior (the normal `DIVERIFIKASI -> MENUNGGU_KETERSEDIAAN -> PENAWARAN_TERKIRIM` path, and every other edge, are unaffected — only a new edge was added, nothing removed or reordered).

- [ ] **Step 7: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add app/Domain/OrderWorkflow/OrderTransition.php \
        app/Domain/OrderWorkflow/Actions/IssueQuoteFromReservedPlot.php \
        tests/Feature/OrderWorkflow/IssueQuoteFromReservedPlotTest.php
git commit -m "feat(order-workflow): allow DIVERIFIKASI to skip straight to a quote when a plot is already reserved"
```

---

### Task 2: Wire the shortcut button into both order-view pages

**Files:**
- Create: `app/Filament/Admin/Resources/BookingOrders/Actions/IssueQuoteFromReservedPlotAction.php`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Pages/ViewBookingOrder.php`
- Modify: `app/Filament/Operator/Resources/CemeteryOrders/Pages/ViewCemeteryOrder.php`
- Test: `tests/Feature/Filament/IssueQuoteFromReservedPlotActionTest.php`

**Interfaces:**
- Consumes: `IssueQuoteFromReservedPlot` (Task 1), `PlotReservation::activeForOrder()` (pre-existing), `OrderTransitionAuthorizerContract` (pre-existing, reused with the existing `'issue_quote'` transition name — do not add a new one).
- Produces: nothing further downstream — final task of this plan.

Read `app/Filament/Admin/Resources/BookingOrders/Actions/ReservePlotAction.php`'s `make()` method in full first (the visible/authorize two-layer pattern to mirror), and `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php`'s `run()` method (the try/catch + notification + redirect shape to mirror). Both `ViewBookingOrder.php` and `ViewCemeteryOrder.php`'s `getHeaderActions()` bodies are currently IDENTICAL — re-confirm this with a direct diff before editing, since either file may have drifted since this plan was written.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Filament\Admin\Resources\BookingOrders\Pages\ViewBookingOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class IssueQuoteFromReservedPlotActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    private function makeDraft(): BookingDraft
    {
        $service = $this->makePricedService();

        return BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
    }

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    private function makeGranularCemeteryPlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    public function test_the_button_is_visible_when_a_reservation_exists_and_invoking_it_issues_a_quote(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('issue_quote_from_reserved_plot')
            ->callAction('issue_quote_from_reserved_plot');

        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->fresh()->status());
        $this->assertNotNull(Quote::currentFor($order->fresh()));
    }

    public function test_the_button_is_not_visible_without_a_reservation(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $this->makeDraft());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }

    public function test_the_button_is_not_visible_at_the_wrong_status_even_with_a_reservation(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        // MASUK cannot legally hold a reservation via ReservePlotAction's own
        // visible() gate in real UI use, but the domain action has no such
        // restriction — construct this state directly to prove the BUTTON's
        // own visible() gate checks status independently of the reservation.
        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::MASUK, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }

    public function test_the_normal_request_availability_button_still_renders_alongside_it(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        // Both buttons visible at once — the shortcut is additive, not a
        // replacement (roadmap: "appears alongside, not replacing").
        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionVisible('issue_quote_from_reserved_plot')
            ->assertActionVisible('transition_MENUNGGU_KETERSEDIAAN');
    }

    public function test_the_generic_auto_rendered_transition_button_never_appears_for_this_edge(): void
    {
        // Regression for the exact bug this task's own plan text warns
        // about: DIVERIFIKASI's widened allowedFrom() list now includes
        // PENAWARAN_TERKIRIM, but the GENERIC per-edge factory must never
        // render a button for that specific (from, to) pair — only the
        // dedicated action above may.
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $draft = $this->makeDraft();
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $draft);
        $plot = $this->makeGranularCemeteryPlot();
        app(ReservePlot::class)($plot, $order, (string) $user->getKey(), 'operator');

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('transition_PENAWARAN_TERKIRIM');
    }

    public function test_an_aggregate_tier_order_never_shows_the_button(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        // No GravePlot/ReservePlot call at all — aggregate-tier orders have
        // no plot inventory to reserve in the first place.
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI, $this->makeDraft());

        Livewire::test(ViewBookingOrder::class, ['record' => $order->getRouteKey()])
            ->assertActionHidden('issue_quote_from_reserved_plot');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Filament/IssueQuoteFromReservedPlotActionTest.php`
Expected: FAIL — the action `issue_quote_from_reserved_plot` does not exist yet; `transition_PENAWARAN_TERKIRIM` currently WOULD render for a `DIVERIFIKASI` order once Task 1's matrix widening is live (this is the exact bug this task fixes — confirm the failure is specifically this, not something else).

Note on `test_the_generic_auto_rendered_transition_button_never_appears_for_this_edge`: `assertActionHidden()` is expected to pass equally for an action that is registered but fails its own `visible()`/`authorize()` check, and for one this page's `getHeaderActions()` never added to the returned array at all (`ViewBookingOrder.php` already relies on the latter shape elsewhere — `confirm_plot_reservation`/`release_plot_reservation`/`expire_plot_reservation` are only ever added when a reservation exists, and existing tests already assert those hidden when absent). If `assertActionHidden` behaves differently for a wholly-unregistered action name in this Filament/Livewire version, the failure will be obvious and specific — adapt the assertion (e.g. inspect the rendered action list directly) rather than assuming the design is wrong.

- [ ] **Step 3: Write `IssueQuoteFromReservedPlotAction.php`**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Actions;

use App\Domain\OrderWorkflow\Actions\IssueQuoteFromReservedPlot;
use App\Domain\OrderWorkflow\Authorization\Contracts\OrderTransitionAuthorizerContract;
use App\Domain\OrderWorkflow\Exceptions\OrderActionNotAuthorisedException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderResource;
use App\Filament\Support\OrderViewUrl;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Throwable;

/**
 * TPU/TPS operator dashboard roadmap, Phase F
 * (`docs/superpowers/plans/2026-08-29-booking-flow-shortening.md`, Task 2)
 * — the "Lanjutkan dengan Plot Tercadang" header action on both
 * `ViewBookingOrder` (`/admin`) and `ViewCemeteryOrder` (`/operator`).
 * Renders ALONGSIDE the generic `transition_MENUNGGU_KETERSEDIAAN`
 * button, never replacing it — an order at `DIVERIFIKASI` with a
 * qualifying plot reservation gets BOTH options.
 *
 * ---------------------------------------------------------------------------
 * Two-layer enforcement, same shape as `ReservePlotAction`/
 * `TransitionOrderAction`
 * ---------------------------------------------------------------------------
 * `->visible()` is the RENDER gate, from ORDER state alone: status must
 * be `DIVERIFIKASI` AND `PlotReservation::activeForOrder()` must be
 * non-null. `->authorize()` is the ACTOR gate — the SAME
 * `OrderTransitionAuthorizerContract` check `TransitionOrderAction` uses
 * for the normal `issue_quote` edge (this shortcut is authorization-
 * equivalent to issuing a quote the normal way; only the precondition
 * differs, so it reuses the same transition NAME rather than inventing a
 * second one). `run()` re-checks BOTH gates as its first acts, because
 * "the button was not rendered" is not a security property, and because
 * `IssueQuoteFromReservedPlot` itself ALSO re-asserts its own two
 * preconditions independently — belt and braces across three layers
 * (Filament visible, Filament authorize, domain-action precondition), not
 * redundant: each closes a different bypass (a stale render, a direct
 * wire call with a spoofed record, a caller that skips this Filament
 * class entirely and calls the domain action directly).
 *
 * ---------------------------------------------------------------------------
 * Why this is NOT registered via `OrderTransition::allowedFrom()`'s
 * generic loop
 * ---------------------------------------------------------------------------
 * `TransitionOrderAction`'s `TRANSITION_NAME` map is keyed by TARGET
 * status only, not by (from, to) pair — so `allowedFrom(DIVERIFIKASI)`
 * now containing `PENAWARAN_TERKIRIM` (Task 1's matrix widening) would,
 * left alone, make the generic per-edge loop auto-render a
 * `transition_PENAWARAN_TERKIRIM` button that dispatches to
 * `IssueOrderQuote` UNCONDITIONALLY — no reservation check at all. Both
 * `ViewBookingOrder::getHeaderActions()` and
 * `ViewCemeteryOrder::getHeaderActions()` explicitly skip that one
 * (from, to) pair in their loop and call this class directly instead —
 * see those files' own edits in this task.
 */
final class IssueQuoteFromReservedPlotAction
{
    private const string TRANSITION_NAME = 'issue_quote';

    public static function make(Order $order): Action
    {
        return Action::make('issue_quote_from_reserved_plot')
            ->label('Lanjutkan dengan Plot Tercadang')
            ->icon(Heroicon::OutlinedForward)
            ->color('success')
            ->requiresConfirmation()
            ->modalHeading('Lanjutkan dengan plot tercadang')
            ->modalDescription('Pesanan akan langsung menuju status Penawaran Terkirim tanpa langkah konfirmasi ketersediaan manual, karena plot untuk pesanan ini sudah tercadang.')
            ->schema([Textarea::make('reason')->label('Catatan (opsional)')])
            ->visible(fn (): bool => self::qualifies($order))
            ->authorize(fn (): bool => self::authorized($order))
            ->action(fn (array $data) => self::run($order, $data['reason'] ?? null));
    }

    /**
     * The RENDER gate — order state only, no actor concept. Both
     * preconditions `IssueQuoteFromReservedPlot` itself re-asserts.
     */
    private static function qualifies(Order $order): bool
    {
        return $order->status() === OrderStatus::DIVERIFIKASI
            && PlotReservation::activeForOrder($order) !== null;
    }

    private static function authorized(Order $order): bool
    {
        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                app(ActorContext::class),
                self::TRANSITION_NAME,
                $order->bookingDraft?->cemetery_id,
            );
        } catch (OrderActionNotAuthorisedException) {
            return false;
        }

        return true;
    }

    private static function run(Order $order, ?string $reason): void
    {
        $actor = app(ActorContext::class);

        try {
            app(OrderTransitionAuthorizerContract::class)->authorizeTransition(
                $actor,
                self::TRANSITION_NAME,
                $order->bookingDraft?->cemetery_id,
            );
        } catch (OrderActionNotAuthorisedException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $actorRef = (string) $actor->identityReference;
        $actorRole = BookingOrderResource::auditRoleFor($actor);

        try {
            app(IssueQuoteFromReservedPlot::class)(
                $order,
                CarbonImmutable::now()->addDays(30),
                $actorRef,
                $actorRole,
                $reason,
            );

            Notification::make()->success()->title('Transisi berhasil dicatat.')->send();
            redirect()->to(OrderViewUrl::for($order));
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Transisi gagal')->body($exception->getMessage())->send();
        }
    }
}
```

- [ ] **Step 4: Wire it into `ViewBookingOrder.php`**

Change the existing loop:
```php
        foreach (OrderTransition::allowedFrom($record->status()) as $to) {
            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $record);
        }

        $actions[] = ReservePlotAction::make($record);
```
to:
```php
        foreach (OrderTransition::allowedFrom($record->status()) as $to) {
            // The DIVERIFIKASI -> PENAWARAN_TERKIRIM edge (Phase F) is
            // conditional on a plot reservation and is rendered by
            // IssueQuoteFromReservedPlotAction below instead — the generic
            // per-edge factory has no way to express that condition and
            // would otherwise dispatch to the WRONG action (IssueOrderQuote,
            // no reservation check). See that class's own doc block.
            if ($record->status() === OrderStatus::DIVERIFIKASI && $to === OrderStatus::PENAWARAN_TERKIRIM->value) {
                continue;
            }

            $actions[] = TransitionOrderAction::make(OrderStatus::from($to), $record);
        }

        $actions[] = IssueQuoteFromReservedPlotAction::make($record);
        $actions[] = ReservePlotAction::make($record);
```
Add the import: `use App\Filament\Admin\Resources\BookingOrders\Actions\IssueQuoteFromReservedPlotAction;`

- [ ] **Step 5: Wire it into `ViewCemeteryOrder.php`**

Read this file's current imports first (it is in the `App\Filament\Operator\...` namespace, so it imports `TransitionOrderAction`/`ReservePlotAction` from the `/admin` namespace — the SAME classes `/admin` uses, reused, not duplicated — confirm `IssueQuoteFromReservedPlotAction` should be imported the same way, from its actual `App\Filament\Admin\Resources\BookingOrders\Actions` namespace, matching this file's own existing import pattern for its sibling actions). Apply the identical change as Step 4.

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Filament/IssueQuoteFromReservedPlotActionTest.php`
Expected: PASS (6 tests).

- [ ] **Step 7: Duplicate the test's operator-panel coverage**

Add ONE test to the same file (or a sibling file matching this repo's existing `/operator` test-file convention — check `tests/Feature/Filament/Operator/` for the naming pattern already established by Phase C/D/E and match it) proving the button also works from `ViewCemeteryOrder` for a `cemetery_operator` actor granted the order's cemetery — mirror `test_the_button_is_visible_when_a_reservation_exists_and_invoking_it_issues_a_quote` but through `Livewire::test(ViewCemeteryOrder::class, ...)` with a `cemetery_operator` role + a real `ScopeAssignment` grant for the order's cemetery (check `ReservePlotActionCemeteryOperatorTest.php` for this repo's exact established fixture pattern for granting a cemetery_operator a cemetery scope, and mirror it exactly rather than inventing a new one).

- [ ] **Step 8: Run the full regression suite for both panels**

Run:
```
vendor/bin/phpunit tests/Feature/Filament/ tests/Feature/OrderWorkflow/
```
Expected: all green — no other Filament action or order-workflow test regresses.

- [ ] **Step 9: `pint` + `phpstan` + `ci/verify-docs.sh` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
bash ci/verify-docs.sh
git add app/Filament/Admin/Resources/BookingOrders/Actions/IssueQuoteFromReservedPlotAction.php \
        app/Filament/Admin/Resources/BookingOrders/Pages/ViewBookingOrder.php \
        app/Filament/Operator/Resources/CemeteryOrders/Pages/ViewCemeteryOrder.php \
        tests/Feature/Filament/IssueQuoteFromReservedPlotActionTest.php
git commit -m "feat(order-workflow): add the plot-reserved quote shortcut button to both order-view panels"
```

---

## Verification (Phase F, matching the roadmap's own Verification section)

- An aggregate-tier order can never see or invoke the new shortcut button — explicit regression tests in both tasks (`test_an_aggregate_tier_order_can_never_qualify` domain-level, `test_an_aggregate_tier_order_never_shows_the_button` UI-level), not just relying on the structural argument.
- A granular-tier order with a converted `HELD` reservation successfully skips to `PENAWARAN_TERKIRIM` with no operator confirmation of availability required (per decision #6) — `test_it_issues_a_quote_and_transitions_when_a_reservation_exists` / `test_the_button_is_visible_when_a_reservation_exists_and_invoking_it_issues_a_quote`.
- The generic auto-rendered transition button never appears for the `DIVERIFIKASI -> PENAWARAN_TERKIRIM` edge specifically — `test_the_generic_auto_rendered_transition_button_never_appears_for_this_edge`, the regression this plan's own grounding work exists to prevent.
- The normal `request_availability` path is completely unaffected — every existing `OrderTransition`/`TransitionOrderAction` test in the full regression sweep (Task 1 Step 6, Task 2 Step 8) stays green.
