# Customer-Facing Plot Picker + Draft-Scoped Hold (Phase E) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a customer pick and hold a specific grave plot in Step 2 of the public booking wizard, for granular-tier cemeteries, before an `Order` exists — closing the gap that today forces every plot assignment through an operator phone call after submission.

**Architecture:** Extend the existing append-only `plot_reservations` chain with a `booking_draft_id` anchor and an `expires_at` TTL, so a hold can exist against a `BookingDraft` the same way one exists against an `Order` today. A new `HoldPlotForDraft` action creates it with the exact lock discipline `ReservePlot` already uses; a new `ConvertDraftHoldToOrderReservation` action re-validates and re-anchors it onto the real `Order` inside `SubmitBookingDraft`'s existing transaction; a new scheduled sweep expires stale holds a customer abandoned; and Step 2 of the wizard grows an inline plot picker, visible only for granular-tier cemeteries, reusing the same `StatusIntent`-driven status vocabulary Phase D's operator Floor/Block Map already established — through the public site's own `<x-mk.*>` component library, not Filament's.

**Tech Stack:** Laravel 13, Livewire (public booking wizard), PostgreSQL 18 (row locking is load-bearing), Eloquent, PHPUnit.

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` §"Customer-facing plot picker + draft-scoped hold (Phase E)" — this plan's own text resolves every place that document's citations had drifted against the current code (Phases A-D landed since it was written); each resolution is called out inline where it matters.

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- Real PostgreSQL 18 (never SQLite) for every task touching the new locking/concurrency logic (Tasks 1, 2, 3, 4) — `lockForUpdate()` is a no-op on SQLite (no row-level locking), so only PostgreSQL exercises the real serialization. Use the pinned CI image `ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3` per `docs/operations/local-test-recipe.md`.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` must stay clean throughout.
- No hardcoded design values — public-facing Blade (Task 5) goes through `<x-mk.*>` components and `resources/css/tokens.css`-backed intent classes exactly the way `wizard.blade.php`'s existing Step 2 section already does; no arbitrary Tailwind values.
- The draft-hold TTL (15 minutes) is a config value (`config/plot-reservation.php`), never a literal at any call site — Task 2 establishes this.
- Task 4 (the scheduler) is a cron-driven sweep of reservation state — per `AGENTS.md` §Infrastructure-agent execution, human review is mandatory before merge (does not block writing/testing the code).
- Every write path that mutates `plot_reservations` continues to record its own `Audit::record()`/`Outbox::record()` call inside the same `DB::transaction()` — the pattern every existing action in this module already follows. No new event name is invented; every emission reuses the single catalogued `plot_reservation.state_changed.v1` event.
- Public-site (Livewire, non-Filament) actor audit convention, confirmed from `SubmitBookingDraft`, `Marketplace\Checkout`, `Marketplace\OrderTracking`, `Visitation\VisitationPage`: `actorRole: 'customer'`, `actorRef: "booking_draft:{$draft->getKey()}"`, `AuditSource::Api` (not `Panel` — that is Filament-only).

## Resolutions against the roadmap doc (read before starting — these are deliberate deviations, not omissions)

1. ~~**`booking_draft_id` FK uses `restrictOnDelete()`, not `cascadeOnDelete()`** as the roadmap doc suggested. `plot_reservations.order_id` already uses `restrictOnDelete()` "so a cascade would silently erase the append-only trail" (the migration's own words); `booking_drafts` has no delete/purge path in this codebase today (confirmed: no `BookingDraft::destroy`/`forceDelete` call exists anywhere), so there is no operational need for cascade, and restrict is the evidence-preserving choice consistent with the rest of this table.~~

   **SUPERSEDED (final whole-branch review, finding C1) — the shipped FK is `nullOnDelete()`.** The premise struck through above is factually wrong: `App\Domain\Booking\Actions\PurgeStaleBookingDrafts` does a bulk `->delete()` on stale drafts nightly (`routes/console.php`, 30-day retention from `config/booking.php`). Because `plot_reservations` is append-only and its rows are never deleted, a RESTRICT could never be satisfied, so the first purged draft that had ever held a plot would abort the entire single-transaction nightly sweep, permanently, leaving customer/deceased PII in place against the retention policy. `nullOnDelete()` severs only the join link; the reservation rows survive untouched and `reserved_by_ref` still carries `"booking_draft:{id}"` textually. `order_id` keeps `restrictOnDelete()` — `orders` has no purge path and is a commercial record. The shipped migration's own doc block carries this reconciliation.
2. **No new DB CHECK constraint or model guard for "exactly one of `order_id`/`booking_draft_id`."** Grepped this codebase's own precedent for "exactly one of two" (`orders.funeral_case_id`/`pre_need_case_id`, `quote_lines`' package/service line split): in both cases the invariant is enforced purely by **construction discipline** — the one action that creates each family of row only ever sets one side, documented in prose, with no runtime guard. `plot_reservations` follows the identical shape here: `HoldPlotForDraft` sets `booking_draft_id` and leaves `order_id` null; `ReservePlot`/`ConvertDraftHoldToOrderReservation` set `order_id` and leave `booking_draft_id` null. Do not add a heavier mechanism than this repo's own established pattern.
3. **`ExpirePlotReservation`, `ConfirmPlotReservation`, and `ReleasePlotReservation` (all three pre-existing, unmodified-by-Phase-D actions) must be extended to carry `booking_draft_id` forward**, the same way they already carry `order_id` forward on every appended row (`'order_id' => $current->order_id`). This is NOT in the roadmap doc at all — it surfaced only by reading `ExpirePlotReservation.php` directly: without this change, the terminal `expired` row of an abandoned draft hold would silently drop its `booking_draft_id`, breaking the append-only chain's own traceability contract the moment Task 4's scheduler calls it. This is Task 1's job, not Task 4's — the column's "carried forward through every transition" contract belongs with the column's introduction.
4. **The picker is NOT a new numbered wizard step.** `BookingWizardStep` is a fixed nine-step vocabulary ("this class does not smooth over or translate source copy," "the stepper renders all nine labels") sourced from `docs/product/booking-wizard-fields.md`'s own step headings — inserting a tenth step would renumber the whole external contract. The picker is new UI **inside** Step 2 (`BookingWizardStep::CEMETERY`), gating the existing `saveStep2()` call rather than replacing it — see Task 5.
5. **Known unresolved cross-phase interaction, explicitly NOT fixed by this plan:** Phase D's operator Floor/Block Map (`BasePlotFloorMapPage`) offers `ConfirmPlotReservation`/`ReleasePlotReservation` on any plot whose `plot_state` is `reserved`, regardless of whether the underlying hold belongs to an order or a customer's draft — `plot_state` does not distinguish holder type. Releasing a customer's active draft hold from the operator dashboard is correct, intended override behavior. Confirming one produces a `confirmed` row with `order_id === null`, which violates no invariant this codebase enforces (nothing requires `order_id` non-null for `confirmed`) but is semantically odd — a "confirmed" reservation with no order. This plan does not touch `BasePlotFloorMapPage` to close this gap; it is the same class of pre-existing, cross-cutting scope boundary Phase D's own final review documented for I-1/I-3, and belongs in a later hardening pass, not this one. Task 5's own tests do not exercise this path.

---

### Task 1: Extend `plot_reservations` with a draft-scoped hold anchor

**Files:**
- Create: `database/migrations/2026_08_29_100000_add_booking_draft_hold_to_plot_reservations_table.php`
- Modify: `app/Domain/PlotReservation/Models/PlotReservation.php`
- Modify: `app/Domain/PlotReservation/Actions/ConfirmPlotReservation.php`
- Modify: `app/Domain/PlotReservation/Actions/ReleasePlotReservation.php`
- Modify: `app/Domain/PlotReservation/Actions/ExpirePlotReservation.php`
- Test: `tests/Feature/Domain/PlotReservation/PlotReservationBookingDraftHoldTest.php`

**Interfaces:**
- Consumes: nothing new — pure schema/model extension of the existing `PlotReservation` model (`app/Domain/PlotReservation/Models/PlotReservation.php`).
- Produces: `PlotReservation::activeForDraft(BookingDraft $draft): ?self` (Task 2 consumes this), `PlotReservation.booking_draft_id`/`expires_at` columns and matching `$fillable`/casts entries (Task 2 and Task 3 consume these), and the "booking_draft_id carries forward through every lifecycle transition" contract (Task 4's scheduler relies on this).

- [ ] **Step 1: Write the failing migration test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\AuditSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationBookingDraftHoldTest extends TestCase
{
    use RefreshDatabase;

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    public function test_a_held_row_can_carry_a_booking_draft_id_with_no_order_id(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'order_id' => null,
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $this->assertSame($draft->getKey(), $row->booking_draft_id);
        $this->assertNull($row->order_id);
        $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $row->expires_at);
    }

    public function test_active_for_draft_returns_the_head_row_when_held(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $this->assertNull(PlotReservation::activeForDraft($draft));

        $row = PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);

        $incumbent = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($incumbent);
        $this->assertSame($row->getKey(), $incumbent->getKey());
    }

    public function test_active_for_draft_is_null_once_the_chain_is_released(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        PlotReservation::query()->create([
            'plot_id' => $plot->getKey(),
            'booking_draft_id' => $draft->getKey(),
            'state' => PlotReservationState::HELD,
            'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(15),
        ]);
        $held = PlotReservation::activeForDraft($draft);

        (new ReleasePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job);

        $this->assertNull(PlotReservation::activeForDraft($draft));
    }

    public function test_expire_confirm_and_release_all_carry_booking_draft_id_forward(): void
    {
        foreach ([
            'expire' => fn (PlotReservation $held) => (new ExpirePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
            'confirm' => fn (PlotReservation $held) => (new ConfirmPlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
            'release' => fn (PlotReservation $held) => (new ReleasePlotReservation)($held, 'system', 'system', auditSource: AuditSource::Job),
        ] as $label => $transition) {
            $plot = $this->makePlot();
            $draft = BookingDraft::query()->create(['current_step' => 2]);

            $held = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'booking_draft_id' => $draft->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => "booking_draft:{$draft->getKey()}",
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes(15),
            ]);

            $result = $transition($held);

            $this->assertSame($draft->getKey(), $result->booking_draft_id, "booking_draft_id was dropped by {$label}");
            $this->assertNull($result->order_id, "order_id should stay null for a draft-only chain after {$label}");
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (real Postgres, per the pinned image — see `docs/operations/local-test-recipe.md`):
```
vendor/bin/phpunit tests/Feature/Domain/PlotReservation/PlotReservationBookingDraftHoldTest.php
```
Expected: FAIL — `booking_draft_id` is not a fillable/known column, `activeForDraft` does not exist.

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 1) — extends `plot_reservations` so a hold can be anchored to a
 * `BookingDraft` (a customer's step-2 plot pick, before an `Order` exists)
 * the same way `order_id` already anchors an operator-initiated hold.
 *
 * SUPERSEDED — see Resolutions point 1. The shipped migration uses
 * `nullOnDelete()` and carries the full reconciliation in its own doc
 * block; the sketch below is left as the historical draft.
 *
 * No CHECK constraint enforcing "exactly one of order_id/booking_draft_id":
 * this codebase's own precedent for that shape
 * (`orders.funeral_case_id`/`pre_need_case_id`) is construction discipline
 * in the writing actions, not a database constraint — see this plan's
 * "Resolutions" section, point 2.
 *
 * `expires_at` is nullable because only draft-scoped `held` rows ever set
 * it — an operator-initiated (`order_id`-anchored) hold has no TTL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            $table->foreignUuid('booking_draft_id')->nullable()->after('order_id')->constrained('booking_drafts')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable()->after('expired_at');

            $table->index(['booking_draft_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_draft_id');
            $table->dropColumn('expires_at');
        });
    }
};
```

- [ ] **Step 4: Run the migration and confirm up/down are clean**

Run:
```
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=<db> -e DB_USERNAME=<user> -e DB_PASSWORD=<pass> \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php artisan migrate:fresh
```
Expected: no errors; `\d plot_reservations` (via `psql`) shows `booking_draft_id` and `expires_at`.

- [ ] **Step 5: Modify `PlotReservation.php` — add columns, relation, `activeForDraft()`**

Add to `$fillable` (after `'order_id'`):
```php
'booking_draft_id',
```
Add to `$fillable` (after `'expired_at'`):
```php
'expires_at',
```
Add to `casts()`'s returned array (after `'expired_at' => 'immutable_datetime',`):
```php
'expires_at' => 'immutable_datetime',
```
Add import `use App\Domain\Booking\Models\BookingDraft;` and a new relation, placed after `order(): BelongsTo`:
```php
    public function bookingDraft(): BelongsTo
    {
        return $this->belongsTo(BookingDraft::class, 'booking_draft_id');
    }
```
Add a new static method after `activeForOrder()`, mirroring it exactly (same doc-block reasoning: latest-row-then-filter, `created_at DESC, id DESC` tiebreak):
```php
    /**
     * The incumbent hold for a booking draft — the draft-scoped mirror of
     * `activeForOrder()`. Same head-row-then-filter reasoning: a
     * superseded `held` row remains in the chain forever, so filtering by
     * state first would resurrect a row a later hop already superseded.
     *
     * @param  BookingDraft  $draft  read for its key only — never content.
     */
    public static function activeForDraft(BookingDraft $draft): ?self
    {
        return self::incumbentOf(
            self::query()
                ->where('booking_draft_id', $draft->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first()
        );
    }
```
Update the class doc block's `@property-read` list to add `@property-read BookingDraft|null $bookingDraft`.

- [ ] **Step 6: Modify the three lifecycle actions to carry `booking_draft_id` forward**

In `ExpirePlotReservation.php`, `ConfirmPlotReservation.php`, and `ReleasePlotReservation.php`: each has a `PlotReservation::query()->create([...])` call that already includes `'order_id' => $current->order_id,`. Add immediately after that line, in each of the three files:
```php
                'booking_draft_id' => $current->booking_draft_id,
```
Add one sentence to each file's class doc block (adapt to that file's own voice) noting the column is carried forward the same way `order_id` is, so an appended row never silently drops which draft it belongs to.

- [ ] **Step 7: Run test to verify it passes**

Run:
```
vendor/bin/phpunit tests/Feature/Domain/PlotReservation/PlotReservationBookingDraftHoldTest.php
```
Expected: PASS (4 tests).

- [ ] **Step 8: Run the full existing plot-reservation suite to confirm no regression**

Run:
```
vendor/bin/phpunit tests/Feature/Domain/PlotReservation/ tests/Feature/Filament/PlotFloorMap*.php tests/Feature/Filament/Operator/ tests/Unit/Support/Design/StatusIntentTest.php
```
Expected: all green — the three modified lifecycle actions must still pass every pre-existing test (order-anchored chains carry `booking_draft_id: null` forward, which changes nothing observable for them).

- [ ] **Step 9: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add database/migrations/2026_08_29_100000_add_booking_draft_hold_to_plot_reservations_table.php \
        app/Domain/PlotReservation/Models/PlotReservation.php \
        app/Domain/PlotReservation/Actions/ConfirmPlotReservation.php \
        app/Domain/PlotReservation/Actions/ReleasePlotReservation.php \
        app/Domain/PlotReservation/Actions/ExpirePlotReservation.php \
        tests/Feature/Domain/PlotReservation/PlotReservationBookingDraftHoldTest.php
git commit -m "feat(plot-reservation): extend plot_reservations with a booking-draft hold anchor"
```

---

### Task 2: `HoldPlotForDraft` action

**Files:**
- Create: `config/plot-reservation.php`
- Create: `app/Domain/PlotReservation/Actions/HoldPlotForDraft.php`
- Test: `tests/Feature/Domain/PlotReservation/HoldPlotForDraftTest.php`
- Test: `tests/Feature/Domain/PlotReservation/HoldPlotForDraftTwoConnectionTest.php`

**Interfaces:**
- Consumes: `PlotReservation::activeForDraft()` (Task 1), `GravePlot`, `BookingDraft`, `PlotState`, `PlotNotAvailableException` (all pre-existing).
- Produces: `HoldPlotForDraft::__invoke(GravePlot $plot, BookingDraft $draft, int|string $actorReference, ?int $ttlMinutes = null, ?string $reason = null, AuditSource $auditSource = AuditSource::Api): PlotReservation` — Task 3 and Task 5 both call this.

- [ ] **Step 1: Write the config file**

```php
<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Draft plot hold TTL
    |--------------------------------------------------------------------------
    |
    | How long a customer's step-2 plot pick (App\Domain\PlotReservation\
    | Actions\HoldPlotForDraft) reserves a specific grave plot before it is
    | swept back to available by the plot-reservation:expire-stale-draft-
    | holds scheduled command. A config value, not a literal, so it can
    | change without a deploy-and-decide cycle — see
    | docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md.
    |
    */
    'draft_hold_ttl_minutes' => (int) env('PLOT_DRAFT_HOLD_TTL_MINUTES', 15),
];
```

- [ ] **Step 2: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class HoldPlotForDraftTest extends TestCase
{
    use RefreshDatabase;

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    public function test_it_holds_an_available_plot_for_a_draft(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertSame(PlotReservationState::HELD, $row->state);
        $this->assertSame($draft->getKey(), $row->booking_draft_id);
        $this->assertNull($row->order_id);
        $this->assertNotNull($row->expires_at);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_the_default_ttl_comes_from_config(): void
    {
        config(['plot-reservation.draft_hold_ttl_minutes' => 7]);

        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertEqualsWithDelta(
            now()->addMinutes(7)->getTimestamp(),
            $row->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_an_explicit_ttl_overrides_config(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $row = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: 3);

        $this->assertEqualsWithDelta(
            now()->addMinutes(3)->getTimestamp(),
            $row->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_a_duplicate_hold_by_the_same_draft_returns_the_incumbent(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        $first = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $second = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, PlotReservation::query()->count());
    }

    public function test_it_refuses_a_plot_that_is_not_available(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $otherDraft = BookingDraft::query()->create(['current_step' => 2]);

        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $this->expectException(PlotNotAvailableException::class);
        (new HoldPlotForDraft)($plot->fresh(), $otherDraft, "booking_draft:{$otherDraft->getKey()}");
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/HoldPlotForDraftTest.php`
Expected: FAIL — class `HoldPlotForDraft` does not exist.

- [ ] **Step 4: Write `HoldPlotForDraft.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationAuditActions;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 2) — the draft-scoped mirror of `ReservePlot`: the same lock
 * discipline (order/draft-row lock first, then the plot-row lock, then the
 * availability assert against the LOCKED row), applied to a `BookingDraft`
 * instead of an `Order`, because a real `Order` does not exist until Step 8
 * (`SubmitBookingDraft`) but the picker needs to claim a plot at Step 2.
 *
 * Every structural decision below is inherited unchanged from `ReservePlot`
 * — see that class's own doc block for the full "why" (the rejected
 * partial-unique-index backstop, the plot-row-lock-is-the-serialization-
 * anchor reasoning, the append-only "one row per transition" shape,
 * finding I1's order-row-lock-first discipline). Only two things differ:
 *
 * 1. The lock/idempotency anchor is `booking_draft_id`, not `order_id` —
 *    but the DISCIPLINE is identical: `BookingDraft::query()->
 *    lockForUpdate()` is taken FIRST, inside the transaction, exactly the
 *    way `ReservePlot` locks `Order::query()` first (step 2a there). A
 *    `BookingDraft` row is just as lockable as an `Order` row — nothing
 *    about `SaveBookingDraftStep`'s own optimistic-version check on the
 *    wizard's save path stops this action from also taking a real row
 *    lock here. Without it, finding I1's exact race reappears one layer
 *    down: a customer double-clicking two DIFFERENT plot cells in the
 *    same draft could have both calls pass the outside-the-transaction
 *    incumbent pre-check before either commits, each lock a DIFFERENT
 *    plot row (no contention between them), and both commit — the same
 *    draft ending up with two simultaneous active holds, which must be
 *    impossible for exactly the reason it must be impossible for an
 *    order. Locking the draft row first serializes that race the same
 *    way locking the order row does for `ReservePlot`.
 * 2. A TTL: `expires_at` is set from `$ttlMinutes` (explicit) or
 *    `config('plot-reservation.draft_hold_ttl_minutes')` (default) — an
 *    order-anchored hold never expires on a timer, a draft-anchored one
 *    always does, because the customer may simply abandon the tab.
 */
final readonly class HoldPlotForDraft
{
    public function __invoke(
        GravePlot $plot,
        BookingDraft $draft,
        int|string $actorReference,
        ?int $ttlMinutes = null,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Api,
    ): PlotReservation {
        // Step 1 — outside the transaction: a duplicate attempt by the same
        // draft (a double-tap, or a wizard resume that re-renders the
        // picker) costs one SELECT and returns the incumbent. See
        // `ReservePlot`'s class doc block for why this is a courtesy fast
        // path, not the correctness mechanism.
        $incumbent = PlotReservation::activeForDraft($draft);

        if ($incumbent instanceof PlotReservation) {
            return $incumbent;
        }

        $ttl = $ttlMinutes ?? (int) config('plot-reservation.draft_hold_ttl_minutes');

        return DB::transaction(function () use (
            $plot,
            $draft,
            $actorReference,
            $auditSource,
            $reason,
            $ttl,
        ): PlotReservation {
            // Step 2a — the DRAFT-row lock first, then the authoritative
            // incumbent re-check (mirrors `ReservePlot`'s finding-I1 fix
            // exactly, see class doc block point 1): serializes two
            // concurrent holds by the SAME draft against two DIFFERENT
            // plots before either reaches a plot row.
            $lockedDraft = BookingDraft::query()->lockForUpdate()->findOrFail($draft->getKey());

            $incumbent = PlotReservation::activeForDraft($lockedDraft);

            if ($incumbent instanceof PlotReservation) {
                return $incumbent;
            }

            // Step 2b — the plot-row lock: the shared mutable anchor,
            // exactly as in `ReservePlot`.
            $current = GravePlot::query()->lockForUpdate()->findOrFail($plot->getKey());

            if ($current->plot_state !== PlotState::AVAILABLE) {
                throw PlotNotAvailableException::forPlot((string) $current->getKey());
            }

            $row = PlotReservation::query()->create([
                'plot_id' => $current->getKey(),
                'booking_draft_id' => $lockedDraft->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => (string) $actorReference,
                'reserved_at' => now(),
                'expires_at' => now()->addMinutes($ttl),
                'reason' => $reason,
            ]);

            $current->update(['plot_state' => PlotState::RESERVED]);

            if ($plot !== $current) {
                $plot->setRawAttributes($current->getAttributes(), true);
            }

            $this->emitStateChanged($row, (string) $current->getKey());

            Audit::record(
                action: PlotReservationAuditActions::PLOT_RESERVATION_CREATED,
                subject: new AuditSubject('plot_reservation', $row->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: 'customer',
                source: $auditSource,
                reason: $reason,
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $row;
        });
    }

    private function emitStateChanged(PlotReservation $row, string $plotId): void
    {
        Outbox::record(
            eventName: 'plot_reservation.state_changed.v1',
            eventVersion: 1,
            aggregateType: 'plot_reservation',
            aggregateId: (string) $row->getKey(),
            data: [
                'reservation_id' => (string) $row->getKey(),
                'plot_id' => $plotId,
                'from_state' => null,
                'to_state' => PlotReservationState::HELD,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/HoldPlotForDraftTest.php`
Expected: PASS (5 tests).

- [ ] **Step 6: Write the two-connection concurrency test**

Mirror `tests/Feature/Domain/PlotReservation/ReservePlotTwoConnectionTest.php` exactly (read it first), substituting `HoldPlotForDraft` + two `BookingDraft` rows for `ReservePlot` + two `Order` rows:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The draft-hold mirror of `ReservePlotTwoConnectionTest` (read that
 * class's doc block first — same reasoning applies verbatim, substituting
 * `BookingDraft` for `Order`). Outside `RefreshDatabase`'s outer
 * transaction so the first session's commit is genuinely visible to the
 * second; the trailing `migrate:fresh` after EACH test is load-bearing for
 * the same reason.
 *
 * Two distinct races, both closed by `HoldPlotForDraft`'s draft-row lock
 * (step 2a, mirroring `ReservePlot`'s finding I1):
 * `test_a_second_hold_is_refused_after_the_first_commits` proves the
 * PLOT-row lock refuses a second draft claiming an already-held plot;
 * `test_the_same_draft_cannot_hold_two_different_plots_concurrently`
 * proves the DRAFT-row lock refuses the same draft claiming two DIFFERENT
 * plots at once — the two locks guard two different invariants and each
 * needs its own race test, the same way `ReservePlot`'s own class doc
 * block explains its plot-level test cannot also prove the order-level
 * guarantee.
 */
final class HoldPlotForDraftTwoConnectionTest extends TestCase
{
    public function test_a_second_hold_is_refused_after_the_first_commits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $secondDraft = BookingDraft::query()->create(['current_step' => 2]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $outcomes = [];
        $drafts = [$draft, $secondDraft];
        try {
            foreach (['pgsql', 'pgsql_race'] as $i => $connectionName) {
                DB::setDefaultConnection($connectionName);
                try {
                    app(HoldPlotForDraft::class)(
                        GravePlot::query()->findOrFail($plot->getKey()),
                        BookingDraft::query()->findOrFail($drafts[$i]->getKey()),
                        "booking_draft:{$drafts[$i]->getKey()}",
                    );
                    $outcomes[] = 'ok';
                } catch (PlotNotAvailableException) {
                    $outcomes[] = 'blocked';
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        $this->assertSame(['ok', 'blocked'], $outcomes);
        $this->assertSame(1, PlotReservation::query()->count());

        Artisan::call('migrate:fresh');
    }

    /**
     * The draft-row-lock race (step 2a): the SAME draft racing itself
     * against two DIFFERENT plots. Unlike the plot-collision test above,
     * neither call can throw `PlotNotAvailableException` — each locks a
     * DIFFERENT plot row, so plot-level availability is never contended.
     * The draft-row lock is what must refuse the loser: the second
     * session's incumbent re-check, run under the now-locked draft row,
     * must find the first session's already-committed hold and return
     * THAT hold rather than creating a second one. Proof is therefore
     * "exactly one reservation row exists, and both calls return the same
     * id" — not a caught exception.
     */
    public function test_the_same_draft_cannot_hold_two_different_plots_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 2]);
        $firstPlot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $secondPlot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '002', 'plot_state' => 'available']);
        $draft = BookingDraft::query()->create(['current_step' => 2]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $reservationIds = [];
        $plots = [$firstPlot, $secondPlot];
        try {
            foreach (['pgsql', 'pgsql_race'] as $i => $connectionName) {
                DB::setDefaultConnection($connectionName);
                $reservation = app(HoldPlotForDraft::class)(
                    GravePlot::query()->findOrFail($plots[$i]->getKey()),
                    BookingDraft::query()->findOrFail($draft->getKey()),
                    "booking_draft:{$draft->getKey()}",
                );
                $reservationIds[] = (string) $reservation->getKey();
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        $this->assertSame($reservationIds[0], $reservationIds[1], 'the second session must return the incumbent, not create a second hold');
        $this->assertSame(1, PlotReservation::query()->count());

        Artisan::call('migrate:fresh');
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run (real Postgres — both tests self-skip on SQLite):
```
vendor/bin/phpunit tests/Feature/Domain/PlotReservation/HoldPlotForDraftTwoConnectionTest.php
```
Expected: PASS (2 tests, neither skipped, when run against the real Postgres connection).

- [ ] **Step 8: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add config/plot-reservation.php \
        app/Domain/PlotReservation/Actions/HoldPlotForDraft.php \
        tests/Feature/Domain/PlotReservation/HoldPlotForDraftTest.php \
        tests/Feature/Domain/PlotReservation/HoldPlotForDraftTwoConnectionTest.php
git commit -m "feat(plot-reservation): add HoldPlotForDraft — draft-scoped plot holds"
```

---

### Task 3: `PlotReservationState::CONVERTED` + `ConvertDraftHoldToOrderReservation` + `SubmitBookingDraft` wiring

**Files:**
- Modify: `app/Domain/PlotReservation/PlotReservationState.php`
- Create: `app/Domain/PlotReservation/Exceptions/DraftPlotHoldNoLongerValidException.php`
- Create: `app/Domain/PlotReservation/Actions/ConvertDraftHoldToOrderReservation.php`
- Modify: `app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php`
- Test: `tests/Feature/Domain/PlotReservation/ConvertDraftHoldToOrderReservationTest.php`
- Test: `tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php`

**Interfaces:**
- Consumes: `PlotReservation::activeForDraft()` (Task 1), `HoldPlotForDraft` (Task 2, used only by tests to set up fixtures).
- Produces: `ConvertDraftHoldToOrderReservation::__invoke(PlotReservation $draftHold, Order $order): PlotReservation`; `DraftPlotHoldNoLongerValidException` (Task 5 catches this in the wizard to route back to re-pick); `PlotReservationState::CONVERTED`.

- [ ] **Step 1: Write the failing test for the state constant and the conversion action**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ConvertDraftHoldToOrderReservation;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ConvertDraftHoldToOrderReservationTest extends TestCase
{
    use RefreshDatabase;

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }

    public function test_converted_is_a_known_state(): void
    {
        $this->assertTrue(PlotReservationState::isKnown(PlotReservationState::CONVERTED));
        $this->assertSame('converted', PlotReservationState::CONVERTED);
    }

    public function test_it_appends_a_new_order_anchored_row_and_closes_the_draft_hold(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $order = $this->makeOrder();

        $new = (new ConvertDraftHoldToOrderReservation)($held, $order);

        $this->assertSame(PlotReservationState::HELD, $new->state);
        $this->assertSame($order->getKey(), $new->order_id);
        $this->assertNull($new->booking_draft_id);

        $closed = PlotReservation::query()->findOrFail($held->getKey());
        $this->assertSame(PlotReservationState::HELD, $closed->state, 'the original row is append-only and unchanged');

        $latestForPlot = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
        $this->assertCount(3, $latestForPlot, 'the draft hold row, its converted-closing row, and the new order row');

        $states = $latestForPlot->pluck('state')->all();
        $this->assertContains(PlotReservationState::CONVERTED, $states);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_it_throws_when_the_hold_has_expired(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);
        $order = $this->makeOrder();

        $this->expectException(DraftPlotHoldNoLongerValidException::class);
        (new ConvertDraftHoldToOrderReservation)($held, $order);
    }

    public function test_it_throws_when_the_hold_was_already_converted(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        $order = $this->makeOrder();

        (new ConvertDraftHoldToOrderReservation)($held, $order);

        $this->expectException(DraftPlotHoldNoLongerValidException::class);
        (new ConvertDraftHoldToOrderReservation)($held, $this->makeOrder());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/ConvertDraftHoldToOrderReservationTest.php`
Expected: FAIL — `PlotReservationState::CONVERTED` and `ConvertDraftHoldToOrderReservation` do not exist.

- [ ] **Step 3: Add `CONVERTED` to `PlotReservationState`**

In `app/Domain/PlotReservation/PlotReservationState.php`, add the constant after `EXPIRED`:
```php
    /**
     * The draft hold's own chain ends here when
     * `Actions\ConvertDraftHoldToOrderReservation` succeeds — the plot's
     * claim moves to a NEW order-anchored row (still `held`), and this row
     * is what closes the draft-scoped chain. NOT an active state: the
     * draft hold no longer holds the plot in its own right once converted
     * — the new order-anchored row does.
     */
    public const string CONVERTED = 'converted';
```
Add it to `KNOWN_STATES` (after `self::EXPIRED,`):
```php
        self::CONVERTED,
```
Do **not** add it to `ACTIVE_STATES` — a converted draft-hold row no longer holds the plot; the newly-appended order-anchored row does (and that row's own state is `held`, already in `ACTIVE_STATES`).

- [ ] **Step 4: Run test, confirm the state-constant assertions now pass and the rest still fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/ConvertDraftHoldToOrderReservationTest.php`
Expected: `test_converted_is_a_known_state` passes; the rest fail (class not found).

- [ ] **Step 5: Write the exception**

```php
<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Exceptions;

use RuntimeException;

/**
 * Thrown by `Actions\ConvertDraftHoldToOrderReservation` when the draft
 * hold it was asked to convert is no longer a valid, live `held` row —
 * expired, already converted, or superseded by another transition since
 * the caller last read it.
 *
 * Per the roadmap's decision #7
 * (`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`): on this
 * failure, `SubmitBookingDraft` does NOT fall back to submitting without a
 * reservation. The whole submission transaction rolls back and the wizard
 * routes the customer back to Step 2 to re-pick a plot — see
 * `docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md` Task 5.
 */
final class DraftPlotHoldNoLongerValidException extends RuntimeException
{
    public static function forHold(string $reservationId, string $reason): self
    {
        return new self("Draft plot hold [{$reservationId}] is no longer valid: {$reason}.");
    }
}
```

- [ ] **Step 6: Write `ConvertDraftHoldToOrderReservation.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation\Actions;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationAuditActions;
use App\Domain\PlotReservation\PlotReservationState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 3) — re-anchors a customer's draft-scoped plot hold onto the real
 * `Order` `SubmitBookingDraft` just created, inside that same transaction.
 *
 * Called ONLY from `SubmitBookingDraft::submit()`, after the order row
 * exists (an order id is required to anchor the new row) and only when
 * `PlotReservation::activeForDraft($draft)` is non-null — a draft that
 * never went through the picker (aggregate-tier cemetery, or a plan
 * shipped before this feature) has no hold to convert, and this action is
 * never called for it.
 *
 * ---------------------------------------------------------------------------
 * Why a NEW row, not an update to the draft hold
 * ---------------------------------------------------------------------------
 * `PlotReservation` is append-only (`update()`/`delete()` throw — see the
 * model's class doc block). The draft hold's chain is closed with a
 * `converted` row (still referencing `booking_draft_id`, so the chain's own
 * history stays intact), and a SEPARATE, NEW row is appended anchored to
 * `order_id` instead, starting at `held` — the same state `ReservePlot`
 * would have produced had the operator claimed this plot directly. This is
 * why `TransitionOrderAction`'s later `PENAWARAN_TERKIRIM` shortcut (Phase
 * F) can treat "a converted `HELD` reservation" as sufficient per the
 * roadmap's decision #6: from the order's perspective, its `plot_reservations`
 * chain looks exactly like ANY order-anchored `held` reservation.
 *
 * ---------------------------------------------------------------------------
 * Lock discipline and the no-fallback failure mode
 * ---------------------------------------------------------------------------
 * The plot row is RE-LOCKED (not trusted from the caller's possibly-stale
 * `$draftHold->plot`), and the LATEST row of the PLOT's own chain (not the
 * draft's) is re-read under that lock — the same "re-derive from the
 * locked row, not the argument" discipline every other action in this
 * module follows. Conversion succeeds only if that latest row IS
 * `$draftHold` itself, still `held`, and its `expires_at` has not passed.
 * Any other outcome — already converted, expired, or (in a genuine race)
 * lost to a concurrent expiry sweep — throws
 * `DraftPlotHoldNoLongerValidException` and the WHOLE transaction rolls
 * back (per the roadmap's decision #7: no silent fallback to submitting
 * without a reservation; the wizard blocks and sends the customer back to
 * re-pick).
 */
final readonly class ConvertDraftHoldToOrderReservation
{
    public function __invoke(
        PlotReservation $draftHold,
        Order $order,
        AuditSource $auditSource = AuditSource::Api,
    ): PlotReservation {
        return DB::transaction(function () use ($draftHold, $order, $auditSource): PlotReservation {
            $plot = GravePlot::query()->lockForUpdate()->findOrFail($draftHold->plot_id);

            $current = PlotReservation::query()
                ->where('plot_id', $plot->getKey())
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();

            if (
                ! $current instanceof PlotReservation
                || $current->getKey() !== $draftHold->getKey()
                || $current->state !== PlotReservationState::HELD
            ) {
                throw DraftPlotHoldNoLongerValidException::forHold(
                    (string) $draftHold->getKey(),
                    'no longer the live head of this plot\'s reservation chain'
                );
            }

            if ($current->expires_at !== null && $current->expires_at->isPast()) {
                throw DraftPlotHoldNoLongerValidException::forHold((string) $draftHold->getKey(), 'hold has expired');
            }

            $actorRef = "booking_draft:{$current->booking_draft_id}";

            $closing = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'booking_draft_id' => $current->booking_draft_id,
                'order_id' => null,
                'state' => PlotReservationState::CONVERTED,
                'reserved_by_ref' => $current->reserved_by_ref,
                'reserved_at' => $current->reserved_at,
                'reason' => 'converted to order reservation on submission',
            ]);

            $reanchored = PlotReservation::query()->create([
                'plot_id' => $plot->getKey(),
                'order_id' => $order->getKey(),
                'state' => PlotReservationState::HELD,
                'reserved_by_ref' => $actorRef,
                'reserved_at' => now(),
            ]);

            // The plot was already `reserved` for the draft hold; it stays
            // `reserved` — the claim just moved to a new anchor. No plot
            // write needed here, unlike `ReservePlot`'s creation path.

            $this->emitStateChanged($closing, (string) $plot->getKey(), PlotReservationState::HELD, PlotReservationState::CONVERTED);
            $this->emitStateChanged($reanchored, (string) $plot->getKey(), null, PlotReservationState::HELD);

            Audit::record(
                action: PlotReservationAuditActions::PLOT_RESERVATION_CREATED,
                subject: new AuditSubject('plot_reservation', $reanchored->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorRef,
                actorRole: 'customer',
                source: $auditSource,
                reason: "converted from draft hold {$current->getKey()} on order {$order->getKey()}",
                correlationId: app(CorrelationContext::class)->current()?->value,
            );

            return $reanchored;
        });
    }

    private function emitStateChanged(PlotReservation $row, string $plotId, ?string $fromState, string $toState): void
    {
        Outbox::record(
            eventName: 'plot_reservation.state_changed.v1',
            eventVersion: 1,
            aggregateType: 'plot_reservation',
            aggregateId: (string) $row->getKey(),
            data: [
                'reservation_id' => (string) $row->getKey(),
                'plot_id' => $plotId,
                'from_state' => $fromState,
                'to_state' => $toState,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "plot_reservation:{$row->getKey()}",
        );
    }
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/ConvertDraftHoldToOrderReservationTest.php`
Expected: PASS (4 tests).

- [ ] **Step 8: Write the failing `SubmitBookingDraft` integration test**

Note before writing this: `SubmitBookingDraft::submit()` calls `OpenFuneralCase`, which reads several draft fields not otherwise exercised by Tasks 1-2 (`city_code` for `FuneralCase.service_area`, among others). The fixture below was checked against `OpenFuneralCase.php` and `FuneralCaseUrgency::fromBookingServiceType()`, but if this test throws from inside `OpenFuneralCase`/`FuneralCase::create()` rather than from the conversion logic under test, read the thrown exception and extend the `makeDraft()` fixture with whatever field it names — this is expected friction from testing across a domain boundary this plan does not otherwise touch, not a sign the test's premise is wrong.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class SubmitBookingDraftConvertsPlotHoldTest extends TestCase
{
    use RefreshDatabase;

    private function makeCemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    private function makeDraft(Cemetery $cemetery): BookingDraft
    {
        return BookingDraft::query()->create([
            'current_step' => 8,
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->getKey(),
            'service_type' => \App\Domain\Booking\BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
        ]);
    }

    public function test_a_held_draft_plot_converts_to_an_order_anchored_reservation_on_submit(): void
    {
        $cemetery = $this->makeCemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = $this->makeDraft($cemetery);
        $held = (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");

        $order = app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));

        $reservation = PlotReservation::query()->where('order_id', $order->getKey())->first();
        $this->assertNotNull($reservation);
        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame($plot->getKey(), $reservation->plot_id);

        $closedDraftHold = PlotReservation::query()->findOrFail($held->getKey());
        $this->assertSame(PlotReservationState::HELD, $closedDraftHold->state, 'append-only: original row unchanged');

        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    public function test_a_draft_with_no_plot_hold_submits_normally(): void
    {
        $cemetery = $this->makeCemetery();
        $draft = $this->makeDraft($cemetery);

        $order = app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));

        $this->assertNotNull($order);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_an_expired_hold_blocks_the_whole_submission(): void
    {
        $cemetery = $this->makeCemetery();
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draft = $this->makeDraft($cemetery);
        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        $this->expectException(DraftPlotHoldNoLongerValidException::class);

        try {
            app(SubmitBookingDraft::class)($draft, 'idem-'.Str::random(8));
        } finally {
            $this->assertSame(0, \App\Domain\OrderWorkflow\Models\Order::query()->count(), 'the whole transaction must roll back — no orphaned order');
        }
    }
}
```

- [ ] **Step 9: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php`
Expected: FAIL — `SubmitBookingDraft` does not yet call the conversion.

- [ ] **Step 10: Wire `ConvertDraftHoldToOrderReservation` into `SubmitBookingDraft`**

Add imports to `app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php`:
```php
use App\Domain\PlotReservation\Actions\ConvertDraftHoldToOrderReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
```
Add the dependency to the constructor (after `private RegisterPreNeedInterest $registerPreNeedInterest,`):
```php
        private ConvertDraftHoldToOrderReservation $convertDraftHold,
```
In `submit()`, immediately after the `OrderParty::query()->create([...]);` call and before `return $order;`, add:
```php
        // Only when the customer actually went through the Step 2 picker
        // (granular-tier cemetery) — most drafts have no hold at all, and
        // that is the normal case, not an error. See
        // `ConvertDraftHoldToOrderReservation`'s class doc block for the
        // no-fallback failure mode this can throw.
        $draftHold = PlotReservation::activeForDraft($draft);

        if ($draftHold instanceof PlotReservation) {
            ($this->convertDraftHold)($draftHold, $order);
        }
```

- [ ] **Step 11: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php`
Expected: PASS (3 tests).

- [ ] **Step 12: Run the full `SubmitBookingDraft` regression suite**

Run: `vendor/bin/phpunit --filter 'SubmitBookingDraft'`
Expected: all green — every existing submission path (no plot hold at all) is unaffected, confirmed by `test_a_draft_with_no_plot_hold_submits_normally`.

- [ ] **Step 13: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add app/Domain/PlotReservation/PlotReservationState.php \
        app/Domain/PlotReservation/Exceptions/DraftPlotHoldNoLongerValidException.php \
        app/Domain/PlotReservation/Actions/ConvertDraftHoldToOrderReservation.php \
        app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php \
        tests/Feature/Domain/PlotReservation/ConvertDraftHoldToOrderReservationTest.php \
        tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php
git commit -m "feat(plot-reservation): convert a draft plot hold into an order reservation on submit"
```

---

### Task 4: `plot-reservation:expire-stale-draft-holds` scheduled sweep

**Files:**
- Create: `app/Domain/PlotReservation/PlotReservationExpiryScheduler.php`
- Create: `app/Console/Commands/PlotReservationExpireStaleDraftHoldsCommand.php`
- Modify: `routes/console.php`
- Test: `tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php`

**Interfaces:**
- Consumes: `ExpirePlotReservation` (reused unchanged, Task 1's carry-forward fix), `PlotReservationState`, `PlotReservationTransitionException`.
- Produces: `plot-reservation:expire-stale-draft-holds` console command, registered `everyMinute()->withoutOverlapping()` in `routes/console.php`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\PlotReservation;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationExpiryScheduler;
use App\Domain\PlotReservation\PlotReservationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PlotReservationExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);

        return GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
    }

    public function test_it_expires_only_the_stale_held_draft_row(): void
    {
        $expiredPlot = $this->makePlot();
        $expiredDraft = BookingDraft::query()->create(['current_step' => 2]);
        $expiredHold = (new HoldPlotForDraft)($expiredPlot, $expiredDraft, "booking_draft:{$expiredDraft->getKey()}", ttlMinutes: -5);

        $freshPlot = $this->makePlot();
        $freshDraft = BookingDraft::query()->create(['current_step' => 2]);
        $freshHold = (new HoldPlotForDraft)($freshPlot, $freshDraft, "booking_draft:{$freshDraft->getKey()}", ttlMinutes: 15);

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        $this->assertCount(1, $expired);
        $this->assertSame($expiredHold->getKey(), $expired->first()->getKey());

        $this->assertSame(PlotState::AVAILABLE, $expiredPlot->fresh()->plot_state);
        $this->assertSame(PlotState::RESERVED, $freshPlot->fresh()->plot_state);

        $freshStillHead = PlotReservation::query()
            ->where('plot_id', $freshPlot->getKey())
            ->orderByDesc('created_at')->orderByDesc('id')->first();
        $this->assertSame(PlotReservationState::HELD, $freshStillHead->state);
    }

    public function test_it_is_idempotent_and_isolates_a_row_already_moved_on(): void
    {
        $plotA = $this->makePlot();
        $draftA = BookingDraft::query()->create(['current_step' => 2]);
        $holdA = (new HoldPlotForDraft)($plotA, $draftA, "booking_draft:{$draftA->getKey()}", ttlMinutes: -5);

        $plotB = $this->makePlot();
        $draftB = BookingDraft::query()->create(['current_step' => 2]);
        $holdB = (new HoldPlotForDraft)($plotB, $draftB, "booking_draft:{$draftB->getKey()}", ttlMinutes: -5);

        // Simulate holdA having already moved on (e.g. converted) between
        // the query and the write, by expiring it directly first.
        app(\App\Domain\PlotReservation\Actions\ExpirePlotReservation::class)($holdA, 'system', 'system');

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        // Only holdB is genuinely expirable by this run; holdA's row is no
        // longer the live HELD head, and the scheduler must isolate that
        // per-row rather than aborting the whole sweep.
        $this->assertCount(1, $expired);
        $this->assertSame($holdB->getKey(), $expired->first()->getKey());
    }

    public function test_a_non_expired_hold_is_left_untouched(): void
    {
        $plot = $this->makePlot();
        $draft = BookingDraft::query()->create(['current_step' => 2]);
        (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: 15);

        $expired = app(PlotReservationExpiryScheduler::class)->expireStaleDraftHolds();

        $this->assertCount(0, $expired);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php`
Expected: FAIL — `PlotReservationExpiryScheduler` does not exist.

- [ ] **Step 3: Write `PlotReservationExpiryScheduler.php`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\PlotReservation;

use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Exceptions\PlotReservationTransitionException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 4) — finds draft-scoped plot holds a customer abandoned and expires
 * them, mirroring `App\Domain\OrderWorkflow\QuoteExpiryScheduler`'s own
 * shape (read that class first): a domain-layer scheduler with a per-row
 * try/catch, backing a thin `Illuminate\Console\Command`.
 *
 * `ExpirePlotReservation` already exists and already does the real work
 * (plot-row lock, `held` -> `expired`, plot flip back to `available`,
 * audit + outbox) — this class is REUSE, not reimplementation: it only
 * finds the candidates and isolates a per-row failure.
 *
 * ---------------------------------------------------------------------------
 * Why the candidate query goes through `booking_draft_id`, not a raw
 * `state = 'held'` scan
 * ---------------------------------------------------------------------------
 * `plot_reservations` is append-only — a row's own `state` column NEVER
 * changes after insert (see `PlotReservation`'s class doc block). A naive
 * `where('state', HELD)->where('expires_at', '<', $now)` would therefore
 * match every draft-hold row that EVER became stale, forever, including
 * ones long since converted or expired by an earlier run — their OWN row
 * still reads `state = held` permanently; only a LATER, separate row in
 * the same plot's chain records what actually happened to it. Every run
 * would keep re-selecting that unboundedly growing historical set and
 * re-attempting (and catching a thrown exception for) each one, forever.
 *
 * Instead: find the DISTINCT `booking_draft_id`s that have any stale
 * `held` row at all (cheap, indexed), then re-derive each draft's TRUE
 * current hold via `PlotReservation::activeForDraft()` — the same
 * incumbent-of-the-latest-row logic `HoldPlotForDraft`/
 * `ConvertDraftHoldToOrderReservation` already trust. Only a draft whose
 * ACTUAL current head is still `held` and still past its `expires_at` is
 * a real candidate; everything else (already converted, already expired)
 * is skipped before ever calling `ExpirePlotReservation`, not merely
 * caught after attempting it.
 *
 * `AGENTS.md` §Queue and event reliability: "Consumers are idempotent" —
 * satisfied two ways here: the `activeForDraft()` re-derivation above
 * skips most already-resolved rows outright, and the remaining
 * `ExpirePlotReservation` call is itself idempotent against a row that
 * moved on in the brief window between that re-derivation and this
 * write (a genuine concurrent run) — it throws
 * `PlotReservationTransitionException`, caught and skipped below.
 */
final readonly class PlotReservationExpiryScheduler
{
    public function __construct(private ExpirePlotReservation $expirePlotReservation) {}

    /**
     * @return Collection<int, PlotReservation> the rows actually expired by
     *                                           this run.
     */
    public function expireStaleDraftHolds(?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        $candidateDraftIds = PlotReservation::query()
            ->whereNotNull('booking_draft_id')
            ->where('state', PlotReservationState::HELD)
            ->where('expires_at', '<', $now)
            ->distinct()
            ->pluck('booking_draft_id');

        $expired = new Collection;

        foreach ($candidateDraftIds as $draftId) {
            $draft = BookingDraft::query()->find($draftId);

            if ($draft === null) {
                continue;
            }

            $head = PlotReservation::activeForDraft($draft);

            if (
                $head === null
                || $head->state !== PlotReservationState::HELD
                || $head->expires_at === null
                || ! $head->expires_at->isPast()
            ) {
                // Not a real candidate — `activeForDraft()`'s own
                // incumbent-of-the-latest-row logic says this chain has
                // already moved on (converted/expired/released) or its
                // current head is not actually stale. See class doc block.
                continue;
            }

            try {
                $expired->push(($this->expirePlotReservation)($head, 'system', 'system'));
            } catch (PlotReservationTransitionException) {
                // Moved on between the re-derivation above and this write
                // (a genuine concurrent run) — nothing to do, and not an
                // error. Isolated per row so one stale candidate can never
                // starve the rest of a real sweep.
                continue;
            }
        }

        return $expired;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php`
Expected: PASS (3 tests).

- [ ] **Step 5: Write the thin console command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\PlotReservation\PlotReservationExpiryScheduler;
use Illuminate\Console\Command;

/**
 * `php artisan plot-reservation:expire-stale-draft-holds`
 *
 * Sweeps customer-abandoned Step 2 plot holds back to available.
 * `HoldPlotForDraft` is the only thing that creates a draft-anchored hold,
 * and until this command existed, nothing swept a hold the customer simply
 * closed the tab on — the plot would stay `reserved` forever, per the
 * roadmap's own "new scheduler required" note (`ExpirePlotReservation` is
 * operator-on-demand only).
 *
 * Scheduled every minute in `routes/console.php`, same cadence as
 * `outbox:publish` — a customer-visible plot staying falsely "reserved"
 * for a full nightly-batch window is not acceptable UX, unlike
 * `orders:expire-stale-quotes`'s cosmetic hourly cadence.
 *
 * Idempotent: `PlotReservationExpiryScheduler` silently skips a row that
 * has already moved on since it was queried — safe to re-run.
 *
 * Deliberately does NOT log or print plot/reservation content beyond a
 * count — same `AGENTS.md` §Observability discipline
 * `orders:expire-stale-quotes` and `booking:purge-stale-drafts` already
 * follow.
 */
final class PlotReservationExpireStaleDraftHoldsCommand extends Command
{
    protected $signature = 'plot-reservation:expire-stale-draft-holds';

    protected $description = 'Expire customer-abandoned draft plot holds and return their plots to available.';

    public function handle(PlotReservationExpiryScheduler $scheduler): int
    {
        $expired = $scheduler->expireStaleDraftHolds();

        $this->info("Expired {$expired->count()} stale draft plot hold(s).");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 6: Register the schedule**

In `routes/console.php`, add after the `outbox:publish` block (matching its exact style):
```php

// Sweep customer-abandoned Step 2 plot holds (App\Domain\PlotReservation\
// Actions\HoldPlotForDraft) back to available. Every minute, matching
// outbox:publish's cadence — a plot showing falsely "reserved" to every
// other customer is directly revenue-visible, not a cosmetic staleness
// window like orders:expire-stale-quotes.
Schedule::command('plot-reservation:expire-stale-draft-holds')->everyMinute()->withoutOverlapping();
```

- [ ] **Step 7: Run the console-command smoke test**

Run:
```
vendor/bin/phpunit --filter 'PlotReservationExpiry'
```
Then confirm the command itself is wired (real DB, real container):
```
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=<db> -e DB_USERNAME=<user> -e DB_PASSWORD=<pass> \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php artisan plot-reservation:expire-stale-draft-holds
```
Expected: `Expired 0 stale draft plot hold(s).` against an empty/migrated test database, no errors.

- [ ] **Step 8: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add app/Domain/PlotReservation/PlotReservationExpiryScheduler.php \
        app/Console/Commands/PlotReservationExpireStaleDraftHoldsCommand.php \
        routes/console.php \
        tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php
git commit -m "feat(plot-reservation): scheduled sweep for stale draft plot holds"
```

---

### Task 5: Step 2 picker UI + wizard wiring

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php`
- Modify: `resources/views/livewire/public/booking/wizard.blade.php`
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`

**Interfaces:**
- Consumes: `HoldPlotForDraft` (Task 2), `PlotReservation::activeForDraft()` (Task 1), `PlotTrackingMode` (Phase B), `StatusIntent::intent()`/`icon()`/`label()` with `FAMILY_PLOT_STATE`/`FAMILY_PLOT_RESERVATION` (Phase D).
- Produces: nothing further downstream — this is the plan's final task.

Read `app/Livewire/Public/Booking/BookingWizard.php` in FULL first (1188 lines), and `resources/views/livewire/public/booking/wizard.blade.php`'s Step 2 section (lines 151-315, already reproduced below for reference) — do not assume the line numbers below are still exact by the time this task runs; re-grep for `BookingWizardStep::CEMETERY` before editing.

**Design resolution (read before starting):** `saveStep2(string $cemeteryId, ?int $cemeteryPackageId = null)` today immediately persists the choice AND advances the draft (via `SaveBookingDraftStep`, called through `saveStepOrShowErrors`). This task does **not** change that method or its step-advance semantics — doing so risks the other 8 steps' sequencing. Instead, for a **granular-tier** cemetery, the existing "Pilih {{ cemetery }}" / per-package buttons are replaced with a two-phase flow on the SAME screen: (1) selecting a cemetery/package reveals the floor-map picker inline, without yet calling `saveStep2()`; (2) picking an available plot calls a NEW action, `holdPlotForStep2()`, which holds the plot AND THEN calls the existing `saveStep2()` internally with the already-known cemetery/package ids. This keeps `saveStep2()`'s own contract — and every other step's — completely untouched; the picker is a client-visible gate placed BEFORE it, not a change to it. For an **aggregate-tier** cemetery (or a cemetery capability lookup that fails/is unavailable — fail toward the existing behavior, never toward blocking a purchase), the screen behaves exactly as it does today: no picker, `saveStep2()` called directly by the existing buttons.

- [ ] **Step 1: Write the failing test**

The established pattern for reaching Step 2 in a test — confirmed from `tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoCardContentTest.php` (read it first) — is to drive the REAL component through `saveStep1()` (which creates the draft AND establishes its session binding via `BookingDraftBinding`), capture the resulting `draftId`, then mount a FRESH component instance with that id to simulate a resumed session. A `BookingDraft` row created directly via `BookingDraft::query()->create([...])`, with no binding established, resolves to `null` in `mount()` (see `resolveDraftById()`) and silently renders a blank wizard — do not do that.

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardPlotPickerTest extends TestCase
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

    /**
     * Drives the real component through Step 1 so the returned draft id
     * has a genuine session binding — see this step's own note above.
     */
    private function draftIdAtStep2(): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    public function test_the_picker_renders_for_a_granular_cemetery_at_step_2(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->assertSee('BLOK-A')
            ->assertSee('001');
    }

    public function test_the_picker_never_renders_for_an_aggregate_cemetery(): void
    {
        $this->makeCemetery(PlotTrackingMode::AGGREGATE);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertDontSee('Lihat Peta Plot');
    }

    public function test_holding_a_plot_persists_the_hold_and_advances_the_draft(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openPickerFor', $cemetery->id)
            ->call('holdPlotForStep2', $cemetery->id, null, $plot->id);

        $draft = BookingDraft::query()->findOrFail($draftId);
        $hold = PlotReservation::activeForDraft($draft);
        $this->assertNotNull($hold);
        $this->assertSame($plot->getKey(), $hold->plot_id);
        $this->assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
        $this->assertSame(3, $draft->current_step);
    }

    public function test_resuming_the_wizard_shows_the_already_held_plot(): void
    {
        $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
        $block = CemeteryBlock::query()->create(['cemetery_id' => $cemetery->getKey(), 'code' => 'BLOK-A', 'name' => 'Blok A', 'capacity' => 1]);
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        // The draft must actually have saved this cemetery for mount()'s
        // resume branch to know which cemetery's picker to reopen —
        // mirrors what saveStep2() would have set on the real path.
        $draft->forceFill(['cemetery_id' => $cemetery->id])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}");

        // A FRESH mount — no explicit openPickerFor() call — must reopen
        // the picker and show the live hold on its own.
        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSee('Ditahan');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`
Expected: FAIL — no picker markup exists yet, `holdPlotForStep2` is not a public method.

- [ ] **Step 3: Add wizard state and actions to `BookingWizard.php`**

Add imports:
```php
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\PlotReservation\Exceptions\PlotNotAvailableException;
use App\Domain\PlotReservation\Models\PlotReservation;
use Illuminate\Support\Str;
```

Add a public property near the other step-2 properties (`cemeteryId` etc — find their declarations and place alongside):
```php
    /**
     * The cemetery a granular-tier picker is currently showing plots for.
     * Distinct from `$this->cemeteryId` (the SAVED draft field, only set
     * once `saveStep2()` actually runs) — this is picker-only, pre-save
     * UI state, and untrusted client input like every other public
     * property (see `resolvePickerPlot()`).
     */
    public ?string $pickerCemeteryId = null;

    /**
     * The package id the picker was opened for, when the cemetery has
     * packages — null for a cemetery with none. Threaded through
     * `openPickerFor()` because the picker section renders ONCE, outside
     * the per-cemetery `@foreach`/`@foreach ($packages as $package)` loops
     * that hold this value in the existing Step 2 markup — a loop-local
     * Blade variable is not in scope there, so it must be captured as
     * component state instead.
     */
    public ?int $pickerCemeteryPackageId = null;
```

In `mount()`, in the resume branch — immediately after the existing `$this->hydrateFrom($draft);` line and before the method's closing brace — add:
```php

        // A genuine page reload/resume with a live plot hold should show
        // it without the customer manually reopening the picker. Scoped to
        // mount() only (not every hydrateFrom() call from an autosave
        // tick elsewhere in the wizard) — reopening on every save would
        // re-fight a customer who deliberately closed the picker.
        if (
            $draft->cemetery_id !== null
            && $this->pickerAppliesTo($draft->cemetery_id)
            && PlotReservation::activeForDraft($draft) !== null
        ) {
            $this->pickerCemeteryId = $draft->cemetery_id;
            $this->pickerCemeteryPackageId = $draft->cemetery_package_id;
        }
```

Add these methods (placed near `saveStep2()`):

```php
    /**
     * Whether Step 2's cemetery/package selection should show the plot
     * picker instead of the plain "Pilih ..." buttons. Fails toward the
     * EXISTING behaviour (no picker) on any lookup problem — a booking
     * must never be blocked by this feature being unavailable.
     */
    public function pickerAppliesTo(string $cemeteryId): bool
    {
        if (! Str::isUuid($cemeteryId)) {
            return false;
        }

        $cemetery = \App\Domain\CemeteryDirectory\Models\Cemetery::query()->find($cemeteryId);

        return $cemetery !== null && $cemetery->plot_tracking_mode === PlotTrackingMode::GRANULAR;
    }

    /**
     * Blocks (`code => name` => plots) for the picker, scoped to the
     * cemetery the picker is currently open on. Empty when nothing is
     * selected or the cemetery is not granular-tier — mirrors
     * `App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage::blocks()`'s
     * own fail-empty shape.
     */
    public function pickerBlocks(): \Illuminate\Support\Collection
    {
        if ($this->pickerCemeteryId === null || ! $this->pickerAppliesTo($this->pickerCemeteryId)) {
            return new \Illuminate\Support\Collection;
        }

        return CemeteryBlock::query()
            ->where('cemetery_id', $this->pickerCemeteryId)
            ->with(['plots' => fn ($query) => $query->orderBy('slot')])
            ->orderBy('code')
            ->get();
    }

    /**
     * The live draft hold for THIS draft, if any — resolved from the
     * database on every render, never from client state, so a stale
     * countdown can never outlive the real row. See design-system.md
     * §8.4: "the indicator is driven by a server-confirmed [value], never
     * by a local timer" — applied here to the hold countdown the same way
     * it already applies to the autosave indicator.
     */
    public function activeDraftPlotHold(): ?PlotReservation
    {
        if ($this->draftId === null) {
            return null;
        }

        $draft = \App\Domain\Booking\BookingDraftQuery::findBound($this->draftId);

        return $draft === null ? null : PlotReservation::activeForDraft($draft);
    }

    /**
     * Opens the picker for a cemetery/package the customer has not yet
     * saved via `saveStep2()` — client-visible UI state only, nothing
     * persisted. Called by the "Pilih {{ cemetery }}" buttons INSTEAD of
     * `saveStep2()` when `pickerAppliesTo()` is true; `saveStep2()` still
     * runs, just later — from `holdPlotForStep2()` below, once a plot is
     * actually held.
     */
    public function openPickerFor(string $cemeteryId, ?int $cemeteryPackageId = null): void
    {
        $this->pickerCemeteryId = $cemeteryId;
        $this->pickerCemeteryPackageId = $cemeteryPackageId;
    }

    /**
     * Holds the chosen plot for this draft, then saves Step 2 exactly the
     * way the existing non-picker buttons already do — `saveStep2()` is
     * called unchanged, so its own version-conflict/validation handling
     * applies identically here.
     */
    public function holdPlotForStep2(string $cemeteryId, ?int $cemeteryPackageId, string $plotId): void
    {
        if ($this->draftId === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        $draft = \App\Domain\Booking\BookingDraftQuery::findBound($this->draftId);

        if ($draft === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }

        if (! Str::isUuid($plotId) || ! $this->pickerAppliesTo($cemeteryId)) {
            $this->addError('plot', 'Plot tidak valid.');

            return;
        }

        $plot = GravePlot::query()
            ->whereHas('block', fn ($query) => $query->where('cemetery_id', $cemeteryId))
            ->find($plotId);

        if ($plot === null) {
            $this->addError('plot', 'Plot tidak ditemukan pada TPU/TPS ini.');

            return;
        }

        try {
            (new HoldPlotForDraft)($plot, $draft, "booking_draft:{$draft->getKey()}");
        } catch (PlotNotAvailableException) {
            $this->addError('plot', 'Plot ini baru saja dipilih oleh pengunjung lain. Silakan pilih plot lain.');

            return;
        }

        $this->saveStep2($cemeteryId, $cemeteryPackageId);
    }
```

- [ ] **Step 4: Extend `wizard.blade.php`'s Step 2 section**

Locate the `@elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CEMETERY)` block. Replace the existing `@if ($packages->isEmpty()) ... @else ... @endif` button block (the one wrapping the `saveStep2(...)` buttons) with this — same two branches (no packages / has packages), each now also branching on `pickerAppliesTo()` so a granular-tier cemetery opens the picker instead of saving directly, and a non-granular one behaves exactly as it does today:

```blade
                                    @if ($packages->isEmpty())
                                        @if ($this->pickerAppliesTo($cemetery->id))
                                            <x-mk.button
                                                variant="primary"
                                                full
                                                wire:click="openPickerFor('{{ $cemetery->id }}')"
                                            >
                                                Pilih {{ $cemetery->name }} &mdash; Lihat Peta Plot
                                            </x-mk.button>
                                        @else
                                            <x-mk.button
                                                variant="primary"
                                                full
                                                wire:click="saveStep2('{{ $cemetery->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="saveStep2"
                                            >
                                                Pilih {{ $cemetery->name }}
                                            </x-mk.button>
                                        @endif
                                    @else
                                        <div>
                                            <p id="cemetery-{{ $cemetery->id }}-packages-label" class="text-sm text-neutral-600">
                                                Pilih paket/kelas untuk TPU/TPS ini:
                                            </p>

                                            <ul class="mt-2 flex flex-wrap gap-2" aria-labelledby="cemetery-{{ $cemetery->id }}-packages-label">
                                                @foreach ($packages as $package)
                                                    <li>
                                                        @if ($this->pickerAppliesTo($cemetery->id))
                                                            <x-mk.button
                                                                variant="secondary"
                                                                wire:click="openPickerFor('{{ $cemetery->id }}', {{ $package->id }})"
                                                            >
                                                                {{ $package->name }}@if ($package->class_label) &mdash; {{ $package->class_label }}@endif
                                                                &mdash; Lihat Peta Plot
                                                            </x-mk.button>
                                                        @else
                                                            <x-mk.button
                                                                variant="secondary"
                                                                wire:click="saveStep2('{{ $cemetery->id }}', {{ $package->id }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="saveStep2"
                                                            >
                                                                {{ $package->name }}@if ($package->class_label) &mdash; {{ $package->class_label }}@endif
                                                            </x-mk.button>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
```

After the closing `</ul>` of the cemetery card grid (and before the `@error('cemetery_id')` block), add the picker section:

```blade
                @if ($this->pickerCemeteryId !== null)
                    <section aria-labelledby="plot-picker-heading" class="mt-6 border-t border-neutral-200 pt-6">
                        <h3 id="plot-picker-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                            Pilih plot
                        </h3>

                        @php $hold = $this->activeDraftPlotHold(); @endphp

                        @if ($hold !== null)
                            <x-mk.alert intent="pending" title="Plot ditahan sementara" live="polite" wire:poll.5s>
                                Plot Anda ditahan selama beberapa menit agar tidak diambil pengunjung lain.
                                Selesaikan langkah berikutnya sebelum waktu habis.
                                <x-mk.badge intent="{{ \App\Support\Design\StatusIntent::intent($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION) }}"
                                            :icon="\App\Support\Design\StatusIntent::icon($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION)">
                                    {{ \App\Support\Design\StatusIntent::label($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION) }}
                                </x-mk.badge>
                            </x-mk.alert>
                        @endif

                        @error('plot')
                            <p class="mb-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-y-6">
                            @forelse ($this->pickerBlocks() as $block)
                                <div>
                                    <p class="mb-2 text-sm font-medium text-neutral-900">{{ $block->code }} &mdash; {{ $block->name }}</p>
                                    <ul class="flex flex-wrap gap-2" aria-label="Plot di {{ $block->code }}">
                                        @foreach ($block->plots as $plot)
                                            <li wire:key="plot-{{ $plot->id }}">
                                                <x-mk.button
                                                    size="sm"
                                                    variant="secondary"
                                                    :disabled="$plot->plot_state !== \App\Domain\PlotInventory\PlotState::AVAILABLE"
                                                    wire:click="holdPlotForStep2('{{ $this->pickerCemeteryId }}', {{ $this->pickerCemeteryPackageId ?? 'null' }}, '{{ $plot->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="holdPlotForStep2"
                                                >
                                                    {{ $plot->slot }}
                                                    <x-mk.badge
                                                        intent="{{ \App\Support\Design\StatusIntent::intent($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}"
                                                        :icon="\App\Support\Design\StatusIntent::icon($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE)"
                                                        size="sm"
                                                    >
                                                        {{ \App\Support\Design\StatusIntent::label($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}
                                                    </x-mk.badge>
                                                </x-mk.button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @empty
                                <p class="text-sm text-neutral-600">Belum ada plot terdaftar untuk TPU/TPS ini.</p>
                            @endforelse
                        </div>
                    </section>
                @endif
```

- [ ] **Step 5: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`
Expected: PASS (4 tests).

- [ ] **Step 6: Run the design-reviewer lens and the full booking-wizard regression suite**

Run:
```
vendor/bin/phpunit --filter 'BookingWizard'
bash ci/verify-docs.sh
```
Expected: all green, including GATE 2/3 (no hardcoded design values — every colour in the new markup goes through `<x-mk.badge intent="...">`/`<x-mk.alert intent="...">`, never a raw hex or arbitrary bracket value).

Then dispatch the `design-reviewer` agent (read-only) against the diff in `resources/views/livewire/public/booking/wizard.blade.php`, per this repo's established practice before any Blade/Livewire batch is committed — confirm the ten mandatory screen states are covered by the new picker section (empty blocks, no plots, plot unavailable/disabled state, hold-active state, error state) and that `StatusIntent` is used correctly.

- [ ] **Step 7: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add app/Livewire/Public/Booking/BookingWizard.php \
        resources/views/livewire/public/booking/wizard.blade.php \
        tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php
git commit -m "feat(booking): step 2 plot picker for granular-tier cemeteries"
```

---

---

### Task 6: Route the customer back to Step 2 on a lost/expired hold at submission

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php`
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardExpiredHoldOnSubmitTest.php`

**Interfaces:**
- Consumes: `DraftPlotHoldNoLongerValidException` (Task 3), `pickerAppliesTo()`/`pickerCemeteryId` (Task 5).
- Produces: nothing further downstream — final task of this plan.

**Why this task exists, separate from Task 3 and Task 5:** `ConvertDraftHoldToOrderReservation`'s exception (Task 3) is thrown deep inside `SubmitBookingDraft`, which is called from TWO places in the wizard — `saveStep8()` (manual payment, ~line 493 as of this plan's writing — re-grep, do not trust the line number) and `openOnlinePayment()` (online payment, ~line 621). Neither call site is part of Step 2's picker UI (Task 5's scope), so wiring the catch belongs in its own task. Read both methods in FULL first — `saveStep8()`'s existing `catch (UnroutableProductTypeException|InvalidArgumentException $e) { report($e); }` currently lets everything else propagate uncaught (a real gap: `DraftPlotHoldNoLongerValidException extends RuntimeException`, not `InvalidArgumentException`, so today it would 500); `openOnlinePayment()`'s existing catches are all `InvalidArgumentException`/specific payment exceptions plus a final `catch (Throwable $e)` that reports and shows a generic error while staying on the PAYMENT step — neither existing path currently routes back to Step 2, which is what the roadmap's decision #7 requires specifically for this failure.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\Booking\BookingServiceType;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotReservation\Actions\HoldPlotForDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingWizardStep;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardExpiredHoldOnSubmitTest extends TestCase
{
    use RefreshDatabase;

    private function draftIdAtStep2(): string
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $this->assertIsString($draftId);

        return $draftId;
    }

    public function test_manual_submission_with_an_expired_hold_routes_back_to_step_2_not_a_500(): void
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
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->set('paymentReference', 'BCA 123456789 a.n. Uji Coba')
            ->call('saveStep8', BookingPaymentMethod::MANUAL)
            ->assertHasNoErrors() // no 500 / uncaught exception surfaced as a Livewire fault
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);
    }

    public function test_online_submission_with_an_expired_hold_routes_back_to_step_2(): void
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
        $plot = GravePlot::query()->create(['block_id' => $block->getKey(), 'slot' => '001', 'plot_state' => 'available']);
        $draftId = $this->draftIdAtStep2();
        $draft = BookingDraft::query()->findOrFail($draftId);
        $draft->forceFill([
            'cemetery_id' => $cemetery->id,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'customer_full_name' => 'Uji Coba',
            'customer_mobile' => '081200000000',
            'customer_relationship' => 'anak',
            'current_step' => BookingWizardStep::PAYMENT,
        ])->saveQuietly();
        app(HoldPlotForDraft::class)($plot, $draft, "booking_draft:{$draft->getKey()}", ttlMinutes: -1);

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('openOnlinePayment')
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);
    }
}
```

Note before running: this test's fixture (`BookingServiceType`, `customer_relationship`, etc.) mirrors Task 3's own `SubmitBookingDraftConvertsPlotHoldTest` fixture — if `saveStep8()`/`openOnlinePayment()` requires additional draft fields not listed here (e.g. from `SaveBookingDraftStep`'s own step-8 validation), read the thrown validation error and extend the fixture; this is the same expected cross-boundary friction Task 3's Step 8 note already flags.

- [ ] **Step 2: Run test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardExpiredHoldOnSubmitTest.php`
Expected: FAIL — `saveStep8()` currently lets the exception propagate uncaught (Livewire surfaces this as an exception, `assertHasNoErrors()`/the flow does not reach a clean `currentStep` assertion); `openOnlinePayment()` currently stays on `BookingWizardStep::PAYMENT`, not `CEMETERY`.

- [ ] **Step 3: Add the shared routing helper and imports**

Add import: `use App\Domain\PlotReservation\Exceptions\DraftPlotHoldNoLongerValidException;`

Add a private method near `handleVersionConflict()` (find it and place alongside — same "reset to a recoverable state" role):

```php
    /**
     * Per the roadmap's decision #7: an expired/lost draft plot hold at
     * submission time does NOT fall back to submitting without a
     * reservation — it blocks, and the customer is routed back to Step 2
     * to re-pick. Reopens the picker for whichever cemetery the draft had
     * saved, so the customer lands on a live grid rather than the bare
     * cemetery list.
     */
    private function routeBackToPlotPickerAfterExpiredHold(): void
    {
        $this->currentStep = BookingWizardStep::CEMETERY;
        $this->addError('plot', 'Plot yang Anda pilih sudah tidak lagi ditahan (kedaluwarsa atau diambil pengunjung lain). Silakan pilih plot lain.');

        if ($this->cemeteryId !== null && $this->pickerAppliesTo($this->cemeteryId)) {
            $this->pickerCemeteryId = $this->cemeteryId;
            $this->pickerCemeteryPackageId = $this->cemeteryPackageId;
        }
    }
```

- [ ] **Step 4: Wire the catch into `saveStep8()`**

Change the existing:
```php
        try {
            app(SubmitBookingDraft::class)($saved, 'booking:'.$saved->id.':submit');
        } catch (UnroutableProductTypeException|InvalidArgumentException $e) {
            report($e);
        }
```
to:
```php
        try {
            app(SubmitBookingDraft::class)($saved, 'booking:'.$saved->id.':submit');
        } catch (DraftPlotHoldNoLongerValidException) {
            $this->routeBackToPlotPickerAfterExpiredHold();
        } catch (UnroutableProductTypeException|InvalidArgumentException $e) {
            report($e);
        }
```

- [ ] **Step 5: Wire the catch into `openOnlinePayment()`**

Add a new catch clause immediately before the existing `catch (InvalidArgumentException|OverflowException)` block (order matters — `DraftPlotHoldNoLongerValidException` must be caught before the generic `Throwable` catch, and placing it before the `InvalidArgumentException` block keeps every specific catch grouped together at the top, matching this method's existing style):
```php
        } catch (DraftPlotHoldNoLongerValidException) {
            $this->routeBackToPlotPickerAfterExpiredHold();

            return;
        } catch (InvalidArgumentException|OverflowException) {
```

- [ ] **Step 6: Run test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardExpiredHoldOnSubmitTest.php`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full booking-wizard regression suite**

Run: `vendor/bin/phpunit --filter 'BookingWizard'`
Expected: all green — every other failure branch in `saveStep8()`/`openOnlinePayment()` (unroutable product type, unpriced service, payment-session denial, etc.) is unchanged; only the new `DraftPlotHoldNoLongerValidException` branch is added.

- [ ] **Step 8: `pint` + `phpstan` + commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress --memory-limit=1G
git add app/Livewire/Public/Booking/BookingWizard.php \
        tests/Feature/Livewire/Public/Booking/BookingWizardExpiredHoldOnSubmitTest.php
git commit -m "fix(booking): route the customer back to Step 2 when a draft plot hold expires at submission"
```

---

## Verification (Phase E, matching the roadmap's own Verification section)

- A two-connection concurrency test proves a customer hold and a second concurrent claim on the same plot serialize correctly (Task 2, `HoldPlotForDraftTwoConnectionTest`).
- An expired/lost hold blocks wizard submission and routes back to plot re-selection rather than silently proceeding (Task 3's `test_an_expired_hold_blocks_the_whole_submission` for the domain layer, Task 5's error-surfacing on `holdPlotForStep2` for a pick-time race, Task 6's `BookingWizardExpiredHoldOnSubmitTest` for the submit-time race on both payment paths).
- The scheduler sweeps expired holds on a real run, isolating a per-row failure rather than aborting the whole sweep (Task 4).
- The picker never renders for an aggregate-tier cemetery (Task 5, explicit regression test, not just relying on the structural argument).
