# Cemetery Plot Tracking Mode Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every cemetery an explicit, permanent `plot_tracking_mode` (AGGREGATE or GRANULAR) that later work (the operator dashboard, the customer plot picker) branches on, and enforce it at the one place granular data actually gets created (`CreateCemeteryBlock`).

**Architecture:** A new closed-list class (`PlotTrackingMode`) mirroring the existing `PlotState` class exactly. A new nullable-free string column on `cemeteries`, defaulting to `aggregate` (existing cemeteries have no blocks yet, so this matches reality). A guard added to the existing `CreateCemeteryBlock` action refusing to create a block under an AGGREGATE cemetery. A new audited domain action `SetCemeteryPlotTrackingMode` that flips the mode, refusing `GRANULAR → AGGREGATE` while any block exists for that cemetery.

**Tech Stack:** Laravel 13, PHP 8.5 (`declare(strict_types=1)` everywhere), Eloquent, PostgreSQL 18 (never SQLite for tests — this repo's own established rule, see `docs/operations/local-test-recipe.md`), PHPUnit.

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` (§"Cemetery tracking-tier concept (Phase B)") — this plan implements that section only. Full design reasoning: `/home/ubuntu/.claude/plans/swirling-cooking-umbrella-agent-aplan-tpu-dashboard-364d54444f4bb40d.md` §3.

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);` as its first statement after `<?php`.
- No AWS references anywhere. No hardcoded design/color values (this plan touches zero UI).
- This plan adds a new schema column and a new admin-facing write action — both need human review before merge per `AGENTS.md` §Infrastructure-agent execution (migrations/schema changes, and any change reachable from an admin panel). This does NOT block writing and testing the code — only the final merge decision.
- Composer/npm builds never run on the bare host — only inside the pinned CI image, per `CLAUDE.md`'s Scope note and `docs/operations/local-test-recipe.md`. If a task needs a new Composer package, stop and flag it — do not add one.
- Every test in this plan runs against real Postgres 18 (never SQLite) via the pinned CI image, per `docs/operations/local-test-recipe.md`. No unexecuted `PASS` claims — if a test wasn't actually run, say so.
- Follow this codebase's established closed-list-string-column convention exactly (see `App\Domain\PlotInventory\PlotState`, `App\Domain\CemeteryDirectory\CemeteryType`) — app-layer validation, not a Postgres enum type.
- Follow this codebase's established audited-write-action convention exactly (see `App\Domain\PlotInventory\Actions\CreateCemeteryBlock`): `Audit::wrap()` around the mutation, a dedicated audit-action-name constants class, no authorization check embedded in the Action itself — authorization happens at the Filament call-site (not built in this plan; a later phase wires this action into the admin panel).

---

## Task 1: `PlotTrackingMode` closed-list class

**Files:**
- Create: `app/Domain/CemeteryDirectory/PlotTrackingMode.php`
- Test: `tests/Unit/Domain/CemeteryDirectory/PlotTrackingModeTest.php`

**Interfaces:**
- Produces: `PlotTrackingMode::AGGREGATE` (string `'aggregate'`), `PlotTrackingMode::GRANULAR` (string `'granular'`), `PlotTrackingMode::KNOWN_MODES` (`list<string>`), `PlotTrackingMode::isKnown(string $mode): bool`, `PlotTrackingMode::assertKnown(string $mode): void` (throws `InvalidArgumentException`). Every later task in this plan uses these exact names.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlotTrackingModeTest extends TestCase
{
    public function test_known_modes_are_aggregate_and_granular(): void
    {
        $this->assertSame(['aggregate', 'granular'], PlotTrackingMode::KNOWN_MODES);
        $this->assertSame('aggregate', PlotTrackingMode::AGGREGATE);
        $this->assertSame('granular', PlotTrackingMode::GRANULAR);
    }

    public function test_is_known_recognises_valid_modes(): void
    {
        $this->assertTrue(PlotTrackingMode::isKnown('aggregate'));
        $this->assertTrue(PlotTrackingMode::isKnown('granular'));
        $this->assertFalse(PlotTrackingMode::isKnown('hybrid'));
        $this->assertFalse(PlotTrackingMode::isKnown(''));
    }

    public function test_assert_known_throws_for_unknown_mode(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plot tracking mode [hybrid]. Known modes: aggregate, granular.');
        PlotTrackingMode::assertKnown('hybrid');
    }

    public function test_assert_known_does_not_throw_for_known_mode(): void
    {
        PlotTrackingMode::assertKnown('aggregate');
        PlotTrackingMode::assertKnown('granular');
        $this->addToAssertionCount(2);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (from the worktree root, against the pinned CI image — no database needed, this is a pure Unit test):

```bash
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  vendor/bin/phpunit tests/Unit/Domain/CemeteryDirectory/PlotTrackingModeTest.php
```

Expected: FAIL — `Class "App\Domain\CemeteryDirectory\PlotTrackingMode" not found`.

- [ ] **Step 3: Write minimal implementation**

```php
<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * The closed list of `cemeteries.plot_tracking_mode` values —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept": a cemetery is permanently AGGREGATE (only
 * class-level `cemetery_packages.availability_status` capacity is
 * tracked) or GRANULAR (real per-plot inventory exists — `CemeteryBlock` +
 * `GravePlot` rows). Neither is a transitional state; this is a business
 * classification an admin explicitly sets via
 * `App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode`, not
 * a fact derived from whether blocks happen to exist yet.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — this codebase's established convention for closed-list
 * string columns (`App\Domain\PlotInventory\PlotState`,
 * `App\Domain\CemeteryDirectory\CemeteryType`).
 */
final class PlotTrackingMode
{
    /**
     * Only class-level capacity (`cemetery_packages.availability_status`)
     * is tracked for this cemetery — no individual `GravePlot` rows exist
     * or are expected to. The default for every existing cemetery, which
     * has no blocks today.
     */
    public const string AGGREGATE = 'aggregate';

    /**
     * Real per-plot inventory exists for this cemetery — `CemeteryBlock` +
     * `GravePlot` rows are the authoritative availability record.
     * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` refuses to
     * create a block unless the cemetery is already in this mode.
     */
    public const string GRANULAR = 'granular';

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::AGGREGATE,
        self::GRANULAR,
    ];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN_MODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$mode` is not one of
     *                                  `self::KNOWN_MODES`.
     */
    public static function assertKnown(string $mode): void
    {
        if (! self::isKnown($mode)) {
            throw new InvalidArgumentException(
                "Unknown plot tracking mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

```bash
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  vendor/bin/phpunit tests/Unit/Domain/CemeteryDirectory/PlotTrackingModeTest.php
```

Expected: `OK (4 tests, 6 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CemeteryDirectory/PlotTrackingMode.php tests/Unit/Domain/CemeteryDirectory/PlotTrackingModeTest.php
git commit -m "feat(cemetery-directory): add PlotTrackingMode closed list"
```

---

## Task 2: `cemeteries.plot_tracking_mode` migration + model wiring

**Files:**
- Create: `database/migrations/2026_08_26_150000_add_plot_tracking_mode_to_cemeteries_table.php`
- Modify: `app/Domain/CemeteryDirectory/Models/Cemetery.php` (add `plot_tracking_mode` to `$fillable`)
- Test: `tests/Feature/Domain/CemeteryDirectory/CemeteryPlotTrackingModeColumnTest.php`

**Interfaces:**
- Consumes: nothing from Task 1 directly (the column stores a plain string; validation happens in the Actions that write it, Tasks 3–4).
- Produces: `cemeteries.plot_tracking_mode` column, `NOT NULL DEFAULT 'aggregate'`. `Cemetery::$fillable` includes `'plot_tracking_mode'`. Every later task relies on this column existing and being mass-assignable.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class CemeteryPlotTrackingModeColumnTest extends TestCase
{
    use RefreshDatabase;

    public function test_column_exists_and_defaults_to_aggregate(): void
    {
        $this->assertTrue(Schema::hasColumn('cemeteries', 'plot_tracking_mode'));

        $cemetery = Cemetery::factory()->create();

        $this->assertSame(PlotTrackingMode::AGGREGATE, $cemetery->fresh()->plot_tracking_mode);
    }

    public function test_mode_is_mass_assignable(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $this->assertSame(PlotTrackingMode::GRANULAR, $cemetery->fresh()->plot_tracking_mode);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Start disposable Postgres per `docs/operations/local-test-recipe.md` (use a prefix unique to this task, e.g. `plottrack`):

```bash
docker run -d --name plottrack-pg -e POSTGRES_USER=makam_test -e POSTGRES_PASSWORD=makam_test \
  -e POSTGRES_DB=makam_test -p 15433:5432 postgres:18
until docker exec plottrack-pg pg_isready -U makam_test >/dev/null 2>&1; do sleep 1; done
docker exec plottrack-pg psql -U makam_test -d makam_test \
  -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;' \
  -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'

docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=15433 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/CemeteryPlotTrackingModeColumnTest.php
```

Expected: FAIL — `Schema::hasColumn` assertion fails (column does not exist yet), or a migration error if the model already references the column before the migration exists.

- [ ] **Step 3: Write minimal implementation**

Migration:

```php
<?php

declare(strict_types=1);

use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds `plot_tracking_mode` to `cemeteries` —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept". Defaults every existing and future cemetery to
 * `aggregate` (`App\Domain\CemeteryDirectory\PlotTrackingMode::AGGREGATE`)
 * — no cemetery has any `CemeteryBlock` rows yet, so this matches current
 * reality rather than guessing. A cemetery only becomes `granular` via
 * `App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode`, an
 * explicit admin decision (see that action's own doc block), never
 * inferred from data.
 *
 * Plain `string(16)` column, application-layer validated by
 * `PlotTrackingMode::assertKnown()` — this codebase's established
 * convention for every closed-list string column, not a Postgres enum
 * type (`cemeteries.type`, `grave_plots.plot_state` do the same).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cemeteries', function (Blueprint $table) {
            $table->string('plot_tracking_mode', 16)
                ->default(PlotTrackingMode::AGGREGATE)
                ->after('operator_name');
        });
    }

    public function down(): void
    {
        Schema::table('cemeteries', function (Blueprint $table) {
            $table->dropColumn('plot_tracking_mode');
        });
    }
};
```

Model change — in `app/Domain/CemeteryDirectory/Models/Cemetery.php`, add `'plot_tracking_mode'` to the existing `protected $fillable` array (append after `'operator_name'` to match the migration's column position):

```php
    protected $fillable = [
        'id',
        'type',
        'publication_status',
        'name',
        'slug',
        'city',
        'address',
        'latitude',
        'longitude',
        'google_maps_url',
        'primary_photo_path',
        'facilities',
        'price_min',
        'price_max',
        'price_currency',
        'price_source',
        'price_effective_at',
        'operator_name',
        'plot_tracking_mode',
        'published_at',
        'unpublished_at',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Same `docker run ... vendor/bin/phpunit` command as Step 2.

Expected: `OK (2 tests, 3 assertions)`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_26_150000_add_plot_tracking_mode_to_cemeteries_table.php \
        app/Domain/CemeteryDirectory/Models/Cemetery.php \
        tests/Feature/Domain/CemeteryDirectory/CemeteryPlotTrackingModeColumnTest.php
git commit -m "feat(cemetery-directory): add plot_tracking_mode column to cemeteries"
```

---

## Task 3: `CreateCemeteryBlock` refuses to create a block under an AGGREGATE cemetery

**Files:**
- Modify: `app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php`
- Test: `tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php` (existing file — add new test methods, do not remove existing ones)

**Interfaces:**
- Consumes: `PlotTrackingMode::GRANULAR` (Task 1), `Cemetery::plot_tracking_mode` (Task 2).
- Produces: `CreateCemeteryBlock::__invoke()` now throws `InvalidArgumentException` when `$cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR`. Signature is otherwise unchanged — every existing caller keeps working as long as it passes a `GRANULAR` cemetery.

- [ ] **Step 1: Write the failing test**

Add this method to the existing `tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php` (alongside its existing tests — do not delete any):

```php
    public function test_refuses_to_create_block_for_an_aggregate_tier_cemetery(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Cannot create a block for cemetery [{$cemetery->getKey()}]: it is tracked in 'aggregate' mode. ".
            "Switch it to 'granular' via SetCemeteryPlotTrackingMode first."
        );

        app(CreateCemeteryBlock::class)($cemetery, 'BLOK-E', 'Blok E', 1, 'user:1', 'operator');
    }

    public function test_allows_creating_block_for_a_granular_tier_cemetery(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $block = app(CreateCemeteryBlock::class)($cemetery, 'BLOK-F', 'Blok F', 1, 'user:1', 'operator');

        $this->assertSame('BLOK-F', $block->code);
    }
```

Also add the import at the top of the test file: `use App\Domain\CemeteryDirectory\PlotTrackingMode;`.

**Important — this changes the existing tests' fixtures too**: every existing test in this file calls `$this->cemetery()`, a private helper returning `Cemetery::factory()->create()` with no explicit `plot_tracking_mode` — after this task's guard lands, those calls will start hitting the new refusal (the factory default is `AGGREGATE`, per Task 2). Update the private `cemetery()` helper at the top of the file to create a `GRANULAR` cemetery instead, so the pre-existing tests keep testing what they were testing:

```php
    private function cemetery(): Cemetery
    {
        return Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Reuse the Postgres container from Task 2 (`plottrack-pg`, port `15433`) if still running; otherwise start it again per Task 2 Step 2.

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=15433 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php
```

Expected: FAIL — `test_refuses_to_create_block_for_an_aggregate_tier_cemetery` fails because no exception is thrown yet (the guard doesn't exist).

- [ ] **Step 3: Write minimal implementation**

In `app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php`:

1. Add the import: `use App\Domain\CemeteryDirectory\PlotTrackingMode;`
2. Add the guard as the first check inside `__invoke()`, before the existing capacity check:

```php
    public function __invoke(
        Cemetery $cemetery,
        string $code,
        string $name,
        int $capacity,
        int|string $actorReference,
        ?string $actorRole = 'admin',
        ?int $cemeteryPackageId = null,
        ?bool $isActive = true,
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): CemeteryBlock {
        if ($cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
            throw new InvalidArgumentException(
                "Cannot create a block for cemetery [{$cemetery->getKey()}]: it is tracked in ".
                "'{$cemetery->plot_tracking_mode}' mode. Switch it to 'granular' via ".
                'SetCemeteryPlotTrackingMode first.'
            );
        }

        if ($capacity < 1) {
            throw new InvalidArgumentException('Cemetery block capacity must be at least 1.');
        }

        // ... rest of the method unchanged
```

Also update the class's own doc block to mention the new precondition (append to the existing doc comment, do not rewrite it):

```php
 * A block may only be created for a cemetery already in GRANULAR tracking
 * mode (`App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR`) — see
 * the guard at the top of `__invoke()`. This prevents a block silently
 * existing under a cemetery still marked `aggregate`.
```

- [ ] **Step 4: Run test to verify it passes**

Same command as Step 2, plus a full re-run of the file to confirm no pre-existing test regressed:

Expected: `OK (6 tests, ...)` — the original 4 tests plus the 2 new ones, all green.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/PlotInventory/Actions/CreateCemeteryBlock.php \
        tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php
git commit -m "feat(plot-inventory): refuse to create a block for an aggregate-tier cemetery"
```

---

## Task 4: `SetCemeteryPlotTrackingMode` action

**Files:**
- Create: `app/Domain/CemeteryDirectory/Actions/SetCemeteryPlotTrackingMode.php`
- Modify: `app/Domain/CemeteryDirectory/CemeteryAuditActions.php` (add one new constant)
- Test: `tests/Feature/Domain/CemeteryDirectory/SetCemeteryPlotTrackingModeTest.php`

**Interfaces:**
- Consumes: `PlotTrackingMode` (Task 1), `Cemetery::plot_tracking_mode` (Task 2), `App\Domain\PlotInventory\Models\CemeteryBlock` (existing model, read-only in this task).
- Produces: `SetCemeteryPlotTrackingMode::__invoke(Cemetery $cemetery, string $targetMode, int|string $actorReference, ?string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel, ?string $reason = null): Cemetery`. This is the ONLY sanctioned write path to `cemeteries.plot_tracking_mode` — later phases (the operator dashboard) call this action, they never write the column directly.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class SetCemeteryPlotTrackingModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_switches_aggregate_to_granular(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $result = app(SetCemeteryPlotTrackingMode::class)(
            $cemetery,
            PlotTrackingMode::GRANULAR,
            'user:1',
            'admin',
        );

        $this->assertSame(PlotTrackingMode::GRANULAR, $result->plot_tracking_mode);
        $this->assertSame(PlotTrackingMode::GRANULAR, $cemetery->fresh()->plot_tracking_mode);
        $this->assertDatabaseHas('audit_events', ['action' => 'CEMETERY_PLOT_TRACKING_MODE_CHANGED']);
    }

    public function test_refuses_granular_to_aggregate_while_blocks_exist(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);
        app(CreateCemeteryBlock::class)($cemetery, 'BLOK-G', 'Blok G', 1, 'user:1', 'operator');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            "Cannot switch cemetery [{$cemetery->getKey()}] to 'aggregate' mode: ".
            '1 cemetery block(s) still exist for it.'
        );

        app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');
    }

    public function test_allows_granular_to_aggregate_when_no_blocks_exist(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::GRANULAR]);

        $result = app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');

        $this->assertSame(PlotTrackingMode::AGGREGATE, $result->plot_tracking_mode);
    }

    public function test_same_state_transition_is_a_safe_no_op(): void
    {
        $cemetery = Cemetery::factory()->create(['plot_tracking_mode' => PlotTrackingMode::AGGREGATE]);

        $result = app(SetCemeteryPlotTrackingMode::class)($cemetery, PlotTrackingMode::AGGREGATE, 'user:1', 'admin');

        $this->assertSame(PlotTrackingMode::AGGREGATE, $result->plot_tracking_mode);
        $this->assertDatabaseMissing('audit_events', ['action' => 'CEMETERY_PLOT_TRACKING_MODE_CHANGED']);
    }

    public function test_rejects_an_unknown_target_mode(): void
    {
        $cemetery = Cemetery::factory()->create();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown plot tracking mode [hybrid]. Known modes: aggregate, granular.');

        app(SetCemeteryPlotTrackingMode::class)($cemetery, 'hybrid', 'user:1', 'admin');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Same Postgres container as prior tasks.

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=15433 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory/SetCemeteryPlotTrackingModeTest.php
```

Expected: FAIL — `Class "App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode" not found`.

- [ ] **Step 3: Write minimal implementation**

First, add the new audit action constant to the existing `app/Domain/CemeteryDirectory/CemeteryAuditActions.php` — add one line inside the existing `final class CemeteryAuditActions` body, alongside `CREATED`/`UPDATED`/`DELETED` (do not remove or rename the existing three):

```php
    public const string PLOT_TRACKING_MODE_CHANGED = 'CEMETERY_PLOT_TRACKING_MODE_CHANGED';
```

Then create `app/Domain/CemeteryDirectory/Actions/SetCemeteryPlotTrackingMode.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Actions;

use App\Domain\CemeteryDirectory\CemeteryAuditActions;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use InvalidArgumentException;

/**
 * The ONLY sanctioned write path to `cemeteries.plot_tracking_mode` —
 * `docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md` §"Cemetery
 * tracking-tier concept". `AGGREGATE -> GRANULAR` is allowed freely (a
 * cemetery opting into real per-plot inventory). `GRANULAR -> AGGREGATE`
 * is refused — an honest `InvalidArgumentException`, the same style
 * `App\Domain\PlotInventory\Models\GravePlot`'s own delete guard uses —
 * while any `CemeteryBlock` row still exists for the cemetery: this is a
 * deliberate one-way-in-practice switch once real inventory exists,
 * matching the tier being a PERMANENT classification, not a toggle a
 * later data-entry mistake should be able to silently undo. A same-state
 * transition (the target mode already matches the current one) is a safe
 * no-op — no write, no audit row — not an error.
 *
 * This action does not itself check authorization — the same layering
 * `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` uses: the
 * Filament call-site (a later phase — not built here) gates via
 * `MasterDataAdminAuthorizerContract` before invoking this action.
 *
 * ---------------------------------------------------------------------------
 * Auditing
 * ---------------------------------------------------------------------------
 * Wrapped in `Audit::wrap()` so the mode flip and its audit row commit
 * atomically, same as `CreateCemeteryBlock`. Not added to
 * `App\Platform\Audit\SensitiveActions::ACTIONS` — flipping a cemetery's
 * tracking tier is an operational/master-data decision, not a fraud- or
 * harm-shaped one, the same judgement `CemeteryAuditActions`'s own doc
 * block already documents for `CREATED`/`UPDATED`/`DELETED`.
 */
final class SetCemeteryPlotTrackingMode
{
    public function __invoke(
        Cemetery $cemetery,
        string $targetMode,
        int|string $actorReference,
        ?string $actorRole = 'admin',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $reason = null,
    ): Cemetery {
        PlotTrackingMode::assertKnown($targetMode);

        if ($cemetery->plot_tracking_mode === $targetMode) {
            return $cemetery;
        }

        if ($targetMode === PlotTrackingMode::AGGREGATE) {
            $blockCount = CemeteryBlock::query()->where('cemetery_id', $cemetery->getKey())->count();

            if ($blockCount > 0) {
                throw new InvalidArgumentException(
                    "Cannot switch cemetery [{$cemetery->getKey()}] to 'aggregate' mode: ".
                    "{$blockCount} cemetery block(s) still exist for it."
                );
            }
        }

        return Audit::wrap(
            mutation: function () use ($cemetery, $targetMode): Cemetery {
                $cemetery->update(['plot_tracking_mode' => $targetMode]);

                return $cemetery->fresh();
            },
            action: CemeteryAuditActions::PLOT_TRACKING_MODE_CHANGED,
            subject: fn (Cemetery $updated): AuditSubject => new AuditSubject('cemetery', $updated->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason ?? "Switched cemetery plot tracking mode to '{$targetMode}'.",
        );
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Same command as Step 2.

Expected: `OK (5 tests, ...)`.

Also re-run the full `CemeteryDirectory` + `PlotInventory` Feature test directories together to confirm nothing in Tasks 1–4 regressed each other:

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=15433 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Domain/CemeteryDirectory tests/Feature/Domain/PlotInventory tests/Unit/Domain/CemeteryDirectory
```

Expected: all green, no failures.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/CemeteryDirectory/Actions/SetCemeteryPlotTrackingMode.php \
        app/Domain/CemeteryDirectory/CemeteryAuditActions.php \
        tests/Feature/Domain/CemeteryDirectory/SetCemeteryPlotTrackingModeTest.php
git commit -m "feat(cemetery-directory): add SetCemeteryPlotTrackingMode action"
```

---

## Task 5: Whole-branch verification and cleanup

**Files:** none new — this task only runs checks.

- [ ] **Step 1: Run pint across the whole branch**

```bash
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
```

Expected: `PASS` on all files, including the 5 new/modified files from this plan.

- [ ] **Step 2: Run phpstan across the whole branch**

```bash
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --no-progress --memory-limit=1G
```

Expected: no new errors introduced by this branch's files.

- [ ] **Step 3: Run the full new/touched test set one more time**

```bash
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=15433 \
  -e DB_DATABASE=makam_test -e DB_USERNAME=makam_test -e DB_PASSWORD=makam_test \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=512M vendor/bin/phpunit \
    tests/Unit/Domain/CemeteryDirectory/PlotTrackingModeTest.php \
    tests/Feature/Domain/CemeteryDirectory/CemeteryPlotTrackingModeColumnTest.php \
    tests/Feature/Domain/CemeteryDirectory/SetCemeteryPlotTrackingModeTest.php \
    tests/Feature/Domain/PlotInventory/CreateCemeteryBlockTest.php
```

Expected: `OK`, all tests green.

- [ ] **Step 4: Run repo doc/design gates**

```bash
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 5: Tear down the disposable Postgres container**

```bash
docker rm -f plottrack-pg
```

- [ ] **Step 6: Invoke `superpowers:finishing-a-development-branch`**

Present the 3-option menu (merge locally / push+PR / keep as-is) to the user. Flag explicitly in that conversation that this branch adds a new schema column and a new admin-facing write action, so human review is expected before merge (`AGENTS.md` §Infrastructure-agent execution) — this is informational for the human reviewing the PR, not a reason to skip presenting the menu.
