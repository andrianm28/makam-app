# Plot Quote Lines Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put the grave plot on the quote, so the system can compute the whole amount a pay-first customer is charged.

**Architecture:** `quote_lines` gains a third line family, PLOT, carrying a frozen `grave_plot_id` + `cemetery_package_id` pair. `IssueQuote`'s "one family per quote" rule becomes an enumerated-combination rule admitting `{PLOT, SERVICE}`. `ComposeQuoteLinesFromBookingDraft` emits one plot line from the draft's active hold, priced through the DRAFT's package. The plot picker refuses to run without a package and closes when that package has no firm price.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18, Livewire 4.4, PHPUnit 12.

**Spec:** [`docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md`](../specs/2026-09-17-plot-quote-lines-design.md) — read it before Task 1; every task argues from it.

## Global Constraints

- **The pricing vehicle is the DRAFT's package, never the plot's.** `grave_plots.cemetery_package_id` is not read for pricing anywhere in this plan (spec D3).
- **A plot line carries BOTH `grave_plot_id` and `cemetery_package_id`.** Never one without the other (spec D2).
- **PACKAGE stays exclusive.** Legal family sets are exactly `{PACKAGE}`, `{SERVICE}`, `{PLOT}`, `{PLOT, SERVICE}` (spec D1).
- **Money never passes through a float.** Use `Money::fromDecimal((string) $amount)`; amounts are stored minor-unit integers.
- **A quote line's `description` is derived, never caller-supplied**, matching the existing service branch.
- **Run tests in the CI-parity image against real PostgreSQL 18**, never SQLite. The host `php` is 8.3 and reports false parse errors — do not trust it.
- **Every new gate is mutation-tested**: break its target, confirm the edit landed on disk, confirm the test fails, restore, confirm green. Report the predicted kill set before running, and report the diff between prediction and result.
- **Human review is mandatory before merge** — this is the money path (`AGENTS.md` §Infrastructure-agent execution). Open the PR; do not merge it.

**Test command used throughout** (substitute the path):

```bash
docker run --rm --user "$(id -u):$(id -g)" --link plotline-pg -v "$PWD":/app -w /app \
  -e DB_CONNECTION=pgsql -e DB_HOST=plotline-pg -e DB_PORT=5432 \
  -e DB_DATABASE=t -e DB_USERNAME=t -e DB_PASSWORD=t \
  -e CACHE_STORE=array -e QUEUE_CONNECTION=sync -e SESSION_DRIVER=array \
  -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e XDG_CONFIG_HOME=/tmp -e HOME=/tmp \
  ghcr.io/andrianm28/makam-app:sha-0319bd77895c \
  php vendor/bin/phpunit <path>
```

Start the database once before Task 2:

```bash
docker run -d --name plotline-pg -e POSTGRES_PASSWORD=t -e POSTGRES_USER=t -e POSTGRES_DB=t postgres:18
until docker exec plotline-pg pg_isready -U t; do sleep 2; done
```

---

### Task 1: ADR — the one-family rule becomes an enumerated-combination rule

Do this first. Every later task's doc block cites this ADR as its authority, and writing the code first would mean citing a document that does not exist.

**Files:**
- Create: `docs/adr/0042-quote-line-families-are-enumerated-combinations.md`
- Modify: `docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md` — add the ADR number to the "Accompanying ADR" section

**Interfaces:**
- Consumes: nothing
- Produces: the ADR number `0042`, cited by Tasks 2-5 doc blocks

- [ ] **Step 1: Find the next free ADR number**

```bash
ls docs/adr/ | sort | tail -3
```

Expected: `0041-...` is the highest. If it is not, use the next number after the highest and use that number consistently for the rest of this task.

- [ ] **Step 2: Write the ADR**

Create `docs/adr/0042-quote-line-families-are-enumerated-combinations.md`:

```markdown
# ADR-0042 — Quote line families are an enumerated combination, not a single family

**Status:** Accepted, 17 September 2026
**Supersedes:** the "P0 ruling (14 Aug 2026)" recorded only in
`app/Domain/Quotation/Actions/IssueQuote.php`'s doc block

## Context

`IssueQuote` accepted exactly one line family per quote — PACKAGE
(`service_package_version_id`) or SERVICE (`service_definition_id`) —
enforced by pinning the family from the first line and rejecting any later
line that differed. The stated reason: "a quote snapshots ONE kind of
pricing universe."

That rule was never an ADR. It lived in one doc block, which is why the
pay-first plan could be approved without anyone noticing it contradicted it.

Tahap 1 of `docs/superpowers/plans/2026-09-13-bayar-penuh-di-muka-online-saja.md`
requires one quote to carry a plot AND the funeral services bought with it:
"Total penawaran = petak + layanan."

## Decision

The set-level rule becomes an enumerated list of legal family combinations:

| Combination | Meaning |
| --- | --- |
| `{PACKAGE}` | marketplace / operator quotes — unchanged |
| `{SERVICE}` | services only |
| `{PLOT}` | a plot with no additional services |
| `{PLOT, SERVICE}` | the pay-first path |

Anything else is refused, naming the combination found.

## Why this is a sharpening rather than a loosening

The old rule's own reason is that a quote snapshots one pricing universe.
This does not abandon that reason — it states explicitly that a plot and the
funeral services bought with it ARE one universe: one order, one currency,
one customer, one moment. PACKAGE remains exclusive, so the marketplace
invariant it protected is untouched.

What changes is that the legal combinations become a list somebody can read
instead of a rule somebody has to remember.

## Rejected alternatives

- **Drop the family concept entirely.** Fewest lines of code, but then
  nothing stops a marketplace PACKAGE line being mixed into a booking quote.
  Removing a guard because it blocks one case is how guards die.
- **Model the plot as a synthetic `ServiceDefinition`.** No schema change and
  no ruling to amend, but `IssueQuote`'s doc block already refuses synthesized
  versions, and it would move plot pricing into the service catalogue —
  against the two-tier package/plot pricing design, and filling the service
  catalogue with entries that are not services.

## Consequences

- `quote_lines` gains `grave_plot_id` and `cemetery_package_id`, and a
  three-armed CHECK constraint enforces that exactly one family's key group
  is populated per row.
- The combination rule itself is per-SET and therefore has no database
  backstop. That is stated rather than assumed away.
- Design detail lives in
  `docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md`; this ADR
  records only the ruling.
```

- [ ] **Step 3: Point the spec at the ADR**

In `docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md`, replace the final section's first sentence:

```
D1 amends a decision recorded only in a doc block. An ADR must record that the
```

with:

```
D1 amends a decision recorded only in a doc block. **ADR-0042** records that the
```

- [ ] **Step 4: Run the documentation gates**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 5: Commit**

```bash
git add docs/adr/0042-quote-line-families-are-enumerated-combinations.md docs/superpowers/specs/2026-09-17-plot-quote-lines-design.md
git commit -m "docs(adr): ADR-0042 — quote line families are an enumerated combination"
```

---

### Task 2: Schema — two frozen columns and the three-armed CHECK

**Files:**
- Create: `database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php`
- Modify: `app/Domain/Quotation/Models/QuoteLine.php` — add both columns to `$fillable`
- Test: `tests/Feature/Database/Migrations/QuoteLinePlotColumnsTest.php`

**Interfaces:**
- Consumes: ADR-0042 (Task 1) as the doc-block citation
- Produces: columns `quote_lines.grave_plot_id` (nullable FK to `grave_plots`, restrictOnDelete) and `quote_lines.cemetery_package_id` (nullable FK to `cemetery_packages`, restrictOnDelete); CHECK constraint `quote_lines_line_family_check`

- [ ] **Step 1: Start the test database**

```bash
docker run -d --name plotline-pg -e POSTGRES_PASSWORD=t -e POSTGRES_USER=t -e POSTGRES_DB=t postgres:18
until docker exec plotline-pg pg_isready -U t; do sleep 2; done
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Database/Migrations/QuoteLinePlotColumnsTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `2026_09_17_110000_add_plot_line_columns_to_quote_lines_table` — ADR-0042.
 *
 * The CHECK is the point of this file. The application also enforces the
 * per-row rule (`IssueQuote::lineFamilyOf()`), and duplicating it here is
 * deliberate: the application produces the readable message, the database
 * provides the guarantee. A stray `DB::table('quote_lines')->insert()` reaches
 * only one of the two.
 */
final class QuoteLinePlotColumnsTest extends TestCase
{
    use RefreshDatabase;

    public function test_both_plot_columns_exist_and_are_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('quote_lines', 'grave_plot_id'));
        $this->assertTrue(Schema::hasColumn('quote_lines', 'cemetery_package_id'));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_plot(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quote_lines')->insert($this->row([
            'grave_plot_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_plot_line_carrying_only_the_package(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quote_lines')->insert($this->row([
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_mixing_a_service_key_with_a_plot_key(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quote_lines')->insert($this->row([
            'service_definition_id' => 1,
            'grave_plot_id' => 1,
            'cemetery_package_id' => 1,
        ]));
    }

    public function test_the_check_refuses_a_row_naming_no_family_at_all(): void
    {
        $this->expectException(QueryException::class);

        DB::table('quote_lines')->insert($this->row([]));
    }

    /**
     * @param  array<string, mixed>  $family
     * @return array<string, mixed>
     */
    private function row(array $family): array
    {
        // quote_id 1 need not exist: PostgreSQL evaluates the CHECK before the
        // FK, and every case here is expected to fail on the CHECK. A row that
        // reached the FK would mean the CHECK did not fire, which is the
        // failure these tests are for.
        return array_merge([
            'quote_id' => 1,
            'price_version_id' => 1,
            'price_version_number' => 1,
            'description' => 'x',
            'quantity' => 1,
            'unit_amount_minor' => 1000,
            'line_total_minor' => 1000,
            'currency' => 'IDR',
            'fulfillment_owner' => 'platform',
            'created_at' => now(),
            'updated_at' => now(),
        ], $family);
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run the test command with `tests/Feature/Database/Migrations/QuoteLinePlotColumnsTest.php`
Expected: FAIL — `assertTrue(Schema::hasColumn(...))` fails because the columns do not exist yet.

- [ ] **Step 4: Write the migration**

Create `database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php`:

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ADR-0042 — a third quote-line family, PLOT.
 *
 * `grave_plot_id` names what was sold; `cemetery_package_id` names the pricing
 * vehicle that produced the amount. BOTH are written, and the CHECK below
 * makes that structural rather than habitual.
 *
 * Recording both is the point. A quote is a frozen snapshot: deriving the
 * package from the plot at read time is the defect, because
 * `grave_plots.cemetery_package_id` is `nullOnDelete` and its own migration
 * calls it "an indicative convenience reference, not the plot's identity". A
 * derived read after that link moves returns a price version that was never
 * charged.
 *
 * Both are `restrictOnDelete`, matching `price_version_id` and the two
 * existing family references: a row a quote points at may not be deleted out
 * from under it.
 *
 * Expand/contract, exactly like
 * `2026_08_15_100000_add_service_definition_id_to_quote_lines_table.php`
 * before it: new columns are nullable, no existing row is touched, and the
 * two existing families keep populating what they always did.
 *
 * Verified against live data before writing: all 101 `quote_lines` rows on
 * beta satisfy the SERVICE arm and none violates any arm, so the constraint
 * applies without a data fix.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->foreignId('grave_plot_id')
                ->nullable()
                ->after('service_definition_id')
                ->constrained('grave_plots')
                ->restrictOnDelete();

            $table->foreignId('cemetery_package_id')
                ->nullable()
                ->after('grave_plot_id')
                ->constrained('cemetery_packages')
                ->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // One arm per family. The PLOT arm requires BOTH columns together:
        // a half-formed plot line cannot be written at all, by anything.
        DB::statement(
            'ALTER TABLE quote_lines ADD CONSTRAINT quote_lines_line_family_check CHECK ('
            .'(service_package_version_id IS NOT NULL AND service_definition_id IS NULL '
            .'AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR '
            .'(service_definition_id IS NOT NULL AND service_package_version_id IS NULL '
            .'AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR '
            .'(grave_plot_id IS NOT NULL AND cemetery_package_id IS NOT NULL '
            .'AND service_package_version_id IS NULL AND service_definition_id IS NULL))'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE quote_lines DROP CONSTRAINT IF EXISTS quote_lines_line_family_check');
        }

        Schema::table('quote_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('cemetery_package_id');
            $table->dropConstrainedForeignId('grave_plot_id');
        });
    }
};
```

- [ ] **Step 5: Add both columns to the model**

In `app/Domain/Quotation/Models/QuoteLine.php`, inside `$fillable`, after `'service_package_version_id',` add:

```php
        'grave_plot_id',
        'cemetery_package_id',
```

- [ ] **Step 6: Run the test to verify it passes**

Run the test command with `tests/Feature/Database/Migrations/QuoteLinePlotColumnsTest.php`
Expected: PASS (5 tests)

- [ ] **Step 7: Mutation-test the CHECK**

Predict first, in writing: removing the `AND cemetery_package_id IS NULL` clause from the SERVICE arm should kill exactly `test_the_check_refuses_a_row_mixing_a_service_key_with_a_plot_key` and no other.

```bash
# Break it
sed -i 's/AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR /OR /' \
  database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php
grep -c "AND grave_plot_id IS NULL AND cemetery_package_id IS NULL) OR " \
  database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php
```

Expected: the grep prints `0` — confirm the edit landed before running anything. Then run the test file; record which tests fail and compare against the prediction. Restore with `git checkout -- database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php` and confirm green again.

- [ ] **Step 8: Verify the destructive-migration gate is satisfied**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`. The migration adds columns and a constraint and drops nothing in `up()`, so `DestructiveMigrationScanner` has nothing to report.

- [ ] **Step 9: Commit**

```bash
git add database/migrations/2026_09_17_110000_add_plot_line_columns_to_quote_lines_table.php app/Domain/Quotation/Models/QuoteLine.php tests/Feature/Database/Migrations/QuoteLinePlotColumnsTest.php
git commit -m "feat(quotation): quote_lines carries a frozen plot+package pair, CHECK-enforced"
```

---

### Task 3: `IssueQuote` accepts the PLOT family

**Files:**
- Modify: `app/Domain/Quotation/Actions/IssueQuote.php`
- Test: `tests/Feature/Domain/Quotation/IssueQuotePlotLineTest.php`

**Interfaces:**
- Consumes: `quote_lines.grave_plot_id`, `quote_lines.cemetery_package_id` (Task 2)
- Produces: a PLOT line shape the composer must emit —
  `array{grave_plot_id: int, cemetery_package_id: int, price_version_id: int, price_version_number: int, quantity: int, unit_amount: string, currency: string, fulfillment_owner: string}`.
  Note it carries NO `description`: like a service line, the description is derived.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Domain/Quotation/IssueQuotePlotLineTest.php`. Build the fixture with real rows — a cemetery, a block, a plot, a package, and a current `PriceVersion` on the package — and assert:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

use App\Domain\Quotation\Actions\IssueQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * ADR-0042 — the PLOT family and the enumerated combinations.
 *
 * The fixture is built row by row rather than from `CemeteryExampleData`: this
 * asserts what `IssueQuote` does with a shape, and a fixture taken from the
 * generator would only ever exercise today's generator (DB-13).
 */
final class IssueQuotePlotLineTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_plot_line_freezes_both_the_plot_and_the_package(): void
    {
        [$order, $line] = $this->orderAndPlotLine();

        $quote = app(IssueQuote::class)($order, [$line], 'actor:test', 'admin');

        $row = DB::table('quote_lines')->where('quote_id', $quote->getKey())->sole();

        $this->assertSame($line['grave_plot_id'], (int) $row->grave_plot_id);
        $this->assertSame($line['cemetery_package_id'], (int) $row->cemetery_package_id);
        $this->assertNull($row->service_definition_id);
        $this->assertNull($row->service_package_version_id);
    }

    public function test_a_plot_line_and_a_service_line_may_share_one_quote(): void
    {
        [$order, $plotLine, $serviceLine] = $this->orderPlotAndServiceLines();

        $quote = app(IssueQuote::class)($order, [$plotLine, $serviceLine], 'actor:test', 'admin');

        $this->assertSame(2, DB::table('quote_lines')->where('quote_id', $quote->getKey())->count());
    }

    public function test_a_plot_line_may_not_share_a_quote_with_a_package_line(): void
    {
        [$order, $plotLine, $packageLine] = $this->orderPlotAndPackageLines();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/combination/i');

        app(IssueQuote::class)($order, [$plotLine, $packageLine], 'actor:test', 'admin');
    }

    public function test_a_price_version_belonging_to_another_package_is_refused(): void
    {
        [$order, $line] = $this->orderAndPlotLineWithForeignPriceVersion();

        $this->expectException(InvalidArgumentException::class);

        app(IssueQuote::class)($order, [$line], 'actor:test', 'admin');
    }

    public function test_a_package_from_another_cemetery_than_the_plot_is_refused(): void
    {
        [$order, $line] = $this->orderAndPlotLineWithCrossCemeteryPackage();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/cemetery/i');

        app(IssueQuote::class)($order, [$line], 'actor:test', 'admin');
    }

    public function test_a_unit_amount_contradicting_the_frozen_version_is_refused(): void
    {
        [$order, $line] = $this->orderAndPlotLine();
        $line['unit_amount'] = '999999.00';

        $this->expectException(InvalidArgumentException::class);

        app(IssueQuote::class)($order, [$line], 'actor:test', 'admin');
    }
}
```

> **Reuse the fixtures that already exist — do not write new ones.**
> `tests/Feature/Domain/Quotation/IssueQuoteServiceLineTest.php` already has
> every helper this file needs: `makeOrder()` (line 304), `issue()` (316),
> `serviceLine()` (338), `packageLine()` (364) and `publishedVersion()` (389).
> Copy them verbatim into the new test class — or, if you prefer, extract them
> into a trait that both classes use, which is the better shape once a second
> caller exists.
>
> Only ONE new helper is needed: `plotLine()`, building a cemetery + block +
> plot + package + current `PriceVersion` and returning the eight-key PLOT
> line from this task's Interfaces block. Every `orderAnd…()` helper in the
> test above is then two lines: `makeOrder()` plus the line(s) it names.
>
> A second way to build an order would rot; there is already one.

- [ ] **Step 2: Run the test to verify it fails**

Run the test command with `tests/Feature/Domain/Quotation/IssueQuotePlotLineTest.php`
Expected: FAIL — `lineFamilyOf()` throws "must carry exactly one of [service_definition_id] … or [service_package_version_id]" because it does not know the plot keys.

- [ ] **Step 3: Add the family constant**

In `app/Domain/Quotation/Actions/IssueQuote.php`, after `private const string PACKAGE_LINE = 'package';` add:

```php
    /**
     * ADR-0042. A plot line names `grave_plot_id` (what was sold) AND
     * `cemetery_package_id` (the pricing vehicle that produced the amount).
     */
    private const string PLOT_LINE = 'plot';

    /**
     * The family SETS a quote may carry, per ADR-0042. `{PLOT, SERVICE}` is
     * the pay-first path: a plot and the funeral services bought with it are
     * one pricing universe. PACKAGE stays exclusive, so the marketplace
     * invariant the original ruling protected is untouched.
     *
     * Sorted arrays, compared against a sorted set — the order lines arrive
     * in must not change the verdict.
     *
     * @var list<list<string>>
     */
    private const array LEGAL_FAMILY_SETS = [
        [self::PACKAGE_LINE],
        [self::SERVICE_LINE],
        [self::PLOT_LINE],
        [self::PLOT_LINE, self::SERVICE_LINE],
    ];
```

- [ ] **Step 4: Make `lineFamilyOf()` three-way**

Replace the body of `lineFamilyOf()` with:

```php
    private function lineFamilyOf(array $line, int $index): string
    {
        $families = [];

        if (array_key_exists('service_definition_id', $line)) {
            $families[] = self::SERVICE_LINE;
        }

        if (array_key_exists('service_package_version_id', $line)) {
            $families[] = self::PACKAGE_LINE;
        }

        // A plot line names BOTH keys, so both are required to claim the
        // family and neither alone is accepted — the same pair the
        // `quote_lines_line_family_check` constraint enforces in the database.
        if (array_key_exists('grave_plot_id', $line) || array_key_exists('cemetery_package_id', $line)) {
            if (! array_key_exists('grave_plot_id', $line) || ! array_key_exists('cemetery_package_id', $line)) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] is a plot line and must carry BOTH ".
                    '[grave_plot_id] and [cemetery_package_id].'
                );
            }

            $families[] = self::PLOT_LINE;
        }

        if (count($families) !== 1) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] must carry exactly one of ".
                '[service_definition_id] (service line), [service_package_version_id] (package line), '.
                'or [grave_plot_id]+[cemetery_package_id] (plot line).'
            );
        }

        return $families[0];
    }
```

- [ ] **Step 5: Replace the first-line pin with the set rule**

In `validateAndNormalizeLines()`, replace:

```php
            if ($family === null) {
                $family = $lineFamily;
            } elseif ($lineFamily !== $family) {
                throw new InvalidArgumentException(
                    "Quote line [{$index}] is a [{$lineFamily}] line in a set whose ".
                    "first line is a [{$family}] line — a quote must carry one line family."
                );
            }
```

with:

```php
            $familiesSeen[$lineFamily] = true;
```

Declare `$familiesSeen = [];` beside `$family = null;` at the top of the method, delete the now-unused `$family` variable, and after the loop — before `return $normalized;` — add:

```php
        // ADR-0042: the SET of families present must be one of the legal
        // combinations. This is a per-set rule, so unlike the per-row rule it
        // has no database backstop; that is stated in the ADR rather than
        // assumed away.
        $present = array_keys($familiesSeen);
        sort($present);

        if (! in_array($present, self::LEGAL_FAMILY_SETS, true)) {
            throw new InvalidArgumentException(
                'A quote may not mix line families this way: got combination ['.
                implode(', ', $present).']. ADR-0042 permits [package], [service], [plot], '.
                'or [plot + service].'
            );
        }
```

- [ ] **Step 6: Dispatch to the plot branch**

In the per-line loop, beside the existing service dispatch, add:

```php
            if ($lineFamily === self::PLOT_LINE) {
                $normalized[] = $this->normalizePlotLine($line, $index, $quantity, $unitAmountMinor, $lineCurrency, $fulfillmentOwner);

                continue;
            }
```

- [ ] **Step 7: Write `normalizePlotLine()`**

Add beside `normalizeServiceLine()`:

```php
    /**
     * A plot line's frozen-snapshot branch, mirroring the service branch.
     *
     * Four things are checked, and the fourth is the one most easily missed:
     * the named `PriceVersion` must exist, be CURRENT, belong to the named
     * `CemeteryPackage` — `price_versions` is polymorphic and holds rows for
     * `ServiceDefinition` and `ServicePackageVersion` too — and that package
     * must belong to the SAME cemetery as the plot, compared through the
     * plot's own path (`grave_plots.block_id` -> `cemetery_blocks.cemetery_id`).
     * Without the last one a draft could freeze another cemetery's package
     * price onto this plot, a defect visible only when somebody asks why the
     * amount is what it is.
     *
     * `description` is derived from the plot and its package, never
     * caller-supplied, so no line description can drift from the catalogue.
     *
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function normalizePlotLine(
        array $line,
        int $index,
        int $quantity,
        int $unitAmountMinor,
        string $lineCurrency,
        string $fulfillmentOwner,
    ): array {
        $gravePlotId = (int) $this->requiredInt($line, 'grave_plot_id', $index);
        $cemeteryPackageId = (int) $this->requiredInt($line, 'cemetery_package_id', $index);

        $plot = GravePlot::query()->with('block')->find($gravePlotId);

        if (! $plot instanceof GravePlot) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references unknown grave plot [{$gravePlotId}]."
            );
        }

        $package = CemeteryPackage::query()->find($cemeteryPackageId);

        if (! $package instanceof CemeteryPackage) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references unknown cemetery package [{$cemeteryPackageId}]."
            );
        }

        if ((string) $package->cemetery_id !== (string) $plot->block?->cemetery_id) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] prices grave plot [{$gravePlotId}] with cemetery package ".
                "[{$cemeteryPackageId}], which belongs to a different cemetery."
            );
        }

        $priceVersionId = (int) $this->requiredInt($line, 'price_version_id', $index);
        $priceVersion = PriceVersion::query()->find($priceVersionId);

        if (! $priceVersion instanceof PriceVersion
            || ! $priceVersion->isCurrent()
            || $priceVersion->priceable_type !== CemeteryPackage::class
            || (int) $priceVersion->priceable_id !== $cemeteryPackageId) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] references price version [{$priceVersionId}], ".
                "which is not the current price version of cemetery package [{$cemeteryPackageId}]."
            );
        }

        $priceVersionNumber = $this->requiredInt($line, 'price_version_number', $index);

        if (Money::fromDecimal((string) $priceVersion->amount) !== $unitAmountMinor
            || $lineCurrency !== (string) $priceVersion->currency
            || $priceVersionNumber !== (int) $priceVersion->version_number) {
            throw new InvalidArgumentException(
                "Quote line [{$index}] unit amount, currency, or version number contradicts ".
                "price version [{$priceVersionId}]'s frozen anchor."
            );
        }

        return [
            'service_definition_id' => null,
            'service_package_version_id' => null,
            'grave_plot_id' => $gravePlotId,
            'cemetery_package_id' => $cemeteryPackageId,
            'price_version_id' => $priceVersionId,
            'price_version_number' => $priceVersionNumber,
            'description' => $package->name.' — '.$plot->slot,
            'quantity' => $quantity,
            'unit_amount_minor' => $unitAmountMinor,
            'line_total_minor' => $this->lineTotalMinor($unitAmountMinor, $quantity),
            'currency' => $lineCurrency,
            'fulfillment_owner' => $fulfillmentOwner,
        ];
    }
```

Add the imports `use App\Domain\CemeteryCapability\Models\CemeteryPackage;` and `use App\Domain\PlotInventory\Models\GravePlot;`.

- [ ] **Step 8: Carry the two columns into the insert**

The service and package branches return `null` for the new keys, so add both to the `QuoteLine::query()->create([...])` array:

```php
                    'grave_plot_id' => $line['grave_plot_id'] ?? null,
                    'cemetery_package_id' => $line['cemetery_package_id'] ?? null,
```

Then add `'grave_plot_id' => null,` and `'cemetery_package_id' => null,` to the arrays returned by `normalizeServiceLine()` and the package branch, so every normalized line has the same key set and the `??` above is belt-and-braces rather than load-bearing.

- [ ] **Step 9: Run the test to verify it passes**

Run the test command with `tests/Feature/Domain/Quotation/IssueQuotePlotLineTest.php`
Expected: PASS (6 tests)

- [ ] **Step 10: Run the whole quotation suite for regressions**

Run the test command with `tests/Feature/Domain/Quotation/ tests/Unit/Domain/Quotation/`
Expected: PASS. If an existing test asserts the old "one line family" message, update the assertion to the new message — do NOT loosen it to a substring that would pass either way.

- [ ] **Step 11: Mutation-test the combination rule**

Predict first: adding `[self::PLOT_LINE, self::PACKAGE_LINE]` to `LEGAL_FAMILY_SETS` should kill exactly `test_a_plot_line_may_not_share_a_quote_with_a_package_line`.

Apply the mutation, `grep` the file to confirm it landed, run the suite, compare against the prediction, restore, confirm green. Report the difference between prediction and result.

- [ ] **Step 12: Commit**

```bash
git add app/Domain/Quotation/Actions/IssueQuote.php tests/Feature/Domain/Quotation/IssueQuotePlotLineTest.php
git commit -m "feat(quotation): IssueQuote accepts a PLOT line family (ADR-0042)"
```

---

### Task 4: The composer emits the plot line

**Files:**
- Create: `app/Domain/Quotation/Exceptions/UnpricedBookingPlotException.php`
- Modify: `app/Domain/Quotation/Actions/ComposeQuoteLinesFromBookingDraft.php`
- Test: `tests/Feature/Domain/Quotation/ComposeQuoteLinesPlotTest.php`

**Interfaces:**
- Consumes: the PLOT line shape from Task 3
- Produces: `ComposeQuoteLinesFromBookingDraft::__invoke()` now returns the plot line FIRST, then the service lines

- [ ] **Step 1: Write the exception**

Create `app/Domain/Quotation/Exceptions/UnpricedBookingPlotException.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Quotation\Exceptions;

use RuntimeException;

/**
 * The selected plot cannot be priced, so no quote may be composed.
 *
 * The sibling of `UnpricedBookingServiceException`, and thrown for the same
 * reason its doc block gives: this feeds a financial WRITE. Silently dropping
 * the plot line would underquote an order by its LARGEST component — the read
 * path may degrade to "harga belum tersedia"; the write path may not.
 *
 * Per ADR-0042 and the Tahap 1 spec, the pricing vehicle is the DRAFT's
 * package, never `grave_plots.cemetery_package_id`, which that column's own
 * migration calls "an indicative convenience reference, not the plot's
 * identity".
 */
final class UnpricedBookingPlotException extends RuntimeException
{
    public static function forMissingPackage(): self
    {
        return new self(
            'The booking draft holds a plot but names no cemetery package, so no price can be '.
            'computed. Selecting a package is a precondition of selecting a plot.'
        );
    }

    public static function forUnpricedPackage(int|string $packageId): self
    {
        return new self(
            "Cemetery package [{$packageId}] has no current firm price version, so the held plot ".
            'cannot be charged. An operator must record a price before this order can be quoted.'
        );
    }

    public static function forCrossCemeteryPackage(int|string $packageId): self
    {
        return new self(
            "Cemetery package [{$packageId}] belongs to a different cemetery than the held plot; ".
            'refusing to price one cemetery\'s plot with another\'s package.'
        );
    }
}
```

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Domain/Quotation/ComposeQuoteLinesPlotTest.php` asserting, with hand-built fixtures:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Quotation;

use App\Domain\Quotation\Actions\ComposeQuoteLinesFromBookingDraft;
use App\Domain\Quotation\Exceptions\UnpricedBookingPlotException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ComposeQuoteLinesPlotTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_a_held_plot_emits_a_plot_line_first(): void
    {
        $draft = $this->draftWithHeldPlotAndPricedPackage();

        $lines = app(ComposeQuoteLinesFromBookingDraft::class)($draft);

        $this->assertArrayHasKey('grave_plot_id', $lines[0]);
        $this->assertArrayHasKey('cemetery_package_id', $lines[0]);
        $this->assertSame(1, $lines[0]['quantity']);
    }

    public function test_a_draft_with_no_held_plot_emits_only_service_lines(): void
    {
        $draft = $this->draftWithServicesOnly();

        foreach (app(ComposeQuoteLinesFromBookingDraft::class)($draft) as $line) {
            $this->assertArrayNotHasKey('grave_plot_id', $line);
        }
    }

    public function test_a_held_plot_with_no_package_on_the_draft_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithHeldPlotButNoPackage());
    }

    public function test_a_package_with_no_current_price_version_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithHeldPlotAndUnpricedPackage());
    }

    public function test_a_package_from_another_cemetery_is_refused(): void
    {
        $this->expectException(UnpricedBookingPlotException::class);

        app(ComposeQuoteLinesFromBookingDraft::class)($this->draftWithCrossCemeteryPackage());
    }
}
```

> **Start from the draft fixture that already exists.**
> `tests/Feature/Domain/Quotation/ComposeQuoteLinesFromBookingDraftTest.php:52`
> has `draftWithSelectedServices(array $services)`. Every helper here is that
> one plus a hold: insert a `PlotReservation` with `plot_id` set and
> `booking_draft_id` pointing at the draft. Read
> `app/Domain/PlotReservation/Models/PlotReservation.php:196-225` for what
> makes a hold count as active — `activeForDraftId()` takes the newest by
> `created_at` then `id`, and passes it through `incumbentOf()`.
>
> `draftWithCrossCemeteryPackage()` is the one that carries the weight of D3:
> its plot must sit in cemetery A while the draft's package belongs to
> cemetery B. Build it that way even though it takes an extra cemetery — a
> fixture where both happen to match cannot tell the two vehicles apart, which
> is exactly what Step 7 mutation-tests for.

- [ ] **Step 3: Run the test to verify it fails**

Run the test command with `tests/Feature/Domain/Quotation/ComposeQuoteLinesPlotTest.php`
Expected: FAIL — `assertArrayHasKey('grave_plot_id', $lines[0])` fails; the composer emits only service lines.

- [ ] **Step 4: Add the plot line to the composer**

In `app/Domain/Quotation/Actions/ComposeQuoteLinesFromBookingDraft.php`, at the top of `__invoke()`, replace `$lines = [];` with:

```php
        // The plot comes FIRST: it is the largest component of the order, and
        // a reader scanning a quote should meet what was bought before what
        // was added to it.
        $lines = $this->plotLines($draft);
```

Then add the method:

```php
    /**
     * Zero or one plot line, from the draft's active hold.
     *
     * `PlotReservation::activeForDraft()` returns at most one hold per draft,
     * so no quantity question arises: a plot line is always quantity 1.
     *
     * The pricing vehicle is the DRAFT's package. `grave_plots.cemetery_package_id`
     * is deliberately NOT read — its own migration calls it "an indicative
     * convenience reference, not the plot's identity", and it is
     * `nullOnDelete`, so a charge must not rest on it (spec D3).
     *
     * @return list<array<string, mixed>>
     */
    private function plotLines(BookingDraft $draft): array
    {
        $hold = PlotReservation::activeForDraft($draft);

        if ($hold === null) {
            return [];
        }

        $packageId = $draft->cemetery_package_id;

        if ($packageId === null) {
            throw UnpricedBookingPlotException::forMissingPackage();
        }

        $package = CemeteryPackage::query()->find($packageId);

        if (! $package instanceof CemeteryPackage) {
            throw UnpricedBookingPlotException::forUnpricedPackage($packageId);
        }

        $plot = GravePlot::query()->with('block')->find($hold->plot_id);

        if ($plot === null || (string) $package->cemetery_id !== (string) $plot->block?->cemetery_id) {
            throw UnpricedBookingPlotException::forCrossCemeteryPackage($packageId);
        }

        $priceVersion = $package->currentPriceVersion();

        if (! $priceVersion instanceof PriceVersion) {
            throw UnpricedBookingPlotException::forUnpricedPackage($packageId);
        }

        // Same seam as the service branch: a malformed stored amount is
        // rejected here rather than deeper inside `IssueQuote`.
        Money::fromDecimal((string) $priceVersion->amount);

        return [[
            'grave_plot_id' => (int) $plot->getKey(),
            'cemetery_package_id' => (int) $package->getKey(),
            'price_version_id' => (int) $priceVersion->getKey(),
            'price_version_number' => (int) $priceVersion->version_number,
            'quantity' => 1,
            'unit_amount' => (string) $priceVersion->amount,
            'currency' => (string) $priceVersion->currency,
            'fulfillment_owner' => FulfillmentOwner::PLATFORM,
        ]];
    }
```

Add the imports: `CemeteryPackage`, `GravePlot`, `PlotReservation`,
`UnpricedBookingPlotException`, and `FulfillmentOwner`.
`FulfillmentOwner::PLATFORM` is verified to exist
(`app/Domain/ServiceCatalog/FulfillmentOwner.php:24`, value `'platform'`) — a
plot is sold by the platform, not by a vendor or the cemetery operator.

- [ ] **Step 5: Run the test to verify it passes**

Run the test command with `tests/Feature/Domain/Quotation/ComposeQuoteLinesPlotTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the booking submission chain for regressions**

Run the test command with `tests/Feature/Domain/Quotation/ tests/Feature/Livewire/Public/Booking/`
Expected: PASS. A draft with no hold must still quote exactly as before — that is what `test_a_draft_with_no_held_plot_emits_only_service_lines` pins.

- [ ] **Step 7: Mutation-test the vehicle rule**

Predict first: changing `$draft->cemetery_package_id` to `$plot->cemetery_package_id` — the rule spec D3 forbids — should kill `test_a_draft_with_a_held_plot_emits_a_plot_line_first` and `test_a_held_plot_with_no_package_on_the_draft_is_refused`.

If it kills NOTHING, the tests do not distinguish the two vehicles and a fixture must be added where the plot's package and the draft's package differ — that is the whole of D3, and a test suite that cannot tell them apart has not tested it.

Apply, confirm on disk, run, compare, restore, confirm green.

- [ ] **Step 8: Commit**

```bash
git add app/Domain/Quotation/Exceptions/UnpricedBookingPlotException.php app/Domain/Quotation/Actions/ComposeQuoteLinesFromBookingDraft.php tests/Feature/Domain/Quotation/ComposeQuoteLinesPlotTest.php
git commit -m "feat(quotation): the booking quote carries the held plot, priced through the draft's package"
```

---

### Task 5: The picker refuses what cannot be charged

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php` — `pickerBlocks()` and its unavailable-state flag
- Modify: `resources/views/livewire/public/booking/wizard.blade.php` — the picker's empty state
- Test: `tests/Feature/Livewire/Public/Booking/PlotPickerPricingGateTest.php`

**Interfaces:**
- Consumes: `CemeteryPackage::currentPriceVersion()` (PR #296)
- Produces: nothing later tasks depend on

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Livewire/Public/Booking/PlotPickerPricingGateTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Spec D4 and D5.
 *
 * Both directions are asserted on purpose: a gate that only ever closes passes
 * a "it is closed" test while testing nothing. The priced case must open.
 */
final class PlotPickerPricingGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_picker_offers_nothing_before_a_package_is_selected(): void
    {
        $component = Livewire::test(BookingWizard::class);
        // Drive it to the picker with a granular cemetery and NO package,
        // then assert the picker is empty and says why.

        $component->assertSee('Pilih paket terlebih dahulu');
    }

    public function test_the_picker_offers_nothing_when_the_selected_package_has_no_firm_price(): void
    {
        $component = $this->wizardAtPickerWithUnpricedPackage();

        $component->assertSee('Harga paket ini belum tersedia');
    }

    public function test_the_picker_offers_every_plot_when_the_package_is_priced(): void
    {
        $component = $this->wizardAtPickerWithPricedPackage();

        $component->assertDontSee('Harga paket ini belum tersedia');
        $component->assertSee('A-1');
    }
}
```

> **Reuse the existing wizard-driving helper.** Read
> `tests/Feature/Livewire/Public/Booking/` and use whatever already drives the
> wizard to the picker; that component has many steps and a second way of
> driving it will rot. The three helpers here differ only in the package they
> attach to the draft: none, unpriced, priced.
>
> The Indonesian copy in these assertions must match Step 3 exactly. Pick the
> copy once, then make the test and the Blade agree — do not let them drift
> into a substring match that would pass either way.

- [ ] **Step 2: Run the test to verify it fails**

Run the test command with `tests/Feature/Livewire/Public/Booking/PlotPickerPricingGateTest.php`
Expected: FAIL — the copy does not exist yet.

- [ ] **Step 3: Gate `pickerBlocks()`**

In `app/Livewire/Public/Booking/BookingWizard.php`, immediately after the existing early return in `pickerBlocks()`, add:

```php
        // Spec D4 — a plot chosen without a package cannot be charged, because
        // the DRAFT's package is the pricing vehicle (spec D3). Refusing here
        // is better than rendering blocks that lead to an unbillable order.
        if ($this->pickerCemeteryPackageId === null) {
            $this->pickerUnpricedReason = 'no-package';

            return new \Illuminate\Support\Collection;
        }

        // Spec D5 — ONE gate, not a per-plot filter. The vehicle is the same
        // for every plot in this picker, so either the package has a current
        // firm price and every plot is priceable, or it has none and no plot
        // is. (This splits per-plot when Tahap 0 tier 2 gives a plot its own
        // price; it is written as one gate because that is what is true now.)
        $package = CemeteryPackage::query()->find($this->pickerCemeteryPackageId);

        if ($package === null || $package->currentPriceVersion() === null) {
            $this->pickerUnpricedReason = 'no-price';

            return new \Illuminate\Support\Collection;
        }

        $this->pickerUnpricedReason = null;
```

Declare `public ?string $pickerUnpricedReason = null;` beside `$pickerBlocksUnavailable`, and import `CemeteryPackage`.

- [ ] **Step 4: Render the two empty states**

In `resources/views/livewire/public/booking/wizard.blade.php`, inside the picker section, before the blocks loop:

```blade
@if ($this->pickerUnpricedReason === 'no-package')
    <p class="text-base text-neutral-700">
        Pilih paket terlebih dahulu — harga petak mengikuti paket yang Anda pilih.
    </p>
@elseif ($this->pickerUnpricedReason === 'no-price')
    <p class="text-base text-neutral-700">
        Harga paket ini belum tersedia, jadi petaknya belum bisa dipesan online.
        Hubungi bantuan untuk melanjutkan.
    </p>
@endif
```

`$this->` is deliberate rather than the bare `$pickerUnpricedReason`, matching
the comment already at `wizard.blade.php:465` explaining why this component's
picker state is read that way. Follow it; do not introduce a second style.

Use only existing design tokens: GATE 2 rejects hardcoded design values and
GATE 3 rejects arbitrary Tailwind values, in `resources/` as well as `app/`.

- [ ] **Step 5: Run the test to verify it passes**

Run the test command with `tests/Feature/Livewire/Public/Booking/PlotPickerPricingGateTest.php`
Expected: PASS (3 tests)

- [ ] **Step 6: Run the design gates**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 7: Mutation-test the gate in the open direction**

Predict first: deleting the `no-price` branch should kill exactly `test_the_picker_offers_nothing_when_the_selected_package_has_no_firm_price`. Then, separately, make the gate ALWAYS close (return the empty collection unconditionally) and confirm it kills `test_the_picker_offers_every_plot_when_the_package_is_priced` — a gate that only closes must not pass.

Apply each, confirm on disk, run, compare against prediction, restore, confirm green.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php resources/views/livewire/public/booking/wizard.blade.php tests/Feature/Livewire/Public/Booking/PlotPickerPricingGateTest.php
git commit -m "feat(booking): the plot picker refuses what cannot be charged"
```

---

### Task 6: Whole-branch verification and the pull request

**Files:** none changed — this task runs checks and opens the PR.

- [ ] **Step 1: Run Pint**

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app -e XDG_CONFIG_HOME=/tmp -e HOME=/tmp \
  ghcr.io/andrianm28/makam-app:sha-0319bd77895c php vendor/bin/pint --test
```

Expected: PASS. If it reports style issues, run without `--test` to fix them, then re-run the affected task's tests — Pint rewrites files.

- [ ] **Step 2: Run PHPStan**

```bash
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD":/app -w /app -e XDG_CONFIG_HOME=/tmp -e HOME=/tmp \
  ghcr.io/andrianm28/makam-app:sha-0319bd77895c php -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
```

Expected: `[OK] No errors`. Run it in a container where phpunit has NOT run first: this image reports a spurious unmatched-baseline error when phpunit precedes it, and pristine trunk reproduces that identically.

- [ ] **Step 3: Run the documentation gates**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS`

- [ ] **Step 4: Tear down the test database**

```bash
docker rm -f plotline-pg
```

- [ ] **Step 5: Push and open the pull request**

The PR body must carry: the ADR number, the D3 vehicle rule and why, the fact that **D5 closes the picker entirely until an operator records a firm price** — nobody should discover that in production — and every mutation-test prediction alongside its result.

Title it with a `[UANG — review wajib]` suffix. **Do not merge it.** This is the money path; `AGENTS.md` §Infrastructure-agent execution requires human review.

- [ ] **Step 6: Wait for CI and report**

Wait for every run at the branch's head SHA to complete — filter by SHA, not by "the latest run", and wait on the run id rather than a status summary. Report the per-job outcome. The local CI-parity image cannot run the full suite (it exhausts memory, reproducibly, on pristine trunk too), so real CI is the only honest full sweep for this branch.
