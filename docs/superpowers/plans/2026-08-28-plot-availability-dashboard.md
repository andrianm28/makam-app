# Plot Availability Dashboard Implementation Plan (Phase D)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship one Plot Availability page per back-office panel (`/admin`, `/operator`) that renders a Floor/Block Map for granular-tier cemeteries and read-only Quota Cards for aggregate-tier cemeteries, chosen by the selected cemetery's `plot_tracking_mode`, dispatching only existing, unmodified domain actions.

**Architecture:** One abstract `App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage` carries every behaviour — cemetery selection, the tracking-mode branch, the map, the quota cards, and the cell actions — and two thin panel subclasses differ in exactly one method, `cemeteryOptions()`, which is also the single server-side authorization seam every other read re-validates through. Status colours and Indonesian labels for both `PlotState` and `CemeteryPackageAvailabilityStatus` are centralised into `App\Support\Design\StatusIntent`, the project's mandated single status→intent resolver, and the already-shipped `GravePlotsTable` is refactored to consume the same family instead of its own hand-rolled `match()`.

**Tech Stack:** PHP 8.5, Laravel 13, Filament 5.7, Livewire 4, Blade, PostgreSQL 18, Redis 8.2, PHPUnit, Pint, PHPStan (larastan).

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` § "Plot Availability dashboard (Phase D — depends on A, B)". Phase A (`docs/superpowers/plans/2026-08-28-operator-panel-and-role.md`, PR #210) and Phase B (`docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md`) are its prerequisites and are already on this branch's base.

**Branch:** `feat/plot-availability-dashboard`, worktree `/home/ubuntu/makam-app/.worktrees/plot-availability-dashboard`. `vendor/` is already hard-linked in — do NOT run `composer install` on the host.

---

## Corrections to the originating brief (verified against the real files, 28 Aug 2026)

The design roadmap and the task brief that produced this plan contain five factual errors. Each is corrected here and the corrected value is what every task below uses.

1. **`PlotState` values are lowercase, not uppercase.** `app/Domain/PlotInventory/PlotState.php` defines `AVAILABLE = 'available'`, `RESERVED = 'reserved'`, `OCCUPIED = 'occupied'`, `MAINTENANCE = 'maintenance'`. The roadmap writes them as `AVAILABLE→success` etc., which are the *constant names*, not the stored values. Every `StatusIntent` map key for this family MUST be the lowercase stored value or nothing will ever resolve. (`CemeteryPackageAvailabilityStatus` genuinely IS uppercase: `'AVAILABLE'`/`'LIMITED'`/`'UNAVAILABLE'`.)

2. **`maintenance` is `info`, not `gray`.** The roadmap sketches `MAINTENANCE→gray`; the already-shipped `GravePlotsTable::stateColor()` (`app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php:210-219`) returns `'info'`. **Ruling: preserve the shipped behaviour.** Changing a live admin page's badge colour is a visible behaviour change outside Phase D's remit, and the roadmap line is an earlier, less-informed sketch. All four shipped colours are preserved exactly: `available→success`, `reserved→warning`, `occupied→danger`, `maintenance→info`.

3. **`StatusIntent` has no per-status label table.** Its `label()` method ignores `$family` entirely and returns a structural humanisation (`ucwords(strtolower(str_replace('_', ' ', $status)))`). `humanize('available')` is `'Available'`, not `'Tersedia'` — so the Indonesian labels this phase needs cannot come from the existing mechanism. Task 1 therefore adds an **optional** `'label'` key to `MAP` entries and makes `label()` prefer it, falling back to `humanize()` when absent. Every existing family is unaffected because none carries the new key.

4. **`StatusIntent`'s own doc block forbids inventing a family in code.** Lines 74-82 read: "To extend, add a `FAMILY_*` constant and a new entry in `MAP` below, sourced from a design-system.md update (a new §3.7 table), not invented in this file." Its doc block also still claims plot-inventory "does not define a status enum … nothing to map yet", which has been false since `PlotState` shipped. **Task 1 therefore also adds the two normative §3.7 tables to `docs/design/design-system.md` and corrects the stale paragraph.** This is not optional scope — without it the code change violates the class's own governance rule and design-system.md §9.1.

5. **Neither panel provider uses `discoverPages()`.** `AdminPanelProvider` and `OperatorPanelProvider` both register pages through an explicit `->pages([...])` array (`AdminPanelProvider.php:230-236`, `OperatorPanelProvider.php`), for the documented unconfirmed-discovery-behaviour reason. A new Page class is invisible until it is added to that array by hand.

Two further findings the brief did not raise:

6. **`cemetery_operator` is NOT a master-data role.** `MasterDataAdminAuthorizer::ALLOWED` is `[admin, restricted_admin, operator, finance]`. `ActorRole::CEMETERY_OPERATOR` is a separate role (Phase A) and the `/operator` panel admits only it (`CemeteryOperatorPanelAccessPolicy`). **Ruling: this phase does NOT widen write authorization.** The map's write actions are gated by the same `MasterDataAdminAuthorizerContract` on both panels, so a bare `cemetery_operator` gets a correct, complete, **read-only** map. Widening writes to `cemetery_operator` is a real authorization decision requiring product sign-off and human review; it is explicitly out of scope and recorded as a follow-up in Task 7. This mirrors the deliberate incompleteness Phase A already recorded in `ReservePlotAction::ALLOWED_ROLES`.

7. **Postgres will hard-error on a malformed UUID comparison; SQLite will not.** `grave_plots.id`, `cemeteries.id` and `orders.id` are all `uuid`-typed (models use `HasUuids`, `$keyType = 'string'`). A Livewire wire call can put arbitrary text into a public property, and `whereKey('garbage')` raises a Postgres `invalid input syntax for type uuid` 500 rather than returning null. Every client-supplied id in this plan is guarded with `Str::isUuid()` before it reaches a query. Do not simplify this away because a SQLite run passes.

---

## Global Constraints

Every task's requirements implicitly include this section.

- `declare(strict_types=1);` on **every** new PHP file, immediately after `<?php`.
- Every new class is `final` unless it is the deliberate abstract base (`BasePlotFloorMapPage`).
- All user-facing copy is **Indonesian**. No English strings reach a rendered page.
- **Never modify** `app/Domain/PlotReservation/Actions/{ReservePlot,ConfirmPlotReservation,ReleasePlotReservation,ExpirePlotReservation}.php` or `app/Domain/PlotInventory/**`. This phase consumes them; it does not change them.
- **No new design values.** `docs/design/design-system.md` §10: "NEVER hardcode a value." No hex literals, no Tailwind arbitrary values (`text-[#12545E]`, `p-[13px]`). `ci/verify-docs.sh` GATE 2 and GATE 3 fail the build on either.
- **Panels use stock Filament, not the public site's `<x-mk.*>` primitives** (`design-system.md` §8.3, superseded 26 Aug 2026). Blade views use `<x-filament-panels::page>`, `<x-filament::section>`, `<x-filament::button>`, `<x-filament::badge>`, `<x-filament::modal>`. See the "Blade class vocabulary" ruling below.
- **Blade class vocabulary ruling.** Neither panel carries a custom theme CSS file any more (`vite.config.js` records the 26 Aug 2026 removal of `resources/css/filament/admin/theme.css`; no provider calls `->viteTheme()`), so panel Blade views render against Filament's own precompiled stylesheet and there is no guarantee an arbitrary Tailwind utility is present in it. **Use only the utility vocabulary that `resources/views/filament/admin/pages/feature-gate-admin.blade.php` already ships** — `grid`, `gap-y-*`, `gap-*`, `flex`, `flex-wrap`, `items-center`, `px-*`, `py-*`, `text-sm`, `text-xs`, `font-medium`, `font-mono`, `text-neutral-*`, `bg-neutral-*`, `rounded-lg`, `border`, `border-neutral-*`, `overflow-x-auto`, `min-w-full`, `divide-y`, `w-full`, `max-w-md`, `fi-input` — and let Filament's own components carry everything else. Do not introduce a new utility family (no `aspect-*`, no `grid-cols-12`, no `columns-*`).
- **Real Postgres 18 + Redis 8.2 only. Never SQLite.** A green SQLite run does not verify this code — see correction 7.
- Composer/npm builds never run on this host. Verify by pushing and reading CI (`.github/workflows/ci.yml`).
- **Human review is mandatory before merge** per `AGENTS.md` §Infrastructure-agent execution. This branch touches authorization-adjacent cemetery scoping and audited state writes, even though it is read-mostly.
- **Never report `PASS` for a check that was not executed.** Use `BLOCKED` or `NOT TESTED`.

### The exact verification commands (used verbatim by every task)

Source: `docs/operations/local-test-recipe.md`. Run these from `/home/ubuntu/makam-app/.worktrees/plot-availability-dashboard`. The host's PHP is 8.3 and the app needs 8.5, so everything runs inside the pinned CI container.

**One-time, at the start of the branch — start the containers:**

```bash
cd /home/ubuntu/makam-app/.worktrees/plot-availability-dashboard
ss -ltn | grep -E '55432|56379' || true   # confirm the ports are free; pick others if not
docker run -d --name plotmap-pg -e POSTGRES_USER=makam_test -e POSTGRES_PASSWORD=makam_test \
  -e POSTGRES_DB=makam_test -p 55432:5432 postgres:18
docker run -d --name plotmap-redis -p 56379:6379 redis:8.2-alpine
until docker exec plotmap-pg pg_isready -U makam_test >/dev/null 2>&1; do sleep 1; done
docker exec plotmap-pg psql -U makam_test -d makam_test \
  -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;' \
  -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'
IMAGE=$(docker images --digests --format '{{.Repository}}:{{.Tag}}' | grep makam-app | head -1)
echo "$IMAGE"   # note this; it is <IMAGE> below
```

**Run tests (`<TEST PATHS>` is filled in per task):**

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=55432 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=56379 \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  <IMAGE> php -d memory_limit=512M vendor/bin/phpunit <TEST PATHS>
```

**Style and static analysis (no containers needed):**

```bash
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  <IMAGE> vendor/bin/pint --test
docker run --rm --network host --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  <IMAGE> vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

**Docs gate:**

```bash
bash ci/verify-docs.sh
```

**Teardown, once the branch is finished:**

```bash
docker rm -f plotmap-pg plotmap-redis
```

Throughout this plan, `RUN-TESTS <paths>` means the test command above with `<TEST PATHS>` replaced; `RUN-PINT`, `RUN-PHPSTAN` and `RUN-DOCS` mean the three gate commands.

---

## File Structure

**Created**

| File | Responsibility |
|---|---|
| `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php` | Every behaviour of the dashboard: cemetery selection + re-validation, tracking-mode branch, block/plot and package reads, the cell modal state, and all cell actions. The one place authorization is enforced. |
| `app/Filament/Shared/PlotInventory/PlotStateOverrides.php` | The single write path for the three audited plot-state overrides (from-state guard + `Audit::wrap` + notifications), shared by `GravePlotsTable` and the new map. |
| `app/Filament/Admin/Pages/PlotFloorMap.php` | `/admin` subclass — all cemeteries. |
| `app/Filament/Operator/Pages/PlotFloorMap.php` | `/operator` subclass — granted cemeteries only. |
| `resources/views/filament/shared/plot-floor-map.blade.php` | Page shell: cemetery select, tracking-mode branch, the cell modal. |
| `resources/views/filament/shared/plot-floor-map/granular.blade.php` | Floor/Block Map — sections per `CemeteryBlock`, plot cells in slot order, colour legend. |
| `resources/views/filament/shared/plot-floor-map/aggregate.blade.php` | Quota Cards — one read-only card per active `CemeteryPackage`. |
| `tests/Feature/Filament/PlotFloorMapPageTest.php` | Rendering, cemetery-options scoping, tracking-mode branch, malformed-id safety. |
| `tests/Feature/Filament/PlotFloorMapActionsTest.php` | State overrides, order-linked reserve, reservation lifecycle actions. |

**Modified**

| File | Change |
|---|---|
| `app/Support/Design/StatusIntent.php` | Two new families + optional per-status `label`; stale doc paragraph corrected. |
| `docs/design/design-system.md` §3.7 | Two new normative status→intent tables. |
| `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php` | `stateColor()`/`stateLabel()` delegate to `StatusIntent`; the override write path moves to `PlotStateOverrides`. |
| `tests/Unit/Support/Design/StatusIntentTest.php` | Coverage for both new families and the label mechanism. |
| `app/Providers/Filament/AdminPanelProvider.php` | Register `PlotFloorMap` in `->pages([...])`. |
| `app/Providers/Filament/OperatorPanelProvider.php` | Register `PlotFloorMap` in `->pages([...])`. |
| `docs/product/screen-inventory.md` | New rows ADM-250 (admin) and ADM-251 (operator). |

---

## Task 1: Centralise plot and package status → intent in `StatusIntent`

Everything else in this plan reads colours and Indonesian labels through the families this task creates. Do it first.

**Files:**
- Modify: `app/Support/Design/StatusIntent.php`
- Modify: `docs/design/design-system.md` (§3.7, after the "Marketplace payment" table, before the two `>` blockquotes that close the section)
- Modify: `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php:210-230`
- Test: `tests/Unit/Support/Design/StatusIntentTest.php`

**Interfaces:**
- Consumes: nothing from earlier tasks.
- Produces:
  - `StatusIntent::FAMILY_PLOT_STATE` (`string`, value `'plot_state'`)
  - `StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY` (`string`, value `'cemetery_package_availability'`)
  - `StatusIntent::filamentColor(string $status, ?string $family = null): string` — unchanged signature, now resolves both new families
  - `StatusIntent::label(string $status, ?string $family = null): string` — unchanged signature, now returns the mapped Indonesian label when the family defines one
  - `GravePlotsTable::stateColor(string $state): string` and `::stateLabel(string $state): string` — unchanged signatures and unchanged outputs

- [ ] **Step 1: Write the failing tests**

Append to `tests/Unit/Support/Design/StatusIntentTest.php`, inside the class, after the existing care-family tests:

```php
    // -------------------------------------------------------------------
    // Plot inventory (design-system.md §3.7 "Plot state")
    // -------------------------------------------------------------------

    /**
     * The four values are the LOWERCASE stored values of
     * `App\Domain\PlotInventory\PlotState`, not its constant names — a map
     * keyed on 'AVAILABLE' would never resolve a real `grave_plots` row.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function plotStateRows(): array
    {
        return [
            ['available', StatusIntent::INTENT_SUCCESS, 'success', 'Tersedia'],
            ['reserved', StatusIntent::INTENT_PENDING, 'warning', 'Dipesan'],
            ['occupied', StatusIntent::INTENT_DANGER, 'danger', 'Terisi'],
            ['maintenance', StatusIntent::INTENT_INFO, 'info', 'Perawatan'],
        ];
    }

    #[DataProvider('plotStateRows')]
    public function test_plot_states_resolve_per_design_system_table(
        string $status,
        string $intent,
        string $filamentColor,
        string $label,
    ): void {
        $this->assertSame($intent, StatusIntent::intent($status, StatusIntent::FAMILY_PLOT_STATE));
        $this->assertSame($filamentColor, StatusIntent::filamentColor($status, StatusIntent::FAMILY_PLOT_STATE));
        $this->assertSame($label, StatusIntent::label($status, StatusIntent::FAMILY_PLOT_STATE));
    }

    /**
     * Regression lock for the roadmap's own earlier, less-informed sketch
     * (`MAINTENANCE→gray`). `GravePlotsTable` has shipped `info` since 16
     * Aug 2026; centralising the mapping must not silently repaint a live
     * admin page.
     */
    public function test_maintenance_stays_info_not_gray(): void
    {
        $this->assertSame('info', StatusIntent::filamentColor('maintenance', StatusIntent::FAMILY_PLOT_STATE));
        $this->assertNotSame('gray', StatusIntent::filamentColor('maintenance', StatusIntent::FAMILY_PLOT_STATE));
    }

    public function test_every_known_plot_state_is_mapped(): void
    {
        $this->assertSame(
            \App\Domain\PlotInventory\PlotState::KNOWN_STATES,
            StatusIntent::knownStatuses(StatusIntent::FAMILY_PLOT_STATE),
        );
    }

    // -------------------------------------------------------------------
    // Cemetery package availability (design-system.md §3.7)
    // -------------------------------------------------------------------

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function packageAvailabilityRows(): array
    {
        return [
            ['AVAILABLE', StatusIntent::INTENT_SUCCESS, 'success', 'Tersedia'],
            ['LIMITED', StatusIntent::INTENT_PENDING, 'warning', 'Terbatas'],
            ['UNAVAILABLE', StatusIntent::INTENT_DANGER, 'danger', 'Penuh'],
        ];
    }

    #[DataProvider('packageAvailabilityRows')]
    public function test_package_availability_statuses_resolve_per_design_system_table(
        string $status,
        string $intent,
        string $filamentColor,
        string $label,
    ): void {
        $family = StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY;

        $this->assertSame($intent, StatusIntent::intent($status, $family));
        $this->assertSame($filamentColor, StatusIntent::filamentColor($status, $family));
        $this->assertSame($label, StatusIntent::label($status, $family));
    }

    public function test_every_known_package_availability_status_is_mapped(): void
    {
        $this->assertSame(
            \App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus::KNOWN_STATUSES,
            StatusIntent::knownStatuses(StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY),
        );
    }

    /**
     * The two families both contain an "available" concept but with
     * DIFFERENT stored spellings (`available` vs `AVAILABLE`), so the
     * family-less resolver still has an unambiguous answer for each and
     * neither can shadow the other.
     */
    public function test_the_two_new_families_do_not_collide_on_a_bare_status_string(): void
    {
        $this->assertSame(StatusIntent::INTENT_SUCCESS, StatusIntent::intent('available'));
        $this->assertSame(StatusIntent::INTENT_SUCCESS, StatusIntent::intent('AVAILABLE'));
        $this->assertSame('Tersedia', StatusIntent::label('available'));
        $this->assertSame('Tersedia', StatusIntent::label('AVAILABLE'));
    }

    /**
     * A family with no explicit label keeps the existing structural
     * humanisation — the new `label` key is additive, never a rewrite of
     * how the four already-shipped families read.
     */
    public function test_a_family_without_an_explicit_label_still_humanises(): void
    {
        $this->assertSame('Masuk', StatusIntent::label('MASUK', StatusIntent::FAMILY_ORDER_LIFECYCLE));
        $this->assertSame('Menunggu Vendor', StatusIntent::label('MENUNGGU_VENDOR', StatusIntent::FAMILY_VENDOR_PROCESSING));
    }
```

And add, in the same file, a lock that the shipped table did not change:

```php
    public function test_grave_plots_table_colours_and_labels_are_unchanged_by_the_centralisation(): void
    {
        $table = \App\Filament\Admin\Resources\GravePlots\Tables\GravePlotsTable::class;

        $this->assertSame('success', $table::stateColor('available'));
        $this->assertSame('warning', $table::stateColor('reserved'));
        $this->assertSame('danger', $table::stateColor('occupied'));
        $this->assertSame('info', $table::stateColor('maintenance'));

        $this->assertSame('Tersedia', $table::stateLabel('available'));
        $this->assertSame('Dipesan', $table::stateLabel('reserved'));
        $this->assertSame('Terisi', $table::stateLabel('occupied'));
        $this->assertSame('Perawatan', $table::stateLabel('maintenance'));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

`RUN-TESTS tests/Unit/Support/Design/StatusIntentTest.php`

Expected: FAIL. `Undefined constant App\Support\Design\StatusIntent::FAMILY_PLOT_STATE`.

- [ ] **Step 3: Add the two families and the optional label to `StatusIntent`**

In `app/Support/Design/StatusIntent.php`, after `public const FAMILY_CARE_MAKE_GOOD = 'care_make_good';`, add:

```php
    /**
     * `App\Domain\PlotInventory\PlotState` — design-system.md §3.7 "Plot
     * state". Keys are the LOWERCASE stored values of that class, not its
     * constant names: `grave_plots.plot_state` holds 'available', never
     * 'AVAILABLE', and a map keyed on the constant name would silently
     * resolve every real row to the neutral fallback.
     */
    public const FAMILY_PLOT_STATE = 'plot_state';

    /**
     * `App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus` —
     * design-system.md §3.7 "Cemetery package availability". Deliberately a
     * SEPARATE family from plot state: they answer two different questions
     * at two different granularities (one class-level indicative claim vs.
     * one plot's operational truth) and an aggregate-tier cemetery has no
     * plot states at all.
     */
    public const FAMILY_CEMETERY_PACKAGE_AVAILABILITY = 'cemetery_package_availability';
```

Add both entries at the end of the `MAP` array, after `FAMILY_CARE_MAKE_GOOD`:

```php
        // Plot state — design-system.md §3.7 "Plot state" (Phase D of the
        // TPU/TPS operator dashboard roadmap). The intents are chosen so
        // the Filament bridge reproduces `GravePlotsTable`'s ALREADY
        // SHIPPED colours byte for byte (success / warning / danger /
        // info): centralising a mapping must not repaint a live page.
        // `occupied` carries the `slash` icon for the same reason
        // `DIBATALKAN` does — terminal and factual, not an error — even
        // though its colour is `danger`, which reads "not bookable".
        self::FAMILY_PLOT_STATE => [
            'available' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'check-circle', 'label' => 'Tersedia'],
            'reserved' => ['intent' => self::INTENT_PENDING, 'icon' => 'clock', 'label' => 'Dipesan'],
            'occupied' => ['intent' => self::INTENT_DANGER, 'icon' => 'slash', 'label' => 'Terisi'],
            'maintenance' => ['intent' => self::INTENT_INFO, 'icon' => 'cog', 'label' => 'Perawatan'],
        ],
        // Cemetery package availability — design-system.md §3.7. Every
        // value here is INDICATIVE by construction (see that enum's own
        // doc block: the owning cemetery's `availability_mode` is the only
        // thing that could ever make an availability claim a guarantee,
        // and it never is under the safe default), so `AVAILABLE` renders
        // `success` as "currently open for enquiry", never as a promise.
        self::FAMILY_CEMETERY_PACKAGE_AVAILABILITY => [
            'AVAILABLE' => ['intent' => self::INTENT_SUCCESS, 'icon' => 'check-circle', 'label' => 'Tersedia'],
            'LIMITED' => ['intent' => self::INTENT_PENDING, 'icon' => 'alert-circle', 'label' => 'Terbatas'],
            'UNAVAILABLE' => ['intent' => self::INTENT_DANGER, 'icon' => 'slash', 'label' => 'Penuh'],
        ],
```

Widen the `MAP` docblock's `@var` to admit the optional label — replace the existing line

```php
     * @var array<string, array<string, array{intent: string, icon: string}>>
```

with

```php
     * @var array<string, array<string, array{intent: string, icon: string, label?: string}>>
```

Replace `label()` in full:

```php
    /**
     * Blade-facing resolver: `StatusIntent::label($status)`.
     *
     * Two-tier by design. A family MAY declare an explicit `label` per
     * status; when it does, that label wins. When it does not, the status
     * falls back to a structural humanisation — underscores to spaces,
     * title case.
     *
     * The fallback is right for the order-lifecycle, vendor-processing,
     * marketplace-payment and care families: their enums are already
     * Indonesian domain terms (MASUK, DIBAYAR, SELESAI, ...), so
     * humanising them produces readable Indonesian without inventing
     * product copy, and §3.6's "never abbreviate the canonical status enum
     * in the badge label" is satisfied because the full enum is always
     * present, just formatted for reading.
     *
     * The explicit tier exists because that argument does NOT hold for the
     * two families added with Phase D: `PlotState` and
     * `CemeteryPackageAvailabilityStatus` store ENGLISH values
     * ('available', 'LIMITED'), so humanising them would put English on an
     * Indonesian page. Their labels are not invented here either — they
     * are the copy `GravePlotsTable` has shipped since 16 Aug 2026 and the
     * copy design-system.md §3.7 now records normatively.
     *
     * Known rough edge (unchanged): `DIKIRIM_OR_DIJADWALKAN` humanises to
     * "Dikirim Or Dijadwalkan". Rewriting it to "atau" would be inventing
     * copy, so it is left as the honest structural transform.
     */
    public static function label(string $status, ?string $family = null): string
    {
        return self::resolve($status, $family)['label'] ?? self::humanize($status);
    }
```

Update `resolve()`'s return docblock and its agreement check so a label participates in the cross-family collision test. Replace

```php
    /**
     * @return array{intent: string, icon: string}
     */
    private static function resolve(string $status, ?string $family): array
```

with

```php
    /**
     * @return array{intent: string, icon: string, label?: string}
     */
    private static function resolve(string $status, ?string $family): array
```

and, inside the family-less branch, replace the agreement loop

```php
        foreach ($matches as $entry) {
            if ($entry['intent'] !== $first['intent'] || $entry['icon'] !== $first['icon']) {
                $allAgree = false;
                break;
            }
        }
```

with

```php
        foreach ($matches as $entry) {
            // Label participates: two families that agree on intent and
            // icon but disagree on the rendered words are still a genuine
            // collision — the badge a user reads would differ by which
            // family happened to be checked first.
            if ($entry['intent'] !== $first['intent']
                || $entry['icon'] !== $first['icon']
                || ($entry['label'] ?? null) !== ($first['label'] ?? null)) {
                $allAgree = false;
                break;
            }
        }
```

Finally, correct the stale paragraph in the class doc block. Replace

```php
 * `plot-inventory-and-reservation`'s design.md does not define a status
 * enum in its current form (only table ownership) — nothing to map yet.
```

with

```php
 * UPDATED 28 Aug 2026 (Phase D, plot availability dashboard): the
 * paragraph that previously stood here said `plot-inventory-and-
 * reservation` "does not define a status enum ... nothing to map yet".
 * That stopped being true when `App\Domain\PlotInventory\PlotState`
 * shipped (16 Aug 2026). Both it and
 * `App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus` are
 * now mapped below as `FAMILY_PLOT_STATE` and
 * `FAMILY_CEMETERY_PACKAGE_AVAILABILITY`, sourced — as the "Extending to
 * another domain" rule above requires — from two new design-system.md
 * §3.7 tables added in the same change, not invented in this file.
```

- [ ] **Step 4: Add the two normative tables to design-system.md §3.7**

In `docs/design/design-system.md`, immediately after the **Marketplace payment** table and before the `> `PaymentState` and `VendorProcessingStatus` are deliberately separate…` blockquote, insert:

```markdown
**Plot state** (`PlotState`, added 28 Aug 2026 with the plot availability dashboard)

Status values are the lowercase stored values of `app/Domain/PlotInventory/PlotState.php` — `grave_plots.plot_state` never holds an uppercase value.

| Status | Intent | Icon | Label | Rationale |
|---|---|---|---|---|
| `available` | `success` | check-circle | Tersedia | Reservable now |
| `reserved` | `pending` | clock | Dipesan | Claimed by an active reservation, not yet a burial |
| `occupied` | `danger` | slash | Terisi | A burial has taken place. `danger` reads "not bookable", the `slash` icon reads "terminal and factual, not an error" |
| `maintenance` | `info` | cog | Perawatan | Operator-declared unavailable, work underway |

**Cemetery package availability** (`CemeteryPackageAvailabilityStatus`, added 28 Aug 2026 with the plot availability dashboard)

| Status | Intent | Icon | Label | Rationale |
|---|---|---|---|---|
| `AVAILABLE` | `success` | check-circle | Tersedia | Open for enquiry |
| `LIMITED` | `pending` | alert-circle | Terbatas | Capacity constrained |
| `UNAVAILABLE` | `danger` | slash | Penuh | No capacity at this class |

> **Every value in the cemetery-package table is indicative, never a guarantee.** The owning cemetery's `cemetery_capability_profiles.availability_mode` is the single source of truth for whether any availability claim is a guarantee, and it never is under the safe `INDICATIVE` default (see `CemeteryPackageAvailabilityStatus`'s own doc block). `Tersedia` therefore means "open for enquiry", never "reserved for you".

> **Plot state and package availability are two granularities, not two spellings of one thing.** A granular-tier cemetery answers availability from `grave_plots.plot_state`; an aggregate-tier cemetery answers it from `cemetery_packages.availability_status`. They are separate `StatusIntent` families and must never be merged.
```

- [ ] **Step 5: Refactor `GravePlotsTable` to consume the new family**

In `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php`, add the import `use App\Support\Design\StatusIntent;` and replace both methods at lines 210-230:

```php
    /**
     * design-system.md §3.7 is normative and §9.2 MUST #5 is enforceable:
     * "Components must not switch on enum strings. Resolve status → intent
     * in ONE place." The local `match ($state)` that used to live here was
     * that forbidden switch; the mapping now lives in
     * `StatusIntent::FAMILY_PLOT_STATE`, which the Phase D floor map reads
     * too, so the two surfaces cannot drift into two colour schemes for
     * the same four states.
     *
     * The rendered output is UNCHANGED: success / warning / danger / info,
     * exactly as this table has shipped since 16 Aug 2026 — locked by
     * `StatusIntentTest::test_grave_plots_table_colours_and_labels_are_
     * unchanged_by_the_centralisation()`.
     */
    public static function stateColor(string $state): string
    {
        return StatusIntent::filamentColor($state, StatusIntent::FAMILY_PLOT_STATE);
    }

    /**
     * Same centralisation as `stateColor()`. One behavioural difference
     * from the removed `match`: an UNKNOWN state used to return the raw
     * value verbatim and now returns its humanisation, plus a logged
     * warning. Unreachable in practice — `GravePlot::booted()` asserts
     * `PlotState::assertKnown()` on every save, so no row can carry an
     * unmapped state — and the logged warning is strictly more useful
     * than silently rendering a raw enum to an operator.
     */
    public static function stateLabel(string $state): string
    {
        return StatusIntent::label($state, StatusIntent::FAMILY_PLOT_STATE);
    }
```

- [ ] **Step 6: Run the tests to verify they pass**

`RUN-TESTS tests/Unit/Support/Design/StatusIntentTest.php tests/Feature/Filament/PlotInventoryAdminTest.php`

Expected: PASS. `PlotInventoryAdminTest` is included because it exercises the shipped table this step just refactored — it must stay green with zero edits.

- [ ] **Step 7: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`. All three must pass. `verify-docs.sh` GATE 4 checks every markdown link in the section you edited resolves.

- [ ] **Step 8: Commit**

```bash
git add app/Support/Design/StatusIntent.php \
        docs/design/design-system.md \
        app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php \
        tests/Unit/Support/Design/StatusIntentTest.php
git commit -m "feat(design): map plot state and package availability in StatusIntent

Adds FAMILY_PLOT_STATE and FAMILY_CEMETERY_PACKAGE_AVAILABILITY, sourced
from two new normative design-system.md 3.7 tables, plus an optional
per-status label so Indonesian copy no longer has to be hand-rolled per
component. GravePlotsTable drops its local match() and consumes the new
family; its shipped colours and labels are unchanged and locked by test."
```

---

## Task 2: The shared dashboard page and its `/admin` subclass

Delivers a working, read-only `/admin/peta-plot`: pick a cemetery, get the Floor/Block Map for a granular one and Quota Cards for an aggregate one, and open a read-only detail modal on any plot cell. No write actions yet.

**Files:**
- Create: `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`
- Create: `app/Filament/Admin/Pages/PlotFloorMap.php`
- Create: `resources/views/filament/shared/plot-floor-map.blade.php`
- Create: `resources/views/filament/shared/plot-floor-map/granular.blade.php`
- Create: `resources/views/filament/shared/plot-floor-map/aggregate.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` (the `->pages([...])` array at ~line 230)
- Test: `tests/Feature/Filament/PlotFloorMapPageTest.php`

**Interfaces:**
- Consumes: `StatusIntent::FAMILY_PLOT_STATE`, `StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY`, `StatusIntent::filamentColor()`, `StatusIntent::label()` from Task 1.
- Produces, on `BasePlotFloorMapPage`:
  - `public ?string $cemeteryId` — Livewire-bound, **untrusted**
  - `public ?string $activePlotId` — Livewire-bound, **untrusted**
  - `abstract public function cemeteryOptions(): array` — `array<string, string>`, cemetery id ⇒ name
  - `public function selectedCemetery(): ?Cemetery`
  - `public function trackingMode(): ?string`
  - `public function blocks(): \Illuminate\Database\Eloquent\Collection` — `Collection<int, CemeteryBlock>`, each with `plots` eager-loaded in slot order
  - `public function packages(): \Illuminate\Database\Eloquent\Collection` — `Collection<int, CemeteryPackage>`
  - `public function activePlot(): ?GravePlot`
  - `public function openPlot(string $plotId): void`
  - `public function closePlot(): void`
  - `protected function resolvePlot(string $plotId): ?GravePlot`
  - `protected static ?string $slug = 'peta-plot'` — routes are `filament.admin.pages.peta-plot` and `filament.operator.pages.peta-plot`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/PlotFloorMapPageTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Filament\Admin\Pages\PlotFloorMap as AdminPlotFloorMap;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The Phase D plot availability dashboard — rendering, cemetery-options
 * scoping, and the `plot_tracking_mode` branch.
 *
 * Every fixture is a real row against real Postgres: blocks and their
 * plots are generated by the shipped `CreateCemeteryBlock` action rather
 * than hand-inserted, so the test exercises the same slot numbering and
 * default `available` state the live admin surfaces produce.
 */
final class PlotFloorMapPageTest extends TestCase
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

    private function granularCemeteryWithBlock(User $actor, int $capacity = 3): Cemetery
    {
        $cemetery = Cemetery::factory()->create([
            'plot_tracking_mode' => PlotTrackingMode::GRANULAR,
        ]);

        app(CreateCemeteryBlock::class)($cemetery, 'BLOK-A', 'Blok A', $capacity, $actor->id, 'admin');

        return $cemetery;
    }

    private function aggregateCemeteryWithPackage(): Cemetery
    {
        $cemetery = Cemetery::factory()->create([
            'plot_tracking_mode' => PlotTrackingMode::AGGREGATE,
        ]);

        CemeteryPackage::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'name' => 'Paket Utama',
            'availability_status' => CemeteryPackageAvailabilityStatus::LIMITED,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        return $cemetery;
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_back_office_roles_can_access_the_admin_page(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(AdminPlotFloorMap::canAccess(), "role {$role}");
            $this->forgetResolvedActorContext();
        }
    }

    public function test_a_vendor_cannot_access_the_admin_page(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);

        $this->assertFalse(AdminPlotFloorMap::canAccess());

        Livewire::actingAs($user)->test(AdminPlotFloorMap::class)->assertForbidden();
    }

    // -----------------------------------------------------------------
    // Cemetery options — /admin sees everything
    // -----------------------------------------------------------------

    public function test_the_admin_page_offers_every_cemetery(): void
    {
        $actor = $this->admin();
        $granular = $this->granularCemeteryWithBlock($actor);
        $aggregate = $this->aggregateCemeteryWithPackage();

        $options = (new AdminPlotFloorMap)->cemeteryOptions();

        $this->assertArrayHasKey((string) $granular->getKey(), $options);
        $this->assertArrayHasKey((string) $aggregate->getKey(), $options);
    }

    // -----------------------------------------------------------------
    // The plot_tracking_mode branch — the phase's core requirement
    // -----------------------------------------------------------------

    public function test_a_granular_cemetery_renders_the_floor_block_map(): void
    {
        $actor = $this->admin();
        $cemetery = $this->granularCemeteryWithBlock($actor);

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->assertSee('BLOK-A')
            ->assertSee('001')
            ->assertSee('Tersedia')
            ->assertDontSee('Kuota per paket');
    }

    public function test_an_aggregate_cemetery_renders_quota_cards_from_the_same_page(): void
    {
        $actor = $this->admin();
        $cemetery = $this->aggregateCemeteryWithPackage();

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->assertSee('Kuota per paket')
            ->assertSee('Paket Utama')
            ->assertSee('Terbatas')
            ->assertDontSee('BLOK-A');
    }

    public function test_switching_the_selected_cemetery_switches_the_rendered_view(): void
    {
        $actor = $this->admin();
        $granular = $this->granularCemeteryWithBlock($actor);
        $aggregate = $this->aggregateCemeteryWithPackage();

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $granular->getKey())
            ->assertSee('BLOK-A')
            ->set('cemeteryId', (string) $aggregate->getKey())
            ->assertSee('Kuota per paket')
            ->assertDontSee('BLOK-A');
    }

    public function test_no_selection_renders_the_prompt_and_no_map(): void
    {
        $actor = $this->admin();
        $this->granularCemeteryWithBlock($actor);
        $this->aggregateCemeteryWithPackage();

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->assertSee('Pilih makam untuk melihat ketersediaan plot.')
            ->assertDontSee('BLOK-A');
    }

    public function test_a_single_available_cemetery_is_preselected_on_mount(): void
    {
        $actor = $this->admin();
        $cemetery = $this->granularCemeteryWithBlock($actor);

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->assertSet('cemeteryId', (string) $cemetery->getKey())
            ->assertSee('BLOK-A');
    }

    public function test_a_granular_cemetery_with_no_blocks_renders_an_honest_empty_state(): void
    {
        $this->admin();
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        Livewire::test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->assertSee('belum memiliki blok');
    }

    // -----------------------------------------------------------------
    // Untrusted-input safety (correction 7: uuid columns on real Postgres)
    // -----------------------------------------------------------------

    public function test_a_malformed_cemetery_id_renders_the_prompt_instead_of_erroring(): void
    {
        $actor = $this->admin();
        $this->granularCemeteryWithBlock($actor);

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', 'not-a-uuid')
            ->assertOk()
            ->assertSee('Pilih makam untuk melihat ketersediaan plot.');
    }

    public function test_a_malformed_plot_id_on_open_plot_resolves_to_nothing_instead_of_erroring(): void
    {
        $actor = $this->admin();
        $cemetery = $this->granularCemeteryWithBlock($actor);

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', 'not-a-uuid')
            ->assertOk()
            ->assertSet('activePlotId', null);
    }

    public function test_a_plot_from_another_cemetery_is_not_addressable(): void
    {
        $actor = $this->admin();
        $own = $this->granularCemeteryWithBlock($actor);
        $other = $this->granularCemeteryWithBlock($actor);

        $foreignPlot = CemeteryBlock::query()
            ->where('cemetery_id', $other->getKey())
            ->firstOrFail()
            ->plots()
            ->firstOrFail();

        Livewire::actingAs($actor)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $own->getKey())
            ->call('openPlot', (string) $foreignPlot->getKey())
            ->assertSet('activePlotId', null);
    }

    public function test_the_page_is_registered_on_the_admin_panel(): void
    {
        $this->assertContains(
            AdminPlotFloorMap::class,
            \Filament\Facades\Filament::getPanel('admin')->getPages(),
        );
    }
}
```

Note on `granularCemeteryWithBlock()` being called twice in the foreign-plot test: `CreateCemeteryBlock` normalises the block code and enforces uniqueness **per cemetery**, so `'BLOK-A'` in two different cemeteries is legal.

- [ ] **Step 2: Run the test to verify it fails**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: FAIL with `Class "App\Filament\Admin\Pages\PlotFloorMap" not found`.

- [ ] **Step 3: Write the abstract base page**

Create `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Shared\PlotFloorMap;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The Plot Availability dashboard — ONE page per back-office panel, not
 * two nav items. The selected cemetery's `plot_tracking_mode` decides what
 * it renders: a granular cemetery gets the Floor/Block Map (real
 * `CemeteryBlock` + `GravePlot` inventory), an aggregate cemetery gets
 * read-only Quota Cards over `cemetery_packages.availability_status`.
 * `PlotTrackingMode`'s own doc block is why the branch is on the column
 * and not on "does this cemetery happen to have blocks": the mode is a
 * business classification an admin sets deliberately, and a granular
 * cemetery with zero blocks yet is a real, honest state that must render
 * as "no blocks", never as a quota view.
 *
 * ---------------------------------------------------------------------------
 * The ONE difference between the two panel subclasses
 * ---------------------------------------------------------------------------
 * `cemeteryOptions()`. `/admin` returns every cemetery; `/operator`
 * returns `CurrentCemeteryScope::grantedCemeteryOptions()`. Nothing else
 * differs, and nothing else may be allowed to differ — because that method
 * is not merely the select's data source, it is this page's **entire
 * server-side authorization seam**:
 *
 *   - `selectedCemetery()` re-checks `$cemeteryId` against it on EVERY
 *     read, so a wire call that sets the property to a cemetery the actor
 *     may not see resolves to null and the page renders the "pick a
 *     cemetery" prompt rather than another operator's inventory.
 *   - `resolvePlot()` re-derives the plot set from `selectedCemetery()`,
 *     so no plot outside the granted cemetery is ever addressable.
 *   - every action added in later tasks routes through `resolvePlot()`.
 *
 * A public Livewire property is client-writable on every request; "the
 * select only offered three cemeteries" is a render fact, not a security
 * property. Do not add a second scoping path — narrow
 * `cemeteryOptions()` instead.
 *
 * ---------------------------------------------------------------------------
 * UUID guards are load-bearing, not defensive noise
 * ---------------------------------------------------------------------------
 * `cemeteries.id`, `grave_plots.id` and `orders.id` are Postgres `uuid`
 * columns. Comparing one against arbitrary client text raises
 * `invalid input syntax for type uuid` — a 500, not a null result. SQLite
 * silently returns no rows for the same query, so a green SQLite run
 * proves nothing here. Every client-supplied id is `Str::isUuid()`-guarded
 * before it reaches a query.
 */
abstract class BasePlotFloorMapPage extends Page
{
    protected static ?string $slug = 'peta-plot';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMap;

    protected string $view = 'filament.shared.plot-floor-map';

    /**
     * The selected cemetery's id. Livewire-bound (`wire:model.live`) and
     * therefore UNTRUSTED — see `selectedCemetery()`.
     */
    public ?string $cemeteryId = null;

    /**
     * The plot whose detail modal is open, or null. Also untrusted; see
     * `resolvePlot()`.
     */
    public ?string $activePlotId = null;

    /**
     * The cemeteries this panel's actor may work with, `id => name`. The
     * single authorization seam — see the class doc block.
     *
     * @return array<string, string>
     */
    abstract public function cemeteryOptions(): array;

    public static function getNavigationLabel(): string
    {
        return 'Peta Ketersediaan Plot';
    }

    public function getTitle(): string
    {
        return 'Peta Ketersediaan Plot';
    }

    /**
     * Seeds the selection from `?cemetery_id=` when it names a cemetery
     * this actor may see, otherwise from the "exactly one option" rule.
     *
     * The one-option rule deliberately reproduces
     * `CurrentCemeteryScope::defaultCemeteryId()`'s semantics without
     * calling it: expressed against `cemeteryOptions()` it is correct for
     * BOTH panels (an operator with one grant, and an admin of a
     * single-cemetery deployment) and it keeps the subclasses to exactly
     * one overridden method, which is the invariant the class doc block
     * depends on.
     */
    public function mount(): void
    {
        $options = $this->cemeteryOptions();

        $requested = request()->query('cemetery_id');

        if (is_string($requested) && array_key_exists($requested, $options)) {
            $this->cemeteryId = $requested;

            return;
        }

        $this->cemeteryId = count($options) === 1
            ? (string) array_key_first($options)
            : null;
    }

    /**
     * The selected cemetery, or null when nothing valid is selected. The
     * `array_key_exists` check against `cemeteryOptions()` is the
     * authorization re-check; the `Str::isUuid()` guard keeps malformed
     * client text away from a `uuid` column.
     */
    public function selectedCemetery(): ?Cemetery
    {
        if (! is_string($this->cemeteryId) || ! Str::isUuid($this->cemeteryId)) {
            return null;
        }

        if (! array_key_exists($this->cemeteryId, $this->cemeteryOptions())) {
            return null;
        }

        return Cemetery::query()->find($this->cemeteryId);
    }

    public function trackingMode(): ?string
    {
        return $this->selectedCemetery()?->plot_tracking_mode;
    }

    /**
     * The granular arm's data: every block of the selected cemetery with
     * its plots eager-loaded in slot order.
     *
     * `slot` is a zero-padded string ('001', '002', ...) written by
     * `CreateCemeteryBlock`, so a plain string `orderBy` IS slot order —
     * the same ordering `GravePlotsTable` uses.
     *
     * Returns empty for an aggregate cemetery so the Blade view can never
     * render a map for a cemetery that has no per-plot truth, even if a
     * future edit reaches this method from the wrong branch.
     *
     * @return Collection<int, CemeteryBlock>
     */
    public function blocks(): Collection
    {
        $cemetery = $this->selectedCemetery();

        if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
            return new Collection;
        }

        return CemeteryBlock::query()
            ->where('cemetery_id', $cemetery->getKey())
            ->with(['plots' => fn (Builder $query): Builder => $query->orderBy('slot')])
            ->orderBy('code')
            ->get();
    }

    /**
     * The aggregate arm's data: the selected cemetery's ACTIVE packages
     * and classes. Inactive rows are excluded because a quota card is a
     * statement about what an enquirer can currently ask for.
     *
     * Read-only by construction — this phase adds no write path here.
     * Editing a package's availability stays with the shipped
     * `PackagesRelationManager` under `CemeteryResource`.
     *
     * @return Collection<int, CemeteryPackage>
     */
    public function packages(): Collection
    {
        $cemetery = $this->selectedCemetery();

        if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::AGGREGATE) {
            return new Collection;
        }

        return CemeteryPackage::query()
            ->where('cemetery_id', $cemetery->getKey())
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();
    }

    /**
     * Opens the plot detail modal. Stores the id only when it resolves to
     * a plot of the currently selected cemetery, so `activePlot()` can
     * never be tricked into loading a foreign plot on a later request.
     */
    public function openPlot(string $plotId): void
    {
        $this->activePlotId = $this->resolvePlot($plotId) === null ? null : $plotId;
    }

    public function closePlot(): void
    {
        $this->activePlotId = null;
    }

    public function activePlot(): ?GravePlot
    {
        return $this->activePlotId === null ? null : $this->resolvePlot($this->activePlotId);
    }

    /**
     * The single server-side re-resolution of a client-supplied plot id. A
     * plot is addressable only when it sits in a block of the CURRENTLY
     * SELECTED cemetery, and the selection is itself re-validated against
     * `cemeteryOptions()` on every call. Every read and every action in
     * this page goes through here; nothing may query `grave_plots` by a
     * client id directly.
     */
    protected function resolvePlot(string $plotId): ?GravePlot
    {
        if (! Str::isUuid($plotId)) {
            return null;
        }

        $cemetery = $this->selectedCemetery();

        if ($cemetery === null) {
            return null;
        }

        return GravePlot::query()
            ->with(['block', 'cemeteryPackage'])
            ->whereIn(
                'block_id',
                CemeteryBlock::query()->where('cemetery_id', $cemetery->getKey())->select('id'),
            )
            ->whereKey($plotId)
            ->first();
    }
}
```

- [ ] **Step 4: Write the `/admin` subclass**

Create `app/Filament/Admin/Pages/PlotFloorMap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;

/**
 * `/admin` Plot Availability dashboard. One of exactly two subclasses of
 * `BasePlotFloorMapPage`, differing from the `/operator` one in
 * `cemeteryOptions()` alone.
 *
 * Access is the shared four-back-office-role master-data gate — the same
 * `canAccess()` `GravePlotsResource` and `FeatureGateAdmin` carry, so this
 * page can never be visible to a role that the plot resource family
 * already denies.
 *
 * Options are UNSCOPED on purpose: an `/admin` actor works across the
 * whole directory, and the roadmap names cemetery-options scoping as the
 * single intended difference between the two panels.
 */
final class PlotFloorMap extends BasePlotFloorMapPage
{
    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string, string>
     */
    public function cemeteryOptions(): array
    {
        /** @var array<string, string> $options */
        $options = Cemetery::query()->orderBy('name')->pluck('name', 'id')->all();

        return $options;
    }
}
```

- [ ] **Step 5: Write the page shell view**

Create `resources/views/filament/shared/plot-floor-map.blade.php`:

```blade
{{--
    resources/views/filament/shared/plot-floor-map.blade.php

    View for App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage and both
    of its panel subclasses. ONE view for both panels: they render
    identically and differ only in which cemeteries `cemeteryOptions()`
    offers.

    Component choice follows feature-gate-admin.blade.php: Filament's own
    Blade components plus the utility vocabulary that page already ships.
    Neither panel carries a custom theme CSS file (vite.config.js, 26 Aug
    2026), so the page renders against Filament's precompiled stylesheet —
    introducing a new Tailwind utility family here risks a class that was
    never compiled.

    Required states (design-system.md §6): loading (none — every arm is one
    query over a small per-cemetery set), empty (no cemeteries available /
    no cemetery selected / granular cemetery with no blocks / block with no
    plots / aggregate cemetery with no packages), success (the map or the
    cards themselves), error (the honest "unrecognised tracking mode" arm),
    support (the legend explaining what each colour means).
--}}
@php
    $trackingModeGranular = \App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR;
    $trackingModeAggregate = \App\Domain\CemeteryDirectory\PlotTrackingMode::AGGREGATE;

    $cemeteryOptions = $this->cemeteryOptions();
    $selectedCemetery = $this->selectedCemetery();
    $trackingMode = $this->trackingMode();
@endphp

<x-filament-panels::page>
    <div class="grid gap-y-6">
        <div class="grid gap-y-1.5">
            <label for="plot-floor-map-cemetery" class="text-sm font-medium text-neutral-800">
                Makam
            </label>
            <select
                id="plot-floor-map-cemetery"
                wire:model.live="cemeteryId"
                class="fi-input w-full max-w-md"
            >
                <option value="">— Pilih makam —</option>
                @foreach ($cemeteryOptions as $optionId => $optionName)
                    <option value="{{ $optionId }}">{{ $optionName }}</option>
                @endforeach
            </select>

            @if ($cemeteryOptions === [])
                <p class="text-sm text-neutral-600">
                    Belum ada makam yang dapat Anda akses. Hubungi admin untuk meminta akses makam.
                </p>
            @endif
        </div>

        @if ($selectedCemetery === null)
            <p class="text-sm text-neutral-600">
                Pilih makam untuk melihat ketersediaan plot.
            </p>
        @elseif ($trackingMode === $trackingModeGranular)
            @include('filament.shared.plot-floor-map.granular')
        @elseif ($trackingMode === $trackingModeAggregate)
            @include('filament.shared.plot-floor-map.aggregate')
        @else
            {{--
                Unreachable while `Cemetery::booted()` guards the column, but
                guessing an arm would silently show an operator the wrong
                availability truth. Say so instead.
            --}}
            <p class="text-sm text-neutral-600">
                Mode pelacakan plot makam ini tidak dikenali. Hubungi admin sebelum menggunakan data ketersediaan.
            </p>
        @endif
    </div>
</x-filament-panels::page>
```

- [ ] **Step 6: Write the granular (Floor/Block Map) partial**

Create `resources/views/filament/shared/plot-floor-map/granular.blade.php`:

```blade
{{--
    The Floor/Block Map — one section per CemeteryBlock, its plots as cells
    in slot order. Cell colour comes from StatusIntent::FAMILY_PLOT_STATE
    (design-system.md §3.7): components must not switch on enum strings,
    so there is no `match` anywhere in this file.

    Cells are real <button> elements (x-filament::button), not coloured
    divs, so they are keyboard-reachable and screen-reader-announced —
    design-system.md §7.4. §7.5 ("beyond colour") is satisfied by the
    always-visible legend below the blocks and by the accessible name on
    each cell, which states the slot AND its status in words.
--}}
@php
    use App\Domain\PlotInventory\PlotState;
    use App\Support\Design\StatusIntent;

    $blocks = $this->blocks();
    $activePlot = $this->activePlot();
@endphp

<div class="grid gap-y-6">
    @forelse ($blocks as $block)
        <x-filament::section>
            <x-slot name="heading">{{ $block->code }} — {{ $block->name }}</x-slot>
            <x-slot name="description">
                Kapasitas {{ $block->capacity }} · {{ $block->plots->count() }} plot
            </x-slot>

            @if ($block->plots->isEmpty())
                <p class="text-sm text-neutral-600">Blok ini belum memiliki plot.</p>
            @else
                <div class="flex flex-wrap gap-2">
                    @foreach ($block->plots as $plot)
                        <x-filament::button
                            size="sm"
                            :color="StatusIntent::filamentColor($plot->plot_state, StatusIntent::FAMILY_PLOT_STATE)"
                            :aria-label="'Plot ' . $block->code . ' ' . $plot->slot . ' — ' . StatusIntent::label($plot->plot_state, StatusIntent::FAMILY_PLOT_STATE)"
                            wire:click="openPlot('{{ $plot->getKey() }}')"
                            x-on:click="$dispatch('open-modal', { id: 'plot-floor-map-cell' })"
                        >
                            {{ $plot->slot }}
                        </x-filament::button>
                    @endforeach
                </div>
            @endif
        </x-filament::section>
    @empty
        <p class="text-sm text-neutral-600">
            Makam ini belum memiliki blok. Tambahkan blok melalui halaman Makam terlebih dahulu.
        </p>
    @endforelse

    <div class="flex flex-wrap items-center gap-2">
        <span class="text-sm text-neutral-600">Keterangan:</span>
        @foreach (PlotState::KNOWN_STATES as $legendState)
            <x-filament::badge :color="StatusIntent::filamentColor($legendState, StatusIntent::FAMILY_PLOT_STATE)">
                {{ StatusIntent::label($legendState, StatusIntent::FAMILY_PLOT_STATE) }}
            </x-filament::badge>
        @endforeach
    </div>

    <x-filament::modal id="plot-floor-map-cell" width="md">
        <x-slot name="heading">
            @if ($activePlot !== null)
                Plot {{ $activePlot->block?->code }} — {{ $activePlot->slot }}
            @else
                Plot
            @endif
        </x-slot>

        @if ($activePlot === null)
            <p class="text-sm text-neutral-600">Plot tidak ditemukan pada makam yang dipilih.</p>
        @else
            <div class="grid gap-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-sm text-neutral-600">Status:</span>
                    <x-filament::badge :color="StatusIntent::filamentColor($activePlot->plot_state, StatusIntent::FAMILY_PLOT_STATE)">
                        {{ StatusIntent::label($activePlot->plot_state, StatusIntent::FAMILY_PLOT_STATE) }}
                    </x-filament::badge>
                </div>
                <p class="text-sm text-neutral-600">
                    Paket / Kelas: {{ $activePlot->cemeteryPackage?->name ?? 'Tanpa paket' }}
                </p>
            </div>
        @endif

        <x-slot name="footer">
            <x-filament::button
                color="gray"
                wire:click="closePlot"
                x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
            >
                Tutup
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</div>
```

- [ ] **Step 7: Write the aggregate (Quota Cards) partial**

Create `resources/views/filament/shared/plot-floor-map/aggregate.blade.php`:

```blade
{{--
    Quota Cards — the aggregate tier's availability view. READ-ONLY by
    design: an aggregate cemetery has no per-plot truth to act on, and the
    write path for `cemetery_packages.availability_status` stays where it
    already is, the PackagesRelationManager under CemeteryResource. This
    phase adds no second editor for the same column.

    Status colour and label come from
    StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY — never a local
    match(), per design-system.md §3.7/§9.2.
--}}
@php
    use App\Support\Design\StatusIntent;

    $packages = $this->packages();
@endphp

<x-filament::section>
    <x-slot name="heading">Kuota per paket</x-slot>
    <x-slot name="description">
        Ketersediaan tingkat paket/kelas. Angka ini bersifat indikatif, bukan jaminan ketersediaan.
    </x-slot>

    @forelse ($packages as $package)
        <div class="grid gap-y-1.5 border-b border-neutral-200 py-3">
            <div class="flex flex-wrap items-center gap-2">
                <span class="text-sm font-medium text-neutral-800">{{ $package->name }}</span>
                @if ($package->class_label !== null)
                    <span class="text-xs text-neutral-600">{{ $package->class_label }}</span>
                @endif
                <x-filament::badge :color="StatusIntent::filamentColor($package->availability_status, StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY)">
                    {{ StatusIntent::label($package->availability_status, StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY) }}
                </x-filament::badge>
            </div>
            @if ($package->description !== null)
                <p class="text-sm text-neutral-600">{{ $package->description }}</p>
            @endif
        </div>
    @empty
        <p class="text-sm text-neutral-600">
            Makam ini belum memiliki paket atau kelas aktif.
        </p>
    @endforelse
</x-filament::section>
```

- [ ] **Step 8: Register the page on the admin panel**

In `app/Providers/Filament/AdminPanelProvider.php`, add `use App\Filament\Admin\Pages\PlotFloorMap;` to the imports and add `PlotFloorMap::class,` to the `->pages([...])` array (after `InAppNotifications::class,`).

- [ ] **Step 9: Run the test to verify it passes**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: PASS, all 13 tests.

- [ ] **Step 10: Mutation-check the branch test**

A rendering test that would pass with the branch removed proves nothing. Temporarily change the shell view's `@elseif ($trackingMode === $trackingModeGranular)` to `@elseif (true)` and re-run.

Expected: `test_an_aggregate_cemetery_renders_quota_cards_from_the_same_page` FAILS. Revert the edit and re-run to green before moving on. If it still passes, the assertions are vacuous — fix them before continuing.

- [ ] **Step 11: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`.

- [ ] **Step 12: Commit**

```bash
git add app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php \
        app/Filament/Admin/Pages/PlotFloorMap.php \
        resources/views/filament/shared/plot-floor-map.blade.php \
        resources/views/filament/shared/plot-floor-map/ \
        app/Providers/Filament/AdminPanelProvider.php \
        tests/Feature/Filament/PlotFloorMapPageTest.php
git commit -m "feat(admin): plot availability dashboard with tracking-mode branch

One page renders the Floor/Block Map for a granular cemetery and read-only
Quota Cards for an aggregate one, chosen by plot_tracking_mode. The shared
abstract base carries every behaviour; cemeteryOptions() is both the
select's data source and the page's only authorization seam, re-checked on
every read."
```

---

## Task 3: The `/operator` subclass and its cemetery scoping

The authorization-sensitive task. A granted operator must see their cemetery and nothing else, from the same page an admin sees everything on.

**Files:**
- Create: `app/Filament/Operator/Pages/PlotFloorMap.php`
- Modify: `app/Providers/Filament/OperatorPanelProvider.php`
- Test: `tests/Feature/Filament/PlotFloorMapPageTest.php` (append)

**Interfaces:**
- Consumes: `BasePlotFloorMapPage` (Task 2), `CurrentCemeteryScope::grantedCemeteryOptions(): array<string, string>`, `CurrentCemeteryScope::hasAnyGrant(): bool`.
- Produces: `App\Filament\Operator\Pages\PlotFloorMap` with the same public surface as the admin subclass.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/PlotFloorMapPageTest.php`. Add these imports at the top of the file:

```php
use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Filament\Operator\Pages\PlotFloorMap as OperatorPlotFloorMap;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
```

and these methods to the class:

```php
    /**
     * A real cemetery-operator: the `cemetery_operator` role grant that
     * lets them through `CemeteryOperatorPanelAccessPolicy`, plus a real
     * `scope_assignments` row per granted cemetery. No fabricated
     * ActorContext — the whole chain is exercised.
     *
     * @param  list<string>  $cemeteryIds
     */
    private function actingAsCemeteryOperator(array $cemeteryIds): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);

        foreach ($cemeteryIds as $cemeteryId) {
            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::CEMETERY,
                'entity_id' => $cemeteryId,
            ]);
        }

        $this->actingAs($user);
        $this->forgetResolvedActorContext();
        $this->app->forgetScopedInstances();

        return $user;
    }

    // -----------------------------------------------------------------
    // The verification bar: /admin sees all, /operator sees only granted
    // -----------------------------------------------------------------

    public function test_an_operator_sees_only_the_cemetery_they_are_granted(): void
    {
        $setupActor = $this->admin();
        $own = $this->granularCemeteryWithBlock($setupActor);
        $other = $this->granularCemeteryWithBlock($setupActor);

        $this->actingAsCemeteryOperator([(string) $own->getKey()]);

        $options = (new OperatorPlotFloorMap)->cemeteryOptions();

        $this->assertArrayHasKey((string) $own->getKey(), $options);
        $this->assertArrayNotHasKey((string) $other->getKey(), $options);
    }

    public function test_the_admin_page_sees_both_cemeteries_the_operator_page_narrows(): void
    {
        $setupActor = $this->admin();
        $own = $this->granularCemeteryWithBlock($setupActor);
        $other = $this->granularCemeteryWithBlock($setupActor);

        $adminOptions = (new AdminPlotFloorMap)->cemeteryOptions();
        $this->assertArrayHasKey((string) $own->getKey(), $adminOptions);
        $this->assertArrayHasKey((string) $other->getKey(), $adminOptions);

        $this->actingAsCemeteryOperator([(string) $own->getKey()]);
        $this->assertCount(1, (new OperatorPlotFloorMap)->cemeteryOptions());
    }

    /**
     * The property is client-writable; the select's option list is a
     * render fact, not a security property. Setting `cemeteryId` over the
     * wire to a cemetery the operator holds no grant for must resolve to
     * nothing — not to that cemetery's inventory.
     */
    public function test_an_operator_cannot_wire_their_way_into_an_ungranted_cemetery(): void
    {
        $setupActor = $this->admin();
        $own = $this->granularCemeteryWithBlock($setupActor);
        $other = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        app(CreateCemeteryBlock::class)($other, 'BLOK-Z', 'Blok Z', 2, $setupActor->id, 'admin');

        $operator = $this->actingAsCemeteryOperator([(string) $own->getKey()]);

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->set('cemeteryId', (string) $other->getKey())
            ->assertOk()
            ->assertSee('Pilih makam untuk melihat ketersediaan plot.')
            ->assertDontSee('BLOK-Z');
    }

    public function test_an_operator_with_no_grant_cannot_access_the_operator_page(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(app(CurrentCemeteryScope::class)->hasAnyGrant());
        $this->assertFalse(OperatorPlotFloorMap::canAccess());
    }

    public function test_a_guest_cannot_access_the_operator_page(): void
    {
        $this->assertFalse(OperatorPlotFloorMap::canAccess());
    }

    public function test_the_operator_page_renders_the_granted_cemeterys_map(): void
    {
        $setupActor = $this->admin();
        $own = $this->granularCemeteryWithBlock($setupActor);

        $operator = $this->actingAsCemeteryOperator([(string) $own->getKey()]);

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->assertSet('cemeteryId', (string) $own->getKey())
            ->assertSee('BLOK-A')
            ->assertSee('001');
    }

    public function test_the_operator_page_renders_quota_cards_for_an_aggregate_cemetery(): void
    {
        $aggregate = $this->aggregateCemeteryWithPackage();

        $operator = $this->actingAsCemeteryOperator([(string) $aggregate->getKey()]);

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->assertSee('Kuota per paket')
            ->assertSee('Paket Utama')
            ->assertSee('Terbatas');
    }

    /**
     * The roadmap's stated invariant: the two subclasses differ in
     * cemetery-options scoping and nothing else. A second overridden
     * method is a second place scoping could drift, so the shape itself is
     * asserted, not just its current behaviour.
     */
    public function test_the_two_subclasses_override_only_the_options_source_and_access_gate(): void
    {
        $allowed = ['cemeteryOptions', 'canAccess'];

        foreach ([AdminPlotFloorMap::class, OperatorPlotFloorMap::class] as $subclass) {
            $declared = array_map(
                static fn (\ReflectionMethod $method): string => $method->getName(),
                (new \ReflectionClass($subclass))->getMethods(),
            );

            $ownMethods = array_values(array_filter(
                $declared,
                static fn (string $name): bool => (new \ReflectionMethod($subclass, $name))
                    ->getDeclaringClass()->getName() === $subclass,
            ));

            sort($ownMethods);
            $expected = $allowed;
            sort($expected);

            $this->assertSame($expected, $ownMethods, "{$subclass} declares an unexpected method.");
        }
    }

    public function test_the_page_is_registered_on_the_operator_panel(): void
    {
        $this->assertContains(
            OperatorPlotFloorMap::class,
            \Filament\Facades\Filament::getPanel('operator')->getPages(),
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: FAIL with `Class "App\Filament\Operator\Pages\PlotFloorMap" not found`.

- [ ] **Step 3: Write the `/operator` subclass**

Create `app/Filament/Operator/Pages/PlotFloorMap.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Domain\CemeteryDirectory\Access\CurrentCemeteryScope;
use App\Filament\Shared\PlotFloorMap\BasePlotFloorMapPage;

/**
 * `/operator` Plot Availability dashboard — the cemetery-scoped twin of
 * `App\Filament\Admin\Pages\PlotFloorMap`, differing from it in
 * `cemeteryOptions()` and the access gate alone.
 *
 * ---------------------------------------------------------------------------
 * Two gates, doing two different jobs
 * ---------------------------------------------------------------------------
 * PANEL ENTRY is already decided before this class is reached:
 * `CemeteryOperatorPanelAccessPolicy` (via `User::canAccessPanel()`)
 * admits only `ActorRole::CEMETERY_OPERATOR`. `canAccess()` below is the
 * narrower PAGE gate — an actor inside the panel with zero cemetery grants
 * has nothing this page could truthfully show them, so it is hidden rather
 * than rendered empty. This is AC4's "SHALL NOT grant record access on
 * panel membership alone" expressed at the page boundary, and it is the
 * first production consumer of `CurrentCemeteryScope::hasAnyGrant()`,
 * which shipped in Phase A with only test callers.
 *
 * RECORD VISIBILITY is `cemeteryOptions()` plus the base page's re-check of
 * `$cemeteryId` against it on every read. The base class's doc block
 * explains why that one method carries the whole seam; do not add a second
 * scoping path here.
 *
 * ---------------------------------------------------------------------------
 * Writes are deliberately NOT widened to `cemetery_operator`
 * ---------------------------------------------------------------------------
 * The map's write actions are gated by
 * `MasterDataAdminAuthorizerContract`, whose admission list is
 * [admin, restricted_admin, operator, finance] — `cemetery_operator` is
 * not on it. A bare cemetery-operator therefore gets a complete, correct,
 * READ-ONLY map here. Admitting them to plot state changes is a real
 * authorization decision needing product sign-off and human review, and is
 * out of Phase D's read-mostly scope — the same deliberate incompleteness
 * Phase A already recorded on `ReservePlotAction::ALLOWED_ROLES`.
 */
final class PlotFloorMap extends BasePlotFloorMapPage
{
    public static function canAccess(): bool
    {
        return app(CurrentCemeteryScope::class)->hasAnyGrant();
    }

    /**
     * @return array<string, string>
     */
    public function cemeteryOptions(): array
    {
        return app(CurrentCemeteryScope::class)->grantedCemeteryOptions();
    }
}
```

- [ ] **Step 4: Register the page on the operator panel**

In `app/Providers/Filament/OperatorPanelProvider.php`, add `use App\Filament\Operator\Pages\PlotFloorMap;` to the imports and add `PlotFloorMap::class,` to the `->pages([...])` array after `Dashboard::class,`.

Update that provider's class doc block: the sentence "Ships only a placeholder Dashboard page and no discoverable Resources — later phases (C: orders dashboard, D: plot availability) add real Resources/Pages here." is now partly stale. Replace it with:

```
 * Ships the placeholder Dashboard plus, from Phase D (28 Aug 2026), the
 * cemetery-scoped `PlotFloorMap` page. No Resources yet — Phase C's
 * `CemeteryOrderResource` is the first. Pages are listed explicitly rather
 * than discovered, for the same unconfirmed-discovery-behaviour reason
 * `AdminPanelProvider`'s doc block records.
```

- [ ] **Step 5: Run the tests to verify they pass**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: PASS, all 22 tests.

- [ ] **Step 6: Mutation-check the scoping test**

Temporarily change the operator subclass's `cemeteryOptions()` to `return (new \App\Filament\Admin\Pages\PlotFloorMap)->cemeteryOptions();` and re-run.

Expected: `test_an_operator_sees_only_the_cemetery_they_are_granted` and `test_an_operator_cannot_wire_their_way_into_an_ungranted_cemetery` both FAIL. Revert and return to green. If they still pass, the scoping assertions are vacuous — fix them before continuing.

- [ ] **Step 7: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`.

- [ ] **Step 8: Commit**

```bash
git add app/Filament/Operator/Pages/PlotFloorMap.php \
        app/Providers/Filament/OperatorPanelProvider.php \
        tests/Feature/Filament/PlotFloorMapPageTest.php
git commit -m "feat(operator): cemetery-scoped plot availability dashboard

The /operator twin of the admin page, overriding cemeteryOptions() and the
page access gate only. A wire call naming an ungranted cemetery resolves to
nothing rather than that cemetery's inventory; an actor with no grant does
not get the page at all."
```

---

## Task 4: Standalone entry mode — the three audited state overrides

Gives the map its first write path: the same three overrides `GravePlotsResource` already ships, reached from a plot cell. The shared from-state guard and audit write move into one class so a third copy of a documented security control is never created.

**Files:**
- Create: `app/Filament/Shared/PlotInventory/PlotStateOverrides.php`
- Modify: `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php` (delete its private `overrideFromStates()` and `overrideState()`, delegate instead)
- Modify: `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`
- Modify: `resources/views/filament/shared/plot-floor-map/granular.blade.php`
- Test: `tests/Feature/Filament/PlotFloorMapActionsTest.php` (new)

**Interfaces:**
- Consumes: `BasePlotFloorMapPage::resolvePlot()`, `::activePlot()` (Task 2).
- Produces:
  - `PlotStateOverrides::fromStates(string $toState): array` — `list<string>`
  - `PlotStateOverrides::apply(GravePlot $record, string $toState, string $successTitle, string $actorRole): bool` — returns whether a write happened
  - `BasePlotFloorMapPage::actorMayWrite(): bool`
  - `BasePlotFloorMapPage::markPlotState(string $toState): void` — zero-record-argument Livewire action, reads `$activePlotId`
  - `BasePlotFloorMapPage::availableOverrides(): array` — `array<string, string>` target state ⇒ Indonesian button label, for the currently active plot
  - `BasePlotFloorMapPage::requireFreshAuthentication(): bool` — **concrete on the base, never overridden.** Its return URL is `static::getUrl()`, which Filament resolves against whichever panel the concrete subclass is registered in, so the two subclasses need no per-panel override and Task 3's "only two declared members" shape test stays green.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Filament/PlotFloorMapActionsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Pages\PlotFloorMap as AdminPlotFloorMap;
use App\Filament\Operator\Pages\PlotFloorMap as OperatorPlotFloorMap;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The write half of the Phase D plot availability dashboard: the three
 * audited state overrides reached from a plot cell.
 *
 * Every assertion is against real rows — the flipped `grave_plots.plot_state`
 * and the `audit_events` row the write must produce — not against a mocked
 * action. The re-authentication fixture is the one
 * `PlotInventoryAdminTest` established (`actor_sessions
 * .last_authenticated_at`, read by
 * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt`).
 */
final class PlotFloorMapActionsTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): void
    {
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    private function freshAdmin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        return $user;
    }

    /**
     * @return array{0: Cemetery, 1: CemeteryBlock}
     */
    private function granularCemetery(User $actor, int $capacity = 3): array
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-A', 'Blok A', $capacity, $actor->id, 'admin');

        return [$cemetery, $block];
    }

    private function firstPlot(CemeteryBlock $block): GravePlot
    {
        return $block->plots()->orderBy('slot')->firstOrFail();
    }

    // -----------------------------------------------------------------
    // Happy paths
    // -----------------------------------------------------------------

    public function test_marking_a_plot_occupied_flips_the_state_and_writes_an_audit_row(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $this->assertSame(PlotState::AVAILABLE, $plot->plot_state);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED)
            ->assertOk();

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()?->plot_state);

        $this->assertTrue(
            AuditEvent::query()
                ->where('action', PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED)
                ->where('subject_id', (string) $plot->getKey())
                ->exists(),
            'The override must write a GRAVE_PLOT_STATE_CHANGED audit row.',
        );
    }

    public function test_marking_a_plot_under_maintenance_then_available_round_trips(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $component = Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::MAINTENANCE);

        $this->assertSame(PlotState::MAINTENANCE, $plot->fresh()?->plot_state);

        $component->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // The from-state guard (finding I2) — enforced at wire level
    // -----------------------------------------------------------------

    public function test_marking_an_available_plot_available_again_is_refused_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(
            0,
            AuditEvent::query()
                ->where('action', PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED)
                ->where('subject_id', (string) $plot->getKey())
                ->count(),
            'A refused override must write nothing at all, audit row included.',
        );
    }

    public function test_a_reserved_plot_can_never_be_freed_by_the_override(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $plot->update(['plot_state' => PlotState::RESERVED]);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::AVAILABLE);

        $this->assertSame(
            PlotState::RESERVED,
            $plot->fresh()?->plot_state,
            'A reserved plot is owned by its reservation and must never be freed behind it.',
        );
    }

    public function test_an_unknown_target_state_is_refused_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', 'demolished')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // Authorization and freshness
    // -----------------------------------------------------------------

    public function test_a_stale_actor_is_refused_before_any_write(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now()->subYear());

        [$cemetery, $block] = $this->granularCemetery($user);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($user)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(
            PlotState::AVAILABLE,
            $plot->fresh()?->plot_state,
            'A stale actor must be sent to re-authentication, not allowed to write.',
        );
    }

    /**
     * The Phase D ruling: write authorization is NOT widened to
     * `cemetery_operator`. A bare cemetery-operator gets a complete
     * READ-ONLY map — the cell modal offers no override buttons and a
     * direct wire call writes nothing.
     */
    public function test_a_bare_cemetery_operator_gets_a_read_only_map(): void
    {
        $setupAdmin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($setupAdmin);
        $plot = $this->firstPlot($block);

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $operator->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->getKey(),
        ]);
        $this->actingAs($operator);
        $this->seedActorSession($operator, CarbonImmutable::now());
        $this->forgetResolvedActorContext();
        $this->app->forgetScopedInstances();

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->assertSee('BLOK-A')
            ->assertDontSee('Tandai Terisi')
            ->call('openPlot', (string) $plot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_a_plot_outside_the_selected_cemetery_cannot_be_overridden(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery] = $this->granularCemetery($admin);

        $other = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $otherBlock = app(CreateCemeteryBlock::class)($other, 'BLOK-Z', 'Blok Z', 2, $admin->id, 'admin');
        $foreignPlot = $this->firstPlot($otherBlock);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->set('activePlotId', (string) $foreignPlot->getKey())
            ->call('markPlotState', PlotState::OCCUPIED);

        $this->assertSame(PlotState::AVAILABLE, $foreignPlot->fresh()?->plot_state);
    }

    // -----------------------------------------------------------------
    // The shipped table must keep behaving identically
    // -----------------------------------------------------------------

    public function test_the_shared_override_path_reports_the_same_from_states_the_table_documented(): void
    {
        $overrides = \App\Filament\Shared\PlotInventory\PlotStateOverrides::class;

        $this->assertSame(
            [PlotState::MAINTENANCE, PlotState::OCCUPIED],
            $overrides::fromStates(PlotState::AVAILABLE),
        );
        $this->assertSame(
            [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::MAINTENANCE],
            $overrides::fromStates(PlotState::OCCUPIED),
        );
        $this->assertSame(
            [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::OCCUPIED],
            $overrides::fromStates(PlotState::MAINTENANCE),
        );
        $this->assertSame([], $overrides::fromStates('demolished'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php`

Expected: FAIL — `Method markPlotState does not exist` / `Class "App\Filament\Shared\PlotInventory\PlotStateOverrides" not found`.

- [ ] **Step 3: Extract the shared override write path**

Create `app/Filament/Shared/PlotInventory/PlotStateOverrides.php`:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Shared\PlotInventory;

use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Notifications\Notification;
use InvalidArgumentException;

/**
 * The ONE write path for an admin plot-state override, shared by
 * `GravePlotsTable`'s three row actions and by the Phase D Floor/Block
 * Map's three cell actions.
 *
 * It exists because the from-state rule below is a security control, not a
 * cosmetic one (finding I2 on the P3 admin lane): Filament's `mountAction`
 * re-checks authorization and disabled state but NOT visibility, and a
 * Livewire method is addressable over the wire regardless of what was
 * rendered — so "the button was not drawn" is never enough, and the rule
 * must be re-asserted against a fresh re-read at write time. Two surfaces
 * now offer the same three overrides; a second hand-maintained copy of
 * that rule is exactly how the two would eventually disagree, and the
 * disagreement would free a plot behind an active reservation.
 *
 * Deliberately NOT in `app/Domain/` — it sends Filament notifications and
 * is therefore presentation, not domain. The domain invariants it leans on
 * (`PlotState::assertKnown()` in `GravePlot::booted()`, and the
 * `Audit::wrap` transaction) stay where they are.
 *
 * This class does NOT check authorization or re-authentication freshness.
 * Each calling surface owns those, because each has a different return URL
 * for the re-authentication redirect.
 */
final class PlotStateOverrides
{
    /**
     * The allowed from-state set for each override target — the SINGLE
     * source of truth consumed by both surfaces' render-time visibility
     * AND by `apply()`'s run-time re-read, so meaning and enforcement
     * cannot drift:
     * - `available` is only reachable FROM maintenance/occupied — never
     *   from `available` (a no-op) and never from `reserved`, whose claim
     *   belongs to an active reservation.
     * - `occupied` from available/reserved/maintenance.
     * - `maintenance` from any other state.
     *
     * @return list<string>
     */
    public static function fromStates(string $toState): array
    {
        return match ($toState) {
            PlotState::AVAILABLE => [PlotState::MAINTENANCE, PlotState::OCCUPIED],
            PlotState::OCCUPIED => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::MAINTENANCE],
            PlotState::MAINTENANCE => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::OCCUPIED],
            default => [],
        };
    }

    /**
     * Applies one override inside `Audit::wrap` +
     * `GRAVE_PLOT_STATE_CHANGED`, so the row change and its `audit_events`
     * entry commit in one transaction. The model's `saving` guard
     * (`PlotState::assertKnown`) runs inside that same transaction, so an
     * `InvalidArgumentException` rolls BOTH back and surfaces as a danger
     * notification rather than a 500.
     *
     * `fresh()` is re-read BEFORE the write and the from-state rule
     * re-asserted against it: a wire call against a view that has since
     * gone stale — `markAvailable` on a plot another actor just reserved —
     * is refused with no write.
     *
     * Returns whether a write actually happened, so a caller can decide
     * what to do next (this phase's page uses it to close its modal only
     * on success).
     */
    public static function apply(
        GravePlot $record,
        string $toState,
        string $successTitle,
        string $actorRole,
    ): bool {
        $fresh = $record->fresh() ?? $record;
        $fromState = $fresh->plot_state;

        if (! in_array($fromState, self::fromStates($toState), true)) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body('Status plot saat ini tidak mengizinkan tindakan ini; tidak ada perubahan yang ditulis.')
                ->danger()
                ->send();

            return false;
        }

        try {
            Audit::wrap(
                fn (): bool => $fresh->update(['plot_state' => $toState]),
                action: PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED,
                subject: new AuditSubject('grave_plot', (string) $fresh->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: app(ActorContext::class)->identityReference,
                actorRole: $actorRole,
                source: AuditSource::Panel,
                reason: sprintf('Admin state override: plot %s → %s.', $fromState, $toState),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return false;
        }

        Notification::make()->title($successTitle)->success()->send();

        return true;
    }
}
```

- [ ] **Step 4: Point `GravePlotsTable` at the extracted class**

In `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php`:

1. Add `use App\Filament\Shared\PlotInventory\PlotStateOverrides;` to the imports.
2. Delete the private `overrideFromStates()` method and the private `overrideState()` method entirely, plus the now-unused imports they alone needed: `PlotInventoryAuditActions`, `Audit`, `AuditOutcome`, `AuditSubject`, `AuditSource`, `InvalidArgumentException`. Keep `ActorContext`, `Notification`, `AuditSource` **only if** something else in the file still uses them — after this edit `AuditSource` is unused, remove it; `ActorContext` and `Notification` are still used by `actorMayManage()` and `requireFreshAuthentication()`, keep them.
3. Replace each `->visible(fn (GravePlot $record): bool => in_array($record->plot_state, self::overrideFromStates(PlotState::X), true))` with `PlotStateOverrides::fromStates(PlotState::X)` (three occurrences).
4. Replace each `self::overrideState($record, PlotState::X, '...')` inside the three `->action()` closures with:

```php
                        PlotStateOverrides::apply(
                            $record,
                            PlotState::OCCUPIED,
                            'Plot ditandai terisi.',
                            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
                        );
```

(and correspondingly `PlotState::MAINTENANCE` / `'Plot ditandai perawatan.'` and `PlotState::AVAILABLE` / `'Plot ditandai tersedia.'`).

5. Update the class doc block: the paragraph beginning "Each action's allowed from-state set is declared once in `overrideFromStates()`…" now reads:

```
 * Each action's allowed from-state set is declared once in
 * `App\Filament\Shared\PlotInventory\PlotStateOverrides::fromStates()` and
 * used BOTH by the `->visible()` closures here and by that class's
 * run-time re-read, so render-time meaning and wire-call enforcement
 * cannot drift (finding I2). The rule moved out of this file on 28 Aug
 * 2026 when the Phase D Floor/Block Map became a second surface offering
 * the same three overrides; two hand-maintained copies of a security
 * control is how the two would eventually disagree.
```

Layer 4 in the "Authorization: three layers" block should likewise point at `PlotStateOverrides::apply()` instead of `overrideState()`.

- [ ] **Step 5: Add the write gate and the override action to the base page**

Add these imports to `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`:

```php
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use App\Filament\Shared\PlotInventory\PlotStateOverrides;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Notifications\Notification;
```

and these methods:

```php
    /**
     * May the CURRENT actor change plot state from this page?
     *
     * The same `MasterDataAdminAuthorizerContract` gate `GravePlotsTable`
     * carries, evaluated identically on BOTH panels — this phase does not
     * widen write authorization to `ActorRole::CEMETERY_OPERATOR`, which
     * is not on that authorizer's four-role list. A bare cemetery-operator
     * therefore gets a complete, correct, read-only map; see the
     * `/operator` subclass's doc block for why widening it is a separate,
     * sign-off-requiring decision.
     *
     * A null selection is also a "no": there is nothing to write to.
     */
    public function actorMayWrite(): bool
    {
        if ($this->selectedCemetery() === null) {
            return false;
        }

        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * The override targets that are meaningful for the currently open plot,
     * as `target state => Indonesian button label`. Render-time meaning
     * only — `PlotStateOverrides::apply()` re-asserts the same rule against
     * a fresh re-read, because a hidden button is still wire-addressable.
     *
     * @return array<string, string>
     */
    public function availableOverrides(): array
    {
        $plot = $this->activePlot();

        if ($plot === null || ! $this->actorMayWrite()) {
            return [];
        }

        $labels = [
            PlotState::OCCUPIED => 'Tandai Terisi',
            PlotState::MAINTENANCE => 'Tandai Perawatan',
            PlotState::AVAILABLE => 'Tandai Tersedia',
        ];

        return array_filter(
            $labels,
            fn (string $label, string $toState): bool => in_array(
                $plot->plot_state,
                PlotStateOverrides::fromStates($toState),
                true,
            ),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * The ZERO-RECORD-ARGUMENT override action: the target state comes from
     * the clicked button, the RECORD comes from `$activePlotId` re-resolved
     * server-side through `resolvePlot()`. A wire call naming a plot in
     * another cemetery — or in a cemetery this actor holds no grant for —
     * resolves to null and writes nothing.
     *
     * Order of checks is deliberate and mirrors `GravePlotsTable`'s:
     * 1. the actor gate, before anything else touches the record;
     * 2. the plot re-resolution (scope enforcement);
     * 3. recent re-authentication — AGENTS.md requires it for plot-override
     *    actions, and a stale actor is routed to the challenge page with
     *    `url.intended` pointing back at THIS page's own URL, so a
     *    satisfied challenge returns here rather than to the plots table;
     * 4. `PlotStateOverrides::apply()`, which owns the from-state rule and
     *    the audited transactional write.
     */
    public function markPlotState(string $toState): void
    {
        if (! $this->actorMayWrite()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah status plot.')->send();

            return;
        }

        $plot = $this->activePlot();

        if ($plot === null) {
            Notification::make()->danger()->title('Plot tidak ditemukan pada makam yang dipilih.')->send();

            return;
        }

        if (! $this->requireFreshAuthentication()) {
            return;
        }

        $titles = [
            PlotState::OCCUPIED => 'Plot ditandai terisi.',
            PlotState::MAINTENANCE => 'Plot ditandai perawatan.',
            PlotState::AVAILABLE => 'Plot ditandai tersedia.',
        ];

        $successTitle = $titles[$toState] ?? null;

        if ($successTitle === null) {
            Notification::make()->danger()->title('Status tujuan tidak dikenali.')->send();

            return;
        }

        if (PlotStateOverrides::apply(
            $plot,
            $toState,
            $successTitle,
            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
        )) {
            $this->closePlot();
        }
    }

    /**
     * The wire-level re-authentication enforcement, same shape as
     * `GravePlotsTable::requireFreshAuthentication()` but returning to THIS
     * page. `static::getUrl()` resolves against whichever panel the
     * concrete subclass is registered in, so no subclass override is
     * needed and the two subclasses stay identical apart from
     * `cemeteryOptions()`.
     */
    protected function requireFreshAuthentication(): bool
    {
        try {
            app(ReauthenticationGuard::class)->assertFresh(app(ActorContext::class));

            return true;
        } catch (ReauthenticationRequiredException) {
            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'plot_override');
            session()->put('url.intended', static::getUrl());
            redirect()->route(PasswordReauthentication::ROUTE_NAME);

            return false;
        }
    }
```

Note on `GravePlotsResource::auditRoleFor()`: reusing it, rather than adding a third copy of the identical role walk, is deliberate. The map writes the **same** audit action (`GRAVE_PLOT_STATE_CHANGED`) against the same subject type as the table, so the `actor_role` on the two surfaces' rows must be derived identically or the audit trail becomes two vocabularies for one action. Add that sentence as a comment above the two call sites.

- [ ] **Step 6: Add the override buttons to the cell modal**

In `resources/views/filament/shared/plot-floor-map/granular.blade.php`, inside the `<x-filament::modal>`'s `<x-slot name="footer">`, **before** the existing Tutup button, add:

```blade
            @foreach ($this->availableOverrides() as $overrideState => $overrideLabel)
                <x-filament::button
                    color="primary"
                    wire:click="markPlotState('{{ $overrideState }}')"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    {{ $overrideLabel }}
                </x-filament::button>
            @endforeach
```

- [ ] **Step 7: Run the tests to verify they pass**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php tests/Feature/Filament/PlotFloorMapPageTest.php tests/Feature/Filament/PlotInventoryAdminTest.php`

Expected: PASS across all three. `PlotInventoryAdminTest` must stay green with **zero edits** — it is the proof the shipped table's behaviour survived the extraction.

- [ ] **Step 8: Mutation-check the from-state guard**

Temporarily change `PlotStateOverrides::fromStates(PlotState::AVAILABLE)` to return `PlotState::KNOWN_STATES` and re-run.

Expected: `test_a_reserved_plot_can_never_be_freed_by_the_override` and `test_marking_an_available_plot_available_again_is_refused_without_a_write` FAIL, and `PlotInventoryAdminTest`'s equivalent coverage fails too. Revert and return to green.

- [ ] **Step 9: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`.

- [ ] **Step 10: Commit**

```bash
git add app/Filament/Shared/PlotInventory/PlotStateOverrides.php \
        app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php \
        app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php \
        resources/views/filament/shared/plot-floor-map/granular.blade.php \
        tests/Feature/Filament/PlotFloorMapActionsTest.php
git commit -m "feat(plot-map): audited state overrides from a plot cell

Extracts the from-state guard and the audited write into one shared
PlotStateOverrides class so the shipped GravePlotsTable and the new floor
map cannot drift into two versions of a security control, then wires the
map's cell modal to it under the same master-data gate and the same
recent-re-authentication requirement."
```

---

## Task 5: Order-linked entry mode — reserve an available cell for an order

**Files:**
- Modify: `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`
- Modify: `resources/views/filament/shared/plot-floor-map.blade.php`
- Modify: `resources/views/filament/shared/plot-floor-map/granular.blade.php`
- Test: `tests/Feature/Filament/PlotFloorMapActionsTest.php` (append)

**Interfaces:**
- Consumes: `resolvePlot()`, `actorMayWrite()`, `requireFreshAuthentication()` (Tasks 2, 4); `App\Domain\PlotReservation\Actions\ReservePlot::__invoke(GravePlot $plot, Order $order, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation` — **unmodified**.
- Produces:
  - `public ?string $orderId` on the base page
  - `BasePlotFloorMapPage::linkedOrder(): ?Order`
  - `BasePlotFloorMapPage::reserveForOrder(): void` — zero-argument Livewire action

**Design ruling — why only this mode calls `ReservePlot`.** `ReservePlot::__invoke()` hard-type-hints `Order $order`. In standalone mode there is no order, and inventing one would be fabricating a booking. The roadmap places the pre-order "hold" mechanism in Phase E (`HoldPlotForDraft`, a new nullable `booking_draft_id` on `plot_reservations`, a new expiry scheduler). **This phase's standalone map is therefore read plus state-override only. Only the `?order_id=` mode reserves.** Do not add a "reserve without an order" button.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/PlotFloorMapActionsTest.php`. Add these imports:

```php
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
```

and these methods:

```php
    /**
     * A real order anchored to `$cemetery` through its booking draft —
     * the same `bookingDraft.cemetery_id` path `ReservePlotAction` reads.
     */
    private function orderForCemetery(Cemetery $cemetery): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->getKey(),
        ]);

        return Order::query()->create([
            'reference' => 'ORD-'.fake()->unique()->numerify('######'),
            'booking_draft_id' => $draft->getKey(),
        ]);
    }

    // -----------------------------------------------------------------
    // Order-linked entry mode
    // -----------------------------------------------------------------

    public function test_the_order_linked_mode_offers_and_performs_a_reservation(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $order = $this->orderForCemetery($cemetery);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => (string) $order->getKey()])
            ->test(AdminPlotFloorMap::class)
            ->assertSet('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertSee('Reservasi untuk pesanan #'.$order->reference)
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::RESERVED, $plot->fresh()?->plot_state);

        $reservation = PlotReservation::activeForOrder($order->fresh());
        $this->assertNotNull($reservation);
        $this->assertSame(PlotReservationState::HELD, $reservation->state);
        $this->assertSame((string) $plot->getKey(), (string) $reservation->plot_id);
    }

    public function test_without_an_order_id_the_reservation_offer_is_absent_and_the_call_writes_nothing(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertDontSee('Reservasi untuk pesanan')
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_a_malformed_order_id_is_ignored_instead_of_erroring(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => 'not-a-uuid'])
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    /**
     * The order is only ever addressable through the SELECTED cemetery,
     * which is itself scoped. An order belonging to another cemetery
     * resolves to null, so an operator can never reserve one cemetery's
     * plot for another cemetery's order.
     */
    public function test_an_order_from_another_cemetery_does_not_resolve(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        $otherCemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        $foreignOrder = $this->orderForCemetery($otherCemetery);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->set('orderId', (string) $foreignOrder->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder');

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }

    public function test_reserving_an_unavailable_plot_surfaces_the_domain_refusal_without_a_write(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $plot->update(['plot_state' => PlotState::OCCUPIED]);
        $order = $this->orderForCemetery($cemetery);

        Livewire::actingAs($admin)
            ->withQueryParams(['order_id' => (string) $order->getKey()])
            ->test(AdminPlotFloorMap::class)
            ->call('openPlot', (string) $plot->getKey())
            ->call('reserveForOrder')
            ->assertOk();

        $this->assertSame(PlotState::OCCUPIED, $plot->fresh()?->plot_state);
        $this->assertSame(0, PlotReservation::query()->count());
    }
```

If `BookingDraft::query()->create(['cemetery_id' => ...])` or `Order::query()->create([...])` fails on a NOT NULL column, read the two migrations and add the minimum required columns to the fixture — do **not** loosen a model guard or a migration to make a test pass.

- [ ] **Step 2: Run the tests to verify they fail**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php`

Expected: FAIL — `Method reserveForOrder does not exist`.

- [ ] **Step 3: Add the order-linked mode to the base page**

Add these imports to `BasePlotFloorMapPage`:

```php
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Actions\ReservePlot;
use Throwable;
```

Add the property, next to `$activePlotId`:

```php
    /**
     * The order the page was entered for (`?order_id=`), or null in
     * standalone mode. Untrusted like every other public property; see
     * `linkedOrder()`.
     */
    public ?string $orderId = null;
```

Replace `mount()` in full:

```php
    /**
     * Seeds the selection, in priority order:
     *   1. `?cemetery_id=`, when it names a cemetery this actor may see;
     *   2. the cemetery of `?order_id=`'s booking draft, when that order
     *      resolves AND its cemetery is one this actor may see — the
     *      order-linked entry mode, so an operator following a link from an
     *      order lands on the right map with no second click;
     *   3. the "exactly one option" rule.
     *
     * The order id is stored regardless of whether it resolves; every
     * consumer goes through `linkedOrder()`, which re-validates it against
     * the CURRENT selection on every call. Storing it unvalidated here and
     * validating there means changing cemetery cannot leave a stale order
     * armed.
     */
    public function mount(): void
    {
        $options = $this->cemeteryOptions();

        $requestedOrder = request()->query('order_id');
        $this->orderId = is_string($requestedOrder) && $requestedOrder !== '' ? $requestedOrder : null;

        $requestedCemetery = request()->query('cemetery_id');

        if (is_string($requestedCemetery) && array_key_exists($requestedCemetery, $options)) {
            $this->cemeteryId = $requestedCemetery;

            return;
        }

        if ($this->orderId !== null && Str::isUuid($this->orderId)) {
            $orderCemeteryId = Order::query()
                ->with('bookingDraft')
                ->find($this->orderId)
                ?->bookingDraft
                ?->cemetery_id;

            if (is_string($orderCemeteryId) && array_key_exists($orderCemeteryId, $options)) {
                $this->cemeteryId = $orderCemeteryId;

                return;
            }
        }

        $this->cemeteryId = count($options) === 1
            ? (string) array_key_first($options)
            : null;
    }
```

Add these methods:

```php
    /**
     * The order this page is reserving for, or null.
     *
     * The order is deliberately resolved THROUGH the selected cemetery: an
     * order only counts when its booking draft's `cemetery_id` equals the
     * currently selected cemetery, and the selection is itself
     * re-validated against `cemeteryOptions()`. That single condition is
     * what makes it impossible to reserve one cemetery's plot for another
     * cemetery's order, and impossible for an operator to act on an order
     * outside their grants — without adding a second scoping path.
     *
     * `booking_drafts.cemetery_id` is the same route to a cemetery that
     * the shipped `ReservePlotAction` reads, so the two surfaces agree on
     * what "this order's cemetery" means.
     */
    public function linkedOrder(): ?Order
    {
        if (! is_string($this->orderId) || ! Str::isUuid($this->orderId)) {
            return null;
        }

        $cemetery = $this->selectedCemetery();

        if ($cemetery === null) {
            return null;
        }

        $order = Order::query()->with('bookingDraft')->find($this->orderId);

        if ($order === null) {
            return null;
        }

        return (string) $order->bookingDraft?->cemetery_id === (string) $cemetery->getKey()
            ? $order
            : null;
    }

    /**
     * The order-linked entry mode's one write: dispatch the EXISTING,
     * UNMODIFIED `ReservePlot` action for the open cell.
     *
     * This page performs no locking, no availability assert and no state
     * flip of its own — `ReservePlot` owns all of that (order-row lock,
     * then plot-row lock, then the `available` assert against the locked
     * row, then the held row, the plot flip, the audit row and the outbox
     * event, all in one transaction). Every refusal it raises —
     * `PlotNotAvailableException` above all — surfaces here as a danger
     * notification with no state change. Re-implementing any of that check
     * locally would create a second, weaker copy of the module's
     * concurrency discipline.
     */
    public function reserveForOrder(): void
    {
        if (! $this->actorMayWrite()) {
            Notification::make()->danger()->title('Anda tidak berwenang mereservasi plot.')->send();

            return;
        }

        $order = $this->linkedOrder();

        if ($order === null) {
            Notification::make()->danger()->title('Pesanan tidak ditemukan untuk makam yang dipilih.')->send();

            return;
        }

        $plot = $this->activePlot();

        if ($plot === null) {
            Notification::make()->danger()->title('Plot tidak ditemukan pada makam yang dipilih.')->send();

            return;
        }

        if (! $this->requireFreshAuthentication()) {
            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ReservePlot::class)(
                $plot,
                $order,
                (string) $actor->identityReference,
                GravePlotsResource::auditRoleFor($actor),
            );

            Notification::make()->success()->title('Plot berhasil direservasi.')->send();
            $this->closePlot();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Reservasi gagal')->body($exception->getMessage())->send();
        }
    }

    /**
     * Whether the open cell may be offered to the linked order — the
     * render-time condition only. `reserveForOrder()` re-derives all of it
     * server-side, because a hidden button is still wire-addressable.
     */
    public function mayReserveActivePlot(): bool
    {
        $plot = $this->activePlot();

        return $plot !== null
            && $plot->plot_state === PlotState::AVAILABLE
            && $this->linkedOrder() !== null
            && $this->actorMayWrite();
    }
```

- [ ] **Step 4: Surface the linked order in the page shell**

In `resources/views/filament/shared/plot-floor-map.blade.php`, add `$linkedOrder = $this->linkedOrder();` to the top `@php` block and, immediately after the cemetery-select `<div>`, add:

```blade
        @if ($linkedOrder !== null)
            <p class="text-sm text-neutral-600">
                Mode reservasi pesanan: pilih plot yang tersedia untuk pesanan
                <span class="font-mono">#{{ $linkedOrder->reference }}</span>.
            </p>
        @endif
```

- [ ] **Step 5: Add the reserve button to the cell modal**

In `resources/views/filament/shared/plot-floor-map/granular.blade.php`, inside the modal footer, **before** the override buttons added in Task 4:

```blade
            @if ($this->mayReserveActivePlot())
                <x-filament::button
                    color="success"
                    wire:click="reserveForOrder"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    Reservasi untuk pesanan #{{ $this->linkedOrder()?->reference }}
                </x-filament::button>
            @endif
```

- [ ] **Step 6: Run the tests to verify they pass**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: PASS.

- [ ] **Step 7: Verify the domain action was not modified**

```bash
git diff --stat origin/docs/design-system-and-planning -- app/Domain/
```

Expected: **empty**. If `app/Domain/` appears at all, revert it — this phase consumes those actions and does not change them.

- [ ] **Step 8: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php \
        resources/views/filament/shared/plot-floor-map.blade.php \
        resources/views/filament/shared/plot-floor-map/granular.blade.php \
        tests/Feature/Filament/PlotFloorMapActionsTest.php
git commit -m "feat(plot-map): order-linked entry mode via ?order_id=

An available cell offers 'Reservasi untuk pesanan #REF' and dispatches the
existing, unmodified ReservePlot action. The order resolves only through
the selected cemetery's booking draft, so one cemetery's plot can never be
reserved for another cemetery's order."
```

---

## Task 6: Reservation lifecycle actions on a reserved cell

**Files:**
- Modify: `app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php`
- Modify: `resources/views/filament/shared/plot-floor-map/granular.blade.php`
- Test: `tests/Feature/Filament/PlotFloorMapActionsTest.php` (append)

**Interfaces:**
- Consumes: `PlotReservation::activeForPlot(GravePlot $plot): ?PlotReservation`; and, all **unmodified**, `ConfirmPlotReservation`, `ReleasePlotReservation`, `ExpirePlotReservation`, each `__invoke(PlotReservation $reservation, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): PlotReservation`.
- Produces:
  - `BasePlotFloorMapPage::activeReservation(): ?PlotReservation`
  - `BasePlotFloorMapPage::availableReservationActions(): array` — `array<string, string>` action key ⇒ Indonesian label, keys `'confirm' | 'release' | 'expire'`
  - `BasePlotFloorMapPage::runReservationAction(string $action): void`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/Filament/PlotFloorMapActionsTest.php`:

```php
    // -----------------------------------------------------------------
    // Reservation lifecycle on a reserved cell
    // -----------------------------------------------------------------

    /**
     * @return array{0: Cemetery, 1: GravePlot, 2: Order}
     */
    private function reservedPlot(User $admin): array
    {
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);
        $order = $this->orderForCemetery($cemetery);

        app(\App\Domain\PlotReservation\Actions\ReservePlot::class)(
            $plot,
            $order,
            (string) $admin->id,
            'admin',
        );

        return [$cemetery, $plot->fresh(), $order];
    }

    public function test_a_held_reservation_can_be_confirmed_from_the_map(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot, $order] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertSee('Konfirmasi Reservasi')
            ->call('runReservationAction', 'confirm')
            ->assertOk();

        $this->assertSame(
            PlotReservationState::CONFIRMED,
            PlotReservation::activeForPlot($plot)?->state,
        );
        $this->assertSame(
            PlotState::RESERVED,
            $plot->fresh()?->plot_state,
            'Confirming does not free the plot — a confirmed reservation is still the claim.',
        );
    }

    public function test_a_held_reservation_can_be_released_and_the_plot_returns_to_available(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'release')
            ->assertOk();

        $this->assertNull(PlotReservation::activeForPlot($plot));
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_a_held_reservation_can_be_expired(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'expire')
            ->assertOk();

        $this->assertNull(PlotReservation::activeForPlot($plot));
        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_expire_is_not_offered_once_a_reservation_is_confirmed(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        app(\App\Domain\PlotReservation\Actions\ConfirmPlotReservation::class)(
            PlotReservation::activeForPlot($plot),
            (string) $admin->id,
            'admin',
        );

        $component = Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey());

        $component->assertSee('Lepaskan Reservasi')
            ->assertDontSee('Kedaluwarsakan Reservasi')
            ->assertDontSee('Konfirmasi Reservasi');

        // And the wire call is refused too, not just hidden.
        $component->call('runReservationAction', 'expire');

        $this->assertSame(
            PlotReservationState::CONFIRMED,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }

    public function test_an_available_plot_offers_no_reservation_actions(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $block] = $this->granularCemetery($admin);
        $plot = $this->firstPlot($block);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->assertDontSee('Konfirmasi Reservasi')
            ->assertDontSee('Lepaskan Reservasi')
            ->call('runReservationAction', 'release')
            ->assertOk();

        $this->assertSame(PlotState::AVAILABLE, $plot->fresh()?->plot_state);
    }

    public function test_an_unknown_reservation_action_key_writes_nothing(): void
    {
        $admin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($admin);

        Livewire::actingAs($admin)
            ->test(AdminPlotFloorMap::class)
            ->set('cemeteryId', (string) $cemetery->getKey())
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'obliterate')
            ->assertOk();

        $this->assertSame(
            PlotReservationState::HELD,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }

    public function test_a_bare_cemetery_operator_cannot_run_a_reservation_action(): void
    {
        $setupAdmin = $this->freshAdmin();
        [$cemetery, $plot] = $this->reservedPlot($setupAdmin);

        $operator = User::factory()->create();
        $this->grantRoleTo($operator, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $operator->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->getKey(),
        ]);
        $this->actingAs($operator);
        $this->seedActorSession($operator, CarbonImmutable::now());
        $this->forgetResolvedActorContext();
        $this->app->forgetScopedInstances();

        Livewire::actingAs($operator)
            ->test(OperatorPlotFloorMap::class)
            ->call('openPlot', (string) $plot->getKey())
            ->call('runReservationAction', 'release');

        $this->assertSame(
            PlotReservationState::HELD,
            PlotReservation::activeForPlot($plot)?->state,
        );
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php`

Expected: FAIL — `Method runReservationAction does not exist`.

- [ ] **Step 3: Add the lifecycle actions to the base page**

Add these imports to `BasePlotFloorMapPage`:

```php
use App\Domain\PlotReservation\Actions\ConfirmPlotReservation;
use App\Domain\PlotReservation\Actions\ExpirePlotReservation;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
```

and these methods:

```php
    /**
     * The open cell's incumbent reservation, or null. `activeForPlot()` is
     * the module's own head-row resolver (latest row of the plot's
     * append-only chain, counted only when `held` or `confirmed`) — this
     * page must not re-derive that from the chain itself, or it would
     * become a second, drifting definition of "active".
     */
    public function activeReservation(): ?PlotReservation
    {
        $plot = $this->activePlot();

        return $plot === null ? null : PlotReservation::activeForPlot($plot);
    }

    /**
     * The lifecycle transitions that are meaningful for the open cell's
     * incumbent reservation, as `action key => Indonesian label`. Mirrors
     * the shipped `PlotReservationLifecycleActions` on the order view:
     * confirm and expire from `held` only, release from `held` or
     * `confirmed`.
     *
     * Render-time meaning only. `runReservationAction()` re-derives the
     * same map server-side before dispatching, because a hidden button is
     * still wire-addressable.
     *
     * @return array<string, string>
     */
    public function availableReservationActions(): array
    {
        $reservation = $this->activeReservation();

        if ($reservation === null || ! $this->actorMayWrite()) {
            return [];
        }

        return match ($reservation->state) {
            PlotReservationState::HELD => [
                'confirm' => 'Konfirmasi Reservasi',
                'release' => 'Lepaskan Reservasi',
                'expire' => 'Kedaluwarsakan Reservasi',
            ],
            PlotReservationState::CONFIRMED => [
                'release' => 'Lepaskan Reservasi',
            ],
            default => [],
        };
    }

    /**
     * Dispatches one EXISTING, UNMODIFIED lifecycle action against the open
     * cell's incumbent reservation.
     *
     * This page contributes no locking and no state assert of its own: each
     * of the three actions takes the plot-row lock FIRST, re-reads the head
     * of the plot's reservation chain under it, and throws
     * `PlotReservationTransitionException` when the head is not in the
     * allowed from-state. The `availableReservationActions()` re-check here
     * is a courtesy that produces a readable refusal for the common stale
     * click; the domain action is the correctness boundary, and a race the
     * re-check cannot see is refused there instead.
     */
    public function runReservationAction(string $action): void
    {
        if (! $this->actorMayWrite()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengubah reservasi.')->send();

            return;
        }

        $reservation = $this->activeReservation();

        if ($reservation === null || ! array_key_exists($action, $this->availableReservationActions())) {
            Notification::make()->danger()->title('Tindakan reservasi ini tidak tersedia untuk plot tersebut.')->send();

            return;
        }

        if (! $this->requireFreshAuthentication()) {
            return;
        }

        $actor = app(ActorContext::class);
        $actorRole = GravePlotsResource::auditRoleFor($actor);
        $actorReference = (string) $actor->identityReference;

        try {
            match ($action) {
                'confirm' => app(ConfirmPlotReservation::class)($reservation, $actorReference, $actorRole),
                'release' => app(ReleasePlotReservation::class)($reservation, $actorReference, $actorRole),
                'expire' => app(ExpirePlotReservation::class)($reservation, $actorReference, $actorRole),
            };

            Notification::make()->success()->title('Reservasi diperbarui.')->send();
            $this->closePlot();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Tindakan reservasi gagal')->body($exception->getMessage())->send();
        }
    }
```

- [ ] **Step 4: Add the lifecycle buttons to the cell modal**

In `resources/views/filament/shared/plot-floor-map/granular.blade.php`, inside the modal footer, after the reserve button and before the override buttons:

```blade
            @foreach ($this->availableReservationActions() as $reservationAction => $reservationLabel)
                <x-filament::button
                    color="gray"
                    wire:click="runReservationAction('{{ $reservationAction }}')"
                    x-on:click="$dispatch('close-modal', { id: 'plot-floor-map-cell' })"
                >
                    {{ $reservationLabel }}
                </x-filament::button>
            @endforeach
```

Also surface the reservation state inside the modal body, after the existing status badge block:

```blade
                @php $modalReservation = $this->activeReservation(); @endphp
                @if ($modalReservation !== null)
                    <p class="text-sm text-neutral-600">
                        Reservasi: {{ $modalReservation->state }} ·
                        {{ $modalReservation->reserved_at?->format('Y-m-d H:i') ?? '—' }}
                    </p>
                @endif
```

- [ ] **Step 5: Run the tests to verify they pass**

`RUN-TESTS tests/Feature/Filament/PlotFloorMapActionsTest.php tests/Feature/Filament/PlotFloorMapPageTest.php`

Expected: PASS.

- [ ] **Step 6: Mutation-check the from-state map**

Temporarily change `availableReservationActions()`'s `PlotReservationState::CONFIRMED` arm to return all three keys and re-run.

Expected: `test_expire_is_not_offered_once_a_reservation_is_confirmed` FAILS on the `assertDontSee`. Revert and return to green.

- [ ] **Step 7: Confirm `app/Domain/` is still untouched**

```bash
git diff --stat origin/docs/design-system-and-planning -- app/Domain/
```

Expected: empty.

- [ ] **Step 8: Run the gates**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php \
        resources/views/filament/shared/plot-floor-map/granular.blade.php \
        tests/Feature/Filament/PlotFloorMapActionsTest.php
git commit -m "feat(plot-map): reservation lifecycle actions on a reserved cell

Confirm/release/expire dispatch the existing, unmodified PlotReservation
actions against the plot's incumbent reservation. The page adds no locking
and no state assert of its own; the domain actions stay the correctness
boundary."
```

---

## Task 7: Documentation, whole-branch verification, and the review handoff

**Files:**
- Modify: `docs/product/screen-inventory.md`
- Modify: `app/Filament/Admin/Resources/GravePlots/GravePlotsResource.php` (doc block cross-reference only)

- [ ] **Step 1: Add the two screen-inventory rows**

`ADM-242` is the current highest id. In `docs/product/screen-inventory.md`, add a revision note in the same style as the existing ones (immediately after the most recent note, around line 84):

```markdown
**Phase D of the TPU/TPS operator dashboard roadmap shipped 28 Aug 2026** as ADM-250 (admin) and ADM-251 (operator). New rows are minted rather than folded into ADM-180 because this is a new page with a new route (`/admin/peta-plot`, `/operator/peta-plot`), not an extension of `GravePlotsResource`'s table — that resource is unchanged and still lists plots for every cemetery regardless of tier. The tracking-tier branch (`cemeteries.plot_tracking_mode`, Phase B) is read server-side on every render, never a front-end flag. Write authorization is deliberately NOT widened to `ActorRole::CEMETERY_OPERATOR` in this phase: the state-override and reservation actions stay behind `MasterDataAdminAuthorizerContract`'s four back-office roles, so a bare cemetery-operator gets a complete read-only map. Admitting that role to plot writes is a separate product decision. The 16 Aug P5a note above is left verbatim.
```

Then append the two rows at the end of the ADM table:

```markdown
| ADM-250 | **Peta Ketersediaan Plot — shipped 28 Aug 2026 (Phase D: `App\Filament\Admin\Pages\PlotFloorMap`).** One page, two views chosen by the selected cemetery's `plot_tracking_mode`. **Granular:** Floor/Block Map — a section per `CemeteryBlock` with its plots as colour-coded cells in slot order (colour and Indonesian label from `StatusIntent::FAMILY_PLOT_STATE`, the same mapping ADM-180's table now consumes), an always-visible legend, and a per-cell modal offering the three audited state overrides ('Tandai Terisi'/'Tandai Perawatan'/'Tandai Tersedia', shared with ADM-180 through `PlotStateOverrides`, recent re-authentication required, `GRAVE_PLOT_STATE_CHANGED` audited), the reservation lifecycle actions ('Konfirmasi'/'Lepaskan'/'Kedaluwarsakan'), and — in order-linked mode only (`?order_id=`) — 'Reservasi untuk pesanan #REF' on an available cell. **Aggregate:** read-only Quota Cards, one per active `CemeteryPackage`, mapping `availability_status` to Tersedia/Terbatas/Penuh via `StatusIntent::FAMILY_CEMETERY_PACKAGE_AVAILABILITY`; editing stays with `PackagesRelationManager`. Every reservation flows through the existing, unmodified domain actions. Access: `MasterDataAdminAuthorizerContract` (four back-office roles); cemetery options unscoped |
| ADM-251 | **Peta Ketersediaan Plot (operator) — shipped 28 Aug 2026 (Phase D: `App\Filament\Operator\Pages\PlotFloorMap`).** The `/operator` twin of ADM-250, rendered from the same shared base page and the same Blade view, differing in exactly two members: `cemeteryOptions()` returns `CurrentCemeteryScope::grantedCemeteryOptions()` instead of every cemetery, and `canAccess()` requires at least one active cemetery grant (`hasAnyGrant()` — its first production consumer). Options are not merely the select's data source: the base page re-checks the selected cemetery against them on every read, so a wire call naming an ungranted cemetery resolves to nothing rather than that cemetery's inventory, and no plot outside the grant is addressable. Write actions are gated identically to ADM-250, which means a bare `cemetery_operator` gets a complete READ-ONLY map — see the 28 Aug revision note |
```

- [ ] **Step 2: Cross-reference the new page from `GravePlotsResource`**

In `app/Filament/Admin/Resources/GravePlots/GravePlotsResource.php`, add to the class doc block, after the first paragraph:

```
 * Since 28 Aug 2026 this is no longer the only plot surface: Phase D's
 * `App\Filament\Admin\Pages\PlotFloorMap` renders the same inventory as a
 * block-grouped map for granular-tier cemeteries and offers the same three
 * state overrides through the shared
 * `App\Filament\Shared\PlotInventory\PlotStateOverrides`. This resource is
 * unchanged and deliberately kept: it is the only surface that lists plots
 * across ALL cemeteries at once, with search and filters, which the
 * per-cemetery map does not do.
```

- [ ] **Step 3: Run the whole suite, not just this branch's tests**

`RUN-TESTS tests/`

Expected: the full suite passes. If a failure predates this branch, prove it by checking out the merge-base into a scratch worktree and re-running that single test there — do not assume, and do not report a pre-existing failure as caused by this work. Record any genuine pre-existing failure in the PR body as `NOT CAUSED BY THIS BRANCH` with the evidence.

- [ ] **Step 4: Run every gate one final time**

`RUN-PINT`, `RUN-PHPSTAN`, `RUN-DOCS`. All three must pass, and the output must be pasted into the PR body — `AGENTS.md` forbids reporting `PASS` for a check that was not executed.

- [ ] **Step 5: Verify the two rendered pages by hand**

Automated tests assert the rendered HTML contains the right text; they do not prove it *looks* right. Both panels render against Filament's precompiled stylesheet with no custom theme, so a utility class that was never compiled would produce a broken layout that every test still passes (see the "Blade class vocabulary" ruling in Global Constraints).

Load `/admin/peta-plot` for a granular cemetery and an aggregate one, and `/operator/peta-plot` as a granted cemetery-operator. Confirm: cells are laid out as a wrapping grid rather than stacked full-width, the colour legend reads correctly, the modal opens and closes, and the quota cards are separated. If this cannot be done in this environment, report it explicitly as `NOT TESTED — no browser access`, never as `PASS`.

- [ ] **Step 6: Commit and open the PR**

```bash
git add docs/product/screen-inventory.md \
        app/Filament/Admin/Resources/GravePlots/GravePlotsResource.php
git commit -m "docs: record the Phase D plot availability dashboard

Mints ADM-250/ADM-251 and records the deliberate non-widening of write
authorization to cemetery_operator."
```

Open the PR with a body that states, prominently:

- **Human review is mandatory before merge** (`AGENTS.md` §Infrastructure-agent execution). This branch changes cemetery-scoped visibility, adds two audited write surfaces, and refactors a live admin page's security control into a shared class.
- The two follow-up decisions this phase deliberately did not make: (a) whether `ActorRole::CEMETERY_OPERATOR` should be admitted to plot state changes and reservation actions; (b) the pre-order plot hold, which Phase E owns (`ReservePlot` hard-requires an `Order`, so a standalone reserve is impossible today).
- The pasted output of `pint --test`, `phpstan analyse`, `verify-docs.sh` and the full test run.
- The result of Step 5's manual render check, honestly labelled.

---

## Self-Review

**1. Spec coverage.** Every line of the roadmap's Phase D section maps to a task:

| Spec requirement | Task |
|---|---|
| One page per panel, switching by `plot_tracking_mode`, not two nav items | 2 (branch + admin), 3 (operator) |
| Shared abstract base + two thin subclasses differing only in cemetery-options scoping | 2, 3 (and Task 3's reflection test asserts the shape) |
| Grouped by `CemeteryBlock`, plots as clickable cells in slot order | 2 |
| Colour via Filament's semantic keys | 1 (centralised in `StatusIntent`; the roadmap's `gray` for maintenance is corrected to the shipped `info`, recorded as a ruling) |
| Standalone entry mode | 4 (state overrides) + 6 (lifecycle) |
| Order-linked entry mode, `?order_id=`, "Reservasi untuk pesanan #{reference}" | 5 |
| All four `PlotReservation` actions dispatched **unmodified** | 5, 6; verified by an explicit `git diff app/Domain/` check in both |
| Existing audited state-override actions reused | 4 (extracted to `PlotStateOverrides`, shared with the shipped table) |
| Quota Cards, read-only, per `CemeteryPackage`, Tersedia/Terbatas/Penuh | 1 (mapping) + 2 (view) |
| No new write path for aggregate tier | 2 (`packages()` is read-only; documented in the partial) |
| Verification bar: both dashboards render for both tiers from one nav entry | 2, 3 |
| Verification bar: `/admin` all cemeteries, `/operator` only granted | 3 |
| Verification bar: existing reserve/lifecycle actions still function from both panels | 5, 6, plus `PlotInventoryAdminTest` staying green in 4 |
| Global constraints: strict types, pint, phpstan, verify-docs, real Postgres | Global Constraints + every task's gate step |

**2. Placeholder scan.** No `TBD`, no "similar to Task N", no "add appropriate error handling". Every code step carries the literal code. The one deliberate incompleteness — write authorization not widened to `cemetery_operator` — is a stated ruling with a test that locks the current behaviour, not a gap. Task 1 Step 4 contains a deliberate corrected typo callout; that is an instruction to the implementer, not a placeholder.

**3. Type consistency.** Checked across tasks:

- `cemeteryOptions()` is `array<string, string>` in the abstract declaration (Task 2), the admin override (Task 2), and the operator override (Task 3).
- `resolvePlot()` is `protected` and returns `?GravePlot` in Task 2, and is called by `activePlot()` (Task 2), `markPlotState()` (Task 4), `reserveForOrder()` (Task 5) — always via `activePlot()`, never re-derived.
- `PlotStateOverrides::fromStates()` returns `list<string>` and is called from `GravePlotsTable`'s three `->visible()` closures (Task 4 Step 4), from `availableOverrides()` (Task 4 Step 5), and from `apply()` itself — one name, one signature.
- `PlotStateOverrides::apply()` takes `(GravePlot, string, string, string)` and returns `bool` in its definition, its `GravePlotsTable` call sites, and its `markPlotState()` call site.
- `requireFreshAuthentication()` is `protected` on the base page (Task 4) and is called by `markPlotState()` (Task 4), `reserveForOrder()` (Task 5) and `runReservationAction()` (Task 6).
- `actorMayWrite()` is `public` (the Blade view calls it through `availableOverrides()`/`mayReserveActivePlot()`/`availableReservationActions()`) and is called by all three action methods.
- `StatusIntent::FAMILY_PLOT_STATE` / `FAMILY_CEMETERY_PACKAGE_AVAILABILITY` are spelled identically in Task 1's code, Task 1's tests, and both Blade partials in Task 2.
- Modal id `plot-floor-map-cell` is identical in the granular partial's `<x-filament::modal id=...>` (Task 2) and in every `$dispatch('open-modal'/'close-modal', …)` across Tasks 2, 4, 5 and 6.
- Task 3's reflection test allows exactly `['cemeteryOptions', 'canAccess']` on each subclass; Tasks 4, 5 and 6 add methods only to the **base**, and `requireFreshAuthentication()`'s return URL uses `static::getUrl()` specifically so no subclass override is needed. The test stays green.
