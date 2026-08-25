# Phase 2 Workstream 1: Automated Regression Gap-Closing Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close the two remaining gaps in Phase 2 Workstream 1 of the approved release-readiness roadmap — real k6-based load-testing tooling with a genuine AC4 (grave-search <500ms@100k) certification, and two new two-connection concurrency-race tests for payment-webhook replay and outbox duplicate delivery, the two domain-risk areas the roadmap names that the existing 3 narrow race tests don't cover.

**Architecture:** A new, dedicated 100k-row synthetic dataset generator (Artisan command, bulk-insert — not Eloquent-per-row, which would be too slow at this volume) backs both a direct-query AC4 benchmark command (real p95 numbers, safely runnable in CI since it's single-connection and doesn't compete for shared-host resources) and a new k6 test suite (`tests/load/`) covering the four documented load profiles, wired into CI at reduced, CI-runner-safe scale. Two new test files extend the existing `pgsql_race`-connection-swap pattern (`ReservePlotTwoConnectionTest.php`) — one at HTTP level (payment webhook replay), one at genuinely-overlapping-transaction level (outbox `SKIP LOCKED`, a strictly more rigorous proof than anything in this codebase currently has).

**Tech Stack:** k6 (`grafana/k6-action` in CI), PHP/Laravel Artisan commands, PostgreSQL 18 (`pg_trgm`), the existing PHPUnit `pgsql_race` two-connection convention.

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` (the approved release-readiness roadmap — this plan implements the performance-tooling and concurrency-test halves of Phase 2 Workstream 1; the mobile-viewport-Playwright third of that workstream is already done, merged via the Phase 1 UAT-pass plan). Also argues from `docs/operations/performance-and-capacity.md` (the full target/profile/dataset spec) and `docs/testing/test-strategy.md` §3/§7 (the domain-risk test list).

## Global Constraints

- Every claim written into `docs/testing/release-gates.md` must cite a real, currently-passing test or a real, directly-measured number — never narrated without evidence, per `AGENTS.md` §Infrastructure-agent execution: "Never report `PASS` for a check that was not executed."
- `docs/operations/performance-and-capacity.md` §9: "Load generation must run from a separate machine," and full-scale Profile B–D certification (150 VUs, the full 100k dataset under concurrent load) "should use an isolated time window or temporary environment" — the shared dev/staging host is explicitly not accepted as production-capacity evidence. This plan's CI-run k6 job satisfies "separate machine" structurally (a GitHub Actions runner is not the shared host), but runs at a reduced, CI-safe scale documented in Task 3 — full-scale Profile B–D certification is explicitly deferred to Phase 3 (production graduation), not silently declared done here.
- `docs/operations/performance-and-capacity.md` §5: "Do not benchmark on empty/local SQLite data" — every benchmark task in this plan runs against real PostgreSQL 18 with the real generated dataset, never SQLite.
- New concurrency tests follow the established `pgsql_race` convention exactly (`tests/Feature/Domain/PlotReservation/ReservePlotTwoConnectionTest.php` is the reference): skip on non-`pgsql`, no `RefreshDatabase` trait (real commits, outside the transaction wrapper), `config(['database.connections.pgsql_race' => config('database.connections.pgsql')])`, restore the original default connection and `DB::purge('pgsql_race')` in a `finally` block, and a **load-bearing trailing `Artisan::call('migrate:fresh')`** so committed rows don't leak into later `RefreshDatabase` test classes in the same PHPUnit process.
- New Artisan commands follow this repo's established shape (`app/Console/Commands/CareGenerateCyclesCommand.php` is the reference): `final class ...Command extends Command`, `protected $signature`, `protected $description`, `handle(): int` returning `self::SUCCESS`.
- `ci/verify-docs.sh` must pass after every task that touches `docs/testing/release-gates.md`.
- No fabricated data: every synthetic name/address/price in the dataset generator is clearly a generated fixture (matching `CemeteryExampleData`'s own "Contoh ..." naming convention), never presented as real.

---

## Context for every task below (read once)

**Why a dedicated dataset generator, not `CemeteryExampleData`:** that class's `graveRecords()`/`seed()` methods produce ~15 hand-authored rows across 10 cemeteries for functional tests — deliberately small and precise. `performance-and-capacity.md` §5 asks for "100,000 grave records... at least 100 cemeteries across five launch areas" — two orders of magnitude larger, and needs bulk `DB::table()->insert()` in chunks (not one Eloquent model + one `booted()` normalizer call per row, which would take unacceptably long at this volume). This plan's generator is a new, separate command; it does not modify `CemeteryExampleData`.

**Why the AC4 benchmark is a direct Artisan command, not a k6 script:** `performance-and-capacity.md`'s AC4 target ("Grave fuzzy search: below 500ms at 100,000 records") is a *query-latency* target, not a *concurrent-user-load* target — `GraveRegistryPublicQuery::search()` runs behind a Livewire component (`app/Livewire/Public/Renewal/GraveSearch.php`, route `/perpanjangan/cari`), and driving Livewire's AJAX wire-protocol precisely from k6 is real, separate engineering complexity this plan does not take on. A single-connection, many-iteration Artisan command measuring `GraveRegistryPublicQuery::search()`'s own wall-clock p50/p95/p99 directly is simpler, more reliable, and — because it never simulates concurrent *users*, only repeated *queries* — doesn't compete for shared-host resources the way load generation does, so it can safely run against the **full real 100k dataset inside CI**, producing a **real, non-deferred AC4 certification** (this is a strictly better outcome than treating AC4 as blocked on the same "separate machine" constraint that genuinely does block Profile B–D).

**The grave-search index has a known, already-documented gap.** `database/migrations/2026_08_08_100000_create_grave_records_table.php`'s own doc block explains: the query's `similarity(...) >= threshold` clause is a bare function call, not an indexable `pg_trgm` operator, and because it's OR'd with the `LIKE` clause in one `WHERE` group, the whole predicate becomes a non-indexed filter over whatever the `cemetery_id`/`block`/`death_date` composite index already narrowed to. Task 1's benchmark may well *reveal* this as a real bottleneck at scale. That is a legitimate, expected outcome — **this plan measures and honestly records whatever the benchmark finds; it does not redesign the index or rewrite the query.** A slow result is real evidence for `release-gates.md`, and a fast-enough result (plausible, since the composite index already scopes to one cemetery's rows before the residual filter runs, and cemetery sizes are realistically ~1,000 rows each at 100 cemeteries/100k records) is equally real evidence. Either way, Task 4 records the actual number, not an assumption.

**Existing "3 narrow two-connection tests"** (do not duplicate, cited for context): `tests/Feature/Domain/Visitation/RequestVisitationTwoConnectionTest.php`, `tests/Feature/Domain/PlotReservation/ReservePlotTwoConnectionTest.php`, `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTwoConnectionTest.php`. Plot-reservation races are judged adequately covered by the existing test (it already proves the exact "specific-plot reservation race" `performance-and-capacity.md` Profile D names — confirmed directly: no separate "specific-plot" feature flag exists anywhere in the codebase; `ReservePlot` claiming one exact plot atomically *is* what "specific-plot reservation" means here). This plan does not add a fourth plot-reservation test.

**Payment replay and outbox duplicate delivery genuinely lack this rigor today**, confirmed by direct investigation:
- `tests/Feature/Payment/WebhookReceiverTest.php` (800 lines) thoroughly tests duplicate-delivery handling, but every case calls the HTTP endpoint sequentially within one PHPUnit-managed connection/transaction — never a genuinely separate database session.
- `tests/Feature/Outbox/OutboxPublisherClaimTest.php`'s own class doc block states this outright: *"A genuinely separate second database connection/session... cannot see that outer transaction's uncommitted rows... Building genuine cross-session proof would need a non-transactional test harness... out of scope for this 'minimum outbox' batch."* Its own `test_two_sequential_publisher_runs_never_double_claim_the_same_row` is explicitly labelled *"a weaker, sequential-only proof — real overlapping-session SKIP LOCKED behaviour is NOT exercised here."*

Tasks 2 and 3 below close exactly those two gaps, each producing the specific, rigorous proof its neighboring test file's own doc block already says is missing.

---

### Task 1: 100k-row synthetic grave-registry dataset generator

**Files:**
- Create: `app/Console/Commands/GenerateGraveRegistryLoadDatasetCommand.php`
- Test: `tests/Feature/Console/GenerateGraveRegistryLoadDatasetCommandTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: `php artisan bench:generate-grave-dataset {--cemeteries=100} {--records=100000} {--chunk=1000}` — a real, idempotent-by-truncation command later tasks invoke by name (Task 4's benchmark command, Task 5's CI wiring). Populates real `cemeteries` and `grave_records` rows via bulk insert.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class GenerateGraveRegistryLoadDatasetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_generates_the_requested_cemetery_and_record_counts(): void
    {
        $exitCode = Artisan::call('bench:generate-grave-dataset', [
            '--cemeteries' => 5,
            '--records' => 50,
            '--chunk' => 10,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(5, Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->count());
        $this->assertSame(50, GraveRecord::query()->count());
    }

    public function test_generated_records_have_a_real_normalized_name_and_are_distributed_across_cemeteries(): void
    {
        Artisan::call('bench:generate-grave-dataset', [
            '--cemeteries' => 5,
            '--records' => 50,
            '--chunk' => 10,
        ]);

        $record = GraveRecord::query()->first();
        $this->assertNotNull($record);
        $this->assertNotSame('', $record->deceased_name_normalized);
        $this->assertSame(5, GraveRecord::query()->distinct('cemetery_id')->count('cemetery_id'));
    }

    public function test_it_is_re_runnable_and_replaces_rather_than_accumulates(): void
    {
        Artisan::call('bench:generate-grave-dataset', ['--cemeteries' => 3, '--records' => 30, '--chunk' => 10]);
        Artisan::call('bench:generate-grave-dataset', ['--cemeteries' => 3, '--records' => 30, '--chunk' => 10]);

        $this->assertSame(3, Cemetery::query()->where('name', 'like', 'Contoh TPU Beban %')->count());
        $this->assertSame(30, GraveRecord::query()->count());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Console/GenerateGraveRegistryLoadDatasetCommandTest.php`
Expected: FAIL — `Command "bench:generate-grave-dataset" is not defined.`

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `php artisan bench:generate-grave-dataset` — builds the synthetic dataset
 * `docs/operations/performance-and-capacity.md` §5 specifies for load/AC4
 * benchmarking: "100,000 grave records... at least 100 cemeteries across
 * five launch areas." Deliberately separate from
 * `App\Support\ExampleData\CemeteryExampleData`, whose ~15-row fixture set
 * is sized for functional tests, not bulk-load benchmarking.
 *
 * Bulk `DB::table()->insert()` in chunks, not one Eloquent model per row —
 * `GraveRecord::booted()`'s `saving` hook (name normalization, access-mode
 * validation) never fires on a bulk insert, so this command computes
 * `deceased_name_normalized` itself via the same
 * `GraveNameNormalizer::normalize()` the model uses, and writes
 * `access_mode` explicitly on every row (the column default only applies
 * to a raw insert that OMITS the column, and this command never does).
 *
 * Re-running replaces rather than accumulates: every cemetery this command
 * creates carries the fixed, greppable name prefix "Contoh TPU Beban ",
 * making its own rows (and only its own rows) identifiable for deletion
 * before regenerating — this command never touches a cemetery it did not
 * itself create.
 */
final class GenerateGraveRegistryLoadDatasetCommand extends Command
{
    private const string CEMETERY_NAME_PREFIX = 'Contoh TPU Beban ';

    /**
     * Deliberately fake, clearly-marked Indonesian given/family name
     * components — combined and repeated with deterministic index-based
     * variation (never claimed as real identity data, matching this
     * repo's "THIS IS DUMMY DATA" convention).
     */
    private const array GIVEN_NAMES = [
        'Siti', 'Budi', 'Ahmad', 'Dewi', 'Agus', 'Rina', 'Hendra', 'Sri',
        'Wahyu', 'Yuni', 'Bambang', 'Ani', 'Joko', 'Fitri', 'Slamet', 'Lestari',
    ];

    private const array FAMILY_NAMES = [
        'Wijaya', 'Santoso', 'Kusuma', 'Pratama', 'Saputra', 'Hidayat',
        'Gunawan', 'Setiawan', 'Rahman', 'Suryanto',
    ];

    protected $signature = 'bench:generate-grave-dataset
        {--cemeteries=100 : Number of synthetic cemeteries to generate}
        {--records=100000 : Total grave records to distribute across them}
        {--chunk=1000 : Rows per bulk-insert statement}';

    protected $description = 'Generate a synthetic grave-registry dataset for AC4/load benchmarking (docs/operations/performance-and-capacity.md §5).';

    public function handle(): int
    {
        $cemeteryCount = (int) $this->option('cemeteries');
        $recordCount = (int) $this->option('records');
        $chunkSize = (int) $this->option('chunk');

        $this->info("Removing any previously generated benchmark cemeteries...");
        $previousIds = DB::table('cemeteries')
            ->where('name', 'like', self::CEMETERY_NAME_PREFIX.'%')
            ->pluck('id');
        DB::table('grave_records')->whereIn('cemetery_id', $previousIds)->delete();
        DB::table('cemeteries')->whereIn('id', $previousIds)->delete();

        $this->info("Generating {$cemeteryCount} cemeteries...");
        $cemeteryIds = $this->generateCemeteries($cemeteryCount);

        $this->info("Generating {$recordCount} grave records across them...");
        $this->generateGraveRecords($cemeteryIds, $recordCount, $chunkSize);

        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function generateCemeteries(int $count): array
    {
        $cityCodes = LaunchCityCode::KNOWN_CODES;
        $now = now();
        $ids = [];
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $id = (string) Str::uuid();
            $ids[] = $id;
            $city = $cityCodes[$i % count($cityCodes)];

            $rows[] = [
                'id' => $id,
                'type' => $i % 3 === 0 ? CemeteryType::TPS : CemeteryType::TPU,
                'publication_status' => CemeteryPublicationStatus::PUBLISHED,
                'name' => self::CEMETERY_NAME_PREFIX.($i + 1),
                'slug' => Str::slug(self::CEMETERY_NAME_PREFIX.($i + 1)).'-'.Str::lower(Str::random(6)),
                'city' => $city,
                'address' => 'Jl. Contoh Pemakaman No. '.($i + 1).', '.$city,
                'price_currency' => 'IDR',
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        collect($rows)->chunk(500)->each(
            fn ($chunk) => DB::table('cemeteries')->insert($chunk->all())
        );

        return $ids;
    }

    /**
     * @param  list<string>  $cemeteryIds
     */
    private function generateGraveRecords(array $cemeteryIds, int $totalRecords, int $chunkSize): void
    {
        $now = now();
        $buffer = [];
        $written = 0;
        $bar = $this->output->createProgressBar($totalRecords);

        for ($i = 0; $i < $totalRecords; $i++) {
            $cemeteryId = $cemeteryIds[$i % count($cemeteryIds)];
            $given = self::GIVEN_NAMES[$i % count(self::GIVEN_NAMES)];
            $family = self::FAMILY_NAMES[($i * 7) % count(self::FAMILY_NAMES)];

            // Deterministic "spelling variation" for roughly one in five
            // rows — performance-and-capacity.md §5 asks for these
            // explicitly, and a fuzzy-search benchmark that never
            // exercises the similarity() branch at all would be
            // measuring the wrong query shape.
            $name = $i % 5 === 0
                ? $given.' '.$family.'h' // a trailing-letter typo variant
                : $given.' '.$family;

            $normalized = GraveNameNormalizer::normalize($name);

            $year = 2010 + ($i % 15);
            $month = ($i % 12) + 1;
            $day = ($i % 28) + 1;

            $buffer[] = [
                'id' => (string) Str::uuid(),
                'cemetery_id' => $cemeteryId,
                'deceased_name' => $name,
                'deceased_name_normalized' => $normalized,
                'block' => sprintf('BLOK-%02d', ($i % 20) + 1),
                'death_date' => sprintf('%d-%02d-%02d', $year, $month, $day),
                'due_date' => sprintf('%d-%02d-%02d', $year + 10, $month, $day),
                'access_mode' => GraveRecordAccessMode::OPEN,
                'source' => 'bench-generator',
                'source_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($buffer) >= $chunkSize) {
                DB::table('grave_records')->insert($buffer);
                $written += count($buffer);
                $bar->advance(count($buffer));
                $buffer = [];
            }
        }

        if ($buffer !== []) {
            DB::table('grave_records')->insert($buffer);
            $written += count($buffer);
            $bar->advance(count($buffer));
        }

        $bar->finish();
        $this->newLine();
        $this->info("Wrote {$written} grave records.");
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Console/GenerateGraveRegistryLoadDatasetCommandTest.php`
Expected: PASS (3 tests). This runs against SQLite locally per `phpunit.xml` — the bulk-insert path and normalization work identically there; only `pg_trgm`/`similarity()` (irrelevant to this command) is Postgres-only.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/GenerateGraveRegistryLoadDatasetCommand.php tests/Feature/Console/GenerateGraveRegistryLoadDatasetCommandTest.php
git commit -m "feat(bench): add the 100k-row grave-registry load-dataset generator"
```

---

### Task 2: AC4 grave-search benchmark command — real p50/p95/p99 against the real dataset

**Files:**
- Create: `app/Console/Commands/BenchGraveSearchCommand.php`
- Test: `tests/Feature/Console/BenchGraveSearchCommandTest.php`

**Interfaces:**
- Consumes: `bench:generate-grave-dataset` (Task 1) — this command's own test and CI usage run the generator first.
- Produces: `php artisan bench:grave-search {--iterations=200}` — prints p50/p95/p99 in milliseconds and exits `self::FAILURE` (1) if p95 exceeds `performance-and-capacity.md`'s 500ms AC4 target, `self::SUCCESS` (0) otherwise. Task 5 (CI wiring) and Task 4 (evidence recording) both invoke this by name and parse its output.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BenchGraveSearchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_real_percentiles_and_succeeds_when_fast_enough(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => 'TPU',
            'publication_status' => 'published',
            'name' => 'TPU Uji Benchmark',
            'slug' => 'tpu-uji-benchmark-'.Str::lower(Str::random(6)),
            'city' => 'JAKARTA',
            'address' => 'Jl. Uji No. 1',
            'published_at' => now(),
        ]);

        for ($i = 0; $i < 20; $i++) {
            GraveRecord::query()->create([
                'cemetery_id' => $cemetery->getKey(),
                'deceased_name' => 'Contoh Nama '.$i,
                'block' => 'BLOK-01',
                'death_date' => '2020-01-01',
                'access_mode' => GraveRecordAccessMode::OPEN,
                'source' => 'test',
            ]);
        }

        $exitCode = Artisan::call('bench:grave-search', ['--iterations' => 10]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('p50', $output);
        $this->assertStringContainsString('p95', $output);
        $this->assertStringContainsString('p99', $output);
    }

    public function test_it_fails_the_command_when_p95_exceeds_the_ac4_target(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => 'TPU',
            'publication_status' => 'published',
            'name' => 'TPU Uji Benchmark Lambat',
            'slug' => 'tpu-uji-benchmark-lambat-'.Str::lower(Str::random(6)),
            'city' => 'JAKARTA',
            'address' => 'Jl. Uji No. 2',
            'published_at' => now(),
        ]);

        GraveRecord::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'deceased_name' => 'Contoh Nama',
            'block' => 'BLOK-01',
            'death_date' => '2020-01-01',
            'access_mode' => GraveRecordAccessMode::OPEN,
            'source' => 'test',
        ]);

        $exitCode = Artisan::call('bench:grave-search', ['--iterations' => 5, '--fail-threshold-ms' => -1]);

        $this->assertSame(1, $exitCode);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Console/BenchGraveSearchCommandTest.php`
Expected: FAIL — `Command "bench:grave-search" is not defined.`

- [ ] **Step 3: Write the command**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * `php artisan bench:grave-search` — the real, executed AC4 certification
 * (`docs/operations/performance-and-capacity.md` §2: "Grave fuzzy search:
 * below 500ms at 100,000 records"). Measures
 * `GraveRegistryPublicQuery::search()`'s own wall-clock latency directly,
 * across real cemeteries/terms already in the database (run
 * `bench:generate-grave-dataset` first for a real 100k-record certification
 * run) — see this plan's own Context section for why a direct query
 * benchmark, not a k6/HTTP benchmark, is the right tool for this
 * specific, non-concurrent latency target.
 *
 * Picks a real search term from an actual row in the LARGEST cemetery by
 * record count (the worst case for the residual LIKE/similarity() filter
 * described in `2026_08_08_100000_create_grave_records_table.php`'s own
 * doc block), not a synthetic term — this measures the real query shape
 * the app runs, including cache-cold Postgres query planning on the first
 * iteration.
 */
final class BenchGraveSearchCommand extends Command
{
    protected $signature = 'bench:grave-search
        {--iterations=200 : Number of search calls to time}
        {--fail-threshold-ms=500 : p95 threshold in milliseconds; exceeding it fails the command}';

    protected $description = 'Measure GraveRegistryPublicQuery::search() p50/p95/p99 against the current database (AC4 certification).';

    public function handle(): int
    {
        $iterations = (int) $this->option('iterations');
        $thresholdMs = (int) $this->option('fail-threshold-ms');

        $largestCemetery = DB::table('grave_records')
            ->select('cemetery_id', DB::raw('count(*) as record_count'))
            ->groupBy('cemetery_id')
            ->orderByDesc('record_count')
            ->first();

        if ($largestCemetery === null) {
            $this->error('No grave_records rows found — run `php artisan bench:generate-grave-dataset` first.');

            return self::FAILURE;
        }

        $sampleRecord = DB::table('grave_records')
            ->where('cemetery_id', $largestCemetery->cemetery_id)
            ->first();

        $searchTerm = mb_substr((string) $sampleRecord->deceased_name, 0, 4);

        $this->info(sprintf(
            'Benchmarking against cemetery %s (%d records), search term "%s", %d iterations...',
            $largestCemetery->cemetery_id,
            $largestCemetery->record_count,
            $searchTerm,
            $iterations,
        ));

        $timingsMs = [];

        for ($i = 0; $i < $iterations; $i++) {
            $criteria = new GraveSearchCriteria(
                cemeteryId: (string) $largestCemetery->cemetery_id,
                name: $searchTerm,
                block: '',
                deathDate: '',
            );

            $start = hrtime(true);
            GraveRegistryPublicQuery::search($criteria);
            $elapsedMs = (hrtime(true) - $start) / 1_000_000;

            $timingsMs[] = $elapsedMs;
        }

        sort($timingsMs);

        $p50 = $this->percentile($timingsMs, 50);
        $p95 = $this->percentile($timingsMs, 95);
        $p99 = $this->percentile($timingsMs, 99);

        $this->table(
            ['Metric', 'Value (ms)'],
            [
                ['p50', number_format($p50, 2)],
                ['p95', number_format($p95, 2)],
                ['p99', number_format($p99, 2)],
                ['record count (largest cemetery)', (string) $largestCemetery->record_count],
                ['iterations', (string) $iterations],
            ]
        );

        if ($p95 > $thresholdMs) {
            $this->error(sprintf(
                'AC4 FAILED: p95 (%.2fms) exceeds the %dms target.',
                $p95,
                $thresholdMs,
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('AC4 PASSED: p95 (%.2fms) is within the %dms target.', $p95, $thresholdMs));

        return self::SUCCESS;
    }

    /**
     * @param  list<float>  $sortedValues
     */
    private function percentile(array $sortedValues, int $percentile): float
    {
        if ($sortedValues === []) {
            return 0.0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;
        $index = max(0, min($index, count($sortedValues) - 1));

        return $sortedValues[$index];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Console/BenchGraveSearchCommandTest.php`
Expected: PASS (2 tests). Locally this runs against SQLite — `GraveRegistryPublicQuery::supportsTrigram()` correctly falls back to the substring path, so the command still measures something real, just not the trigram path; the CI run (Task 5) is what exercises the real `pg_trgm` query against real Postgres.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/BenchGraveSearchCommand.php tests/Feature/Console/BenchGraveSearchCommandTest.php
git commit -m "feat(bench): add the AC4 grave-search p50/p95/p99 benchmark command"
```

---

### Task 3: Payment-webhook-replay two-connection race test

**Files:**
- Create: `tests/Feature/Payment/WebhookReplayTwoConnectionTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Read the existing single-connection duplicate test for the exact fixture/signing conventions**

```bash
sed -n '1,132p' tests/Feature/Payment/WebhookReceiverTest.php
```

Confirm the real values still match what this task uses below: `MERCHANT = 'makam-sandbox'`, `SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh'`, `ENDPOINT = '/api/payments/webhook/'.self::MERCHANT`, and the `body()`/`signature()` helper logic. If any differ, use the real values in Step 2 — do not guess.

- [ ] **Step 2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Genuine cross-session proof for AC7's idempotency guard
 * (`ReceiveWebhook::resolveDuplicate()`), matching the `pgsql_race`
 * two-connection convention `ReservePlotTwoConnectionTest.php` establishes
 * — see that file's own doc block for the pattern this one replicates.
 *
 * `tests/Feature/Payment/WebhookReceiverTest.php` (800 lines) already
 * proves duplicate-delivery handling exhaustively, but every case there
 * runs sequentially inside ONE PHPUnit-managed connection/transaction —
 * never a genuinely separate database session. This test sends the
 * IDENTICAL signed webhook body through TWO real, separate connections:
 * the first commits a real `provider_events` row; the second's insert then
 * collides on the real unique constraint and is routed through
 * `ReceiveWebhook::resolveDuplicate()`'s `lockForUpdate()` re-read — the
 * exact code path AC7 exists to prove, now exercised across a genuine
 * connection boundary rather than within one process's single connection.
 *
 * `RefreshDatabase` cannot be used here for the same reason
 * `ReservePlotTwoConnectionTest.php` cannot: the fixture (and the first
 * delivery's committed row) must be visible to the second connection's own
 * session, which an outer, never-committed test transaction would hide.
 * The trailing `Artisan::call('migrate:fresh')` is therefore load-bearing,
 * not a nicety — see that file's doc block for the verified in-suite
 * failure this prevents.
 */
final class WebhookReplayTwoConnectionTest extends TestCase
{
    private const string MERCHANT = 'makam-sandbox';

    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    public function test_a_second_connections_identical_delivery_is_recognized_as_a_duplicate_not_a_second_effect(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cross-connection duplicate resolution is only meaningful on PostgreSQL.');
        }

        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_signing_secrets' => [self::SECRET],
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_tokens' => [],
            'payment.webhook.allow_shared_token' => false,
            'payment.webhook.merchants' => [self::MERCHANT],
            'payment.webhook.replay_window_seconds' => 300,
        ]);

        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $id = 'msg_race_01';
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $body = json_encode([
            'event_type' => 'payment.completed',
            'data' => [
                'payment_id' => 'pay_race_test',
                'order_id' => 'INV-2026-RACE-01',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'status' => 'completed',
                'payment_method' => 'QRIS',
                'completed_at' => '2026-08-09T09:59:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR);

        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $timestamp,
            'HTTP_svix-signature' => $signature,
        ];

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $statuses = [];

        try {
            foreach (['pgsql', 'pgsql_race'] as $connectionName) {
                DB::setDefaultConnection($connectionName);

                $response = $this->call('POST', self::ENDPOINT, [], [], [], $headers, $body);
                $response->assertOk();
                $statuses[] = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        // Exactly one row for this provider_event_id, regardless of which
        // of the two connections' deliveries it was — the DB unique
        // constraint on (provider, provider_event_id) is the guarantee,
        // and this asserts its real, structural effect rather than a
        // status string.
        $this->assertSame(
            1,
            ProviderEvent::query()
                ->where('provider', PaymentProviders::SUMOPOD_SANDBOX)
                ->where('provider_event_id', $id)
                ->count(),
        );

        // Wipe the committed rows so later RefreshDatabase test classes in
        // this same process start from an empty, migrated schema — see
        // this file's own doc block.
        Artisan::call('migrate:fresh');
    }
}
```

- [ ] **Step 3: Run the test — needs real Postgres, so run it inside CI's own container setup or an equivalent local Postgres**

This test `markTestSkipped()`s on any non-`pgsql` driver, so a bare local `vendor/bin/phpunit` run (SQLite default per `phpunit.xml`) will report it skipped, not passed — that is expected, not a failure to investigate. Run it for real against Postgres using this session's own established local-repro pattern (a disposable Postgres 18 container, the pinned app image, this worktree bind-mounted, migrated with `DB_CONNECTION=pgsql`) or wait for the real CI run this branch's PR triggers. Either way, do not report this test as passing without having actually seen it pass against real Postgres — a SQLite "skipped" result proves nothing about the behavior this test exists to check.

Run: `DB_CONNECTION=pgsql vendor/bin/phpunit tests/Feature/Payment/WebhookReplayTwoConnectionTest.php`
Expected: PASS (1 test, not skipped) against real Postgres.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Payment/WebhookReplayTwoConnectionTest.php
git commit -m "test(payment): add a genuine cross-connection webhook-replay race test"
```

---

### Task 4: Outbox duplicate-delivery two-connection race test — genuine overlapping-transaction `SKIP LOCKED` proof

**Files:**
- Create: `tests/Feature/Outbox/OutboxPublisherClaimTwoConnectionTest.php`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing later tasks depend on.

This is the one test in this plan that is NOT a sequential commit-then-observe pattern like the other 4 (Task 3 included) — proving `SELECT ... FOR UPDATE SKIP LOCKED` for real requires two transactions genuinely overlapping in time, not one committing before the other starts. This is exactly the harness `OutboxPublisherClaimTest.php`'s own doc block says this codebase doesn't have yet.

- [ ] **Step 1: Read `OutboxPublisher`'s exposed claim query and the existing claim test's fixture helper**

```bash
sed -n '1,177p' app/Platform/Outbox/OutboxPublisher.php
sed -n '1,67p' tests/Feature/Outbox/OutboxPublisherClaimTest.php
```

Confirm `OutboxPublisher::CLAIM_QUERY` and `STALE_CLAIM_SECONDS` still match what this task uses below, and confirm `Outbox::record()`'s real parameter names (`eventName`, `eventVersion`, `aggregateType`, `aggregateId`, `data`, `classification`). If any differ, use the real signatures — do not guess.

- [ ] **Step 2: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The genuine cross-session `SELECT ... FOR UPDATE SKIP LOCKED` proof
 * `OutboxPublisherClaimTest.php`'s own doc block names as missing:
 * "Building genuine cross-session proof would need a non-transactional
 * test harness... out of scope for this 'minimum outbox' batch." This is
 * that harness.
 *
 * `RefreshDatabase` cannot be used, for the same reason that class's doc
 * block explains: a second real connection cannot see an outer test
 * transaction's uncommitted rows under Postgres MVCC. This test commits
 * real fixture rows, then a load-bearing trailing `Artisan::call
 * ('migrate:fresh')` wipes them — matching every other `*TwoConnection*`
 * test in this codebase.
 *
 * Unlike `ReservePlotTwoConnectionTest.php`'s sequential commit-then-
 * observe pattern, THIS test genuinely overlaps two transactions: the
 * first connection opens a transaction and runs `OutboxPublisher::
 * CLAIM_QUERY` directly (the exact SQL `OutboxPublisher::claim()` uses,
 * exposed as a public constant specifically so a test can run it without
 * duplicating it — see that class's own doc block), WITHOUT committing —
 * so the claimed row(s) stay locked. Only THEN does the second connection
 * run the identical query, while the first connection's transaction is
 * still open, proving `SKIP LOCKED` genuinely excludes an in-flight
 * claim, not merely an already-committed one.
 */
final class OutboxPublisherClaimTwoConnectionTest extends TestCase
{
    public function test_a_second_connections_concurrent_claim_never_overlaps_the_firsts_in_flight_claim(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SELECT ... FOR UPDATE SKIP LOCKED is only meaningful on real Postgres.');
        }

        $rowIds = [];

        for ($i = 0; $i < 4; $i++) {
            $event = Outbox::record(
                eventName: 'fixture.race_test.v1',
                eventVersion: 1,
                aggregateType: 'fixture',
                aggregateId: $i,
                data: ['note' => 'outbox-race-test'],
                classification: OutboxClassification::Internal,
            );
            $rowIds[] = $event->getKey();
        }

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');

        try {
            // Connection A: claim 2 of the 4 rows and DELIBERATELY do not
            // commit yet — this is the "in-flight claim" SKIP LOCKED must
            // protect against.
            DB::setDefaultConnection('pgsql');
            DB::beginTransaction();

            $staleBefore = CarbonImmutable::now()->subSeconds(OutboxPublisher::STALE_CLAIM_SECONDS);
            $now = CarbonImmutable::now();

            $connectionAClaimed = DB::select(OutboxPublisher::CLAIM_QUERY, [$staleBefore, $now, 2]);
            $connectionAIds = array_map(static fn (object $row): string => (string) $row->id, $connectionAClaimed);

            $this->assertCount(2, $connectionAIds, 'connection A should claim exactly 2 of the 4 unclaimed rows');

            // Connection B: claim while A's transaction is STILL OPEN.
            // SKIP LOCKED must exclude A's 2 locked-but-uncommitted rows
            // and return only the 2 A did not touch.
            DB::setDefaultConnection('pgsql_race');

            $connectionBClaimed = DB::select(OutboxPublisher::CLAIM_QUERY, [$staleBefore, $now, 4]);
            $connectionBIds = array_map(static fn (object $row): string => (string) $row->id, $connectionBClaimed);

            $this->assertCount(2, $connectionBIds, 'connection B should see only the 2 rows A did not lock');
            $this->assertEmpty(
                array_intersect($connectionAIds, $connectionBIds),
                'the two connections must never claim the same row while A\'s transaction is still open',
            );

            $combinedIds = collect(array_merge($connectionAIds, $connectionBIds))->sort()->values()->all();
            $expectedIds = collect($rowIds)->sort()->values()->all();
            $this->assertSame($expectedIds, $combinedIds, 'together, the two connections should account for all 4 rows');

            // Now commit A — releasing its lock.
            DB::setDefaultConnection('pgsql');
            DB::commit();
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        // Wipe the committed rows so later RefreshDatabase test classes in
        // this same process start from an empty, migrated schema — see
        // this file's own doc block.
        Artisan::call('migrate:fresh');
    }
}
```

- [ ] **Step 3: Run the test — needs real Postgres**

Same caveat as Task 3 Step 3: this `markTestSkipped()`s on SQLite. Run it against real Postgres (this session's established local Docker pattern, or CI) before trusting it.

Run: `DB_CONNECTION=pgsql vendor/bin/phpunit tests/Feature/Outbox/OutboxPublisherClaimTwoConnectionTest.php`
Expected: PASS (1 test, not skipped) against real Postgres. If connection B's claim ever returns fewer than 2 rows or overlaps connection A's, that is a genuine finding about `SKIP LOCKED`'s real behavior in this environment — investigate and report it accurately rather than adjusting the assertion to make it pass.

- [ ] **Step 4: Commit**

```bash
git add tests/Feature/Outbox/OutboxPublisherClaimTwoConnectionTest.php
git commit -m "test(outbox): add a genuine overlapping-transaction SKIP LOCKED race test"
```

---

### Task 5: k6 load-testing suite + CI wiring, at a documented CI-safe reduced scale

**Files:**
- Create: `tests/load/profile-a-normal-launch.js`
- Create: `tests/load/README.md`
- Modify: `.github/workflows/ci.yml` (new job)

**Interfaces:**
- Consumes: `bench:generate-grave-dataset` and `bench:grave-search` (Tasks 1–2), invoked as CI steps.
- Produces: nothing later tasks in this plan depend on (Task 6 cites this task's CI run as evidence).

**The CI-safe scale, reasoned through, not invented:** `performance-and-capacity.md` Profile A specifies 50 concurrent virtual users against mixed homepage/directory/FAQ/wizard traffic. GitHub-hosted public runners are 4 vCPU/16GB, and this same runner class already runs this repo's Playwright browser suite (a real, heavier Chromium-driven job) successfully in the existing `browser-test` CI job. A plain HTTP load generator (k6) is far lighter than a real browser, so 50 VUs is very plausibly sustainable — but this task deliberately runs at **10 VUs for 30 seconds** (one-fifth of Profile A) against real, cacheable, unauthenticated GET routes only (`/`, the cemetery directory, the FAQ index) for two reasons: (1) it keeps this CI job's own resource footprint small alongside the Playwright job that already runs in the same workflow, and (2) proving the tooling, thresholds, and CI wiring genuinely work end-to-end does not require running at the full documented scale — it requires running at a REAL scale and reporting the REAL result, which 10 VUs does. Full Profile A (50 VUs) and Profiles B–D (150 VUs, the 10k-row import, concurrency invariants) are explicitly deferred to Phase 3, per this plan's own Global Constraints — not attempted here at a secretly-reduced scale disguised as the real thing.

- [ ] **Step 1: Write the k6 script**

Create `tests/load/profile-a-normal-launch.js`:

```javascript
import http from 'k6/http';
import { check } from 'k6';

/**
 * Profile A (normal launch) at CI-safe reduced scale — see
 * tests/load/README.md and this plan's Task 5 for why 10 VUs/30s rather
 * than the documented 50 VUs, and why this covers only cacheable,
 * unauthenticated GET routes (homepage, cemetery directory, FAQ index),
 * not "concurrent wizard saves" (Profile B) or authenticated admin
 * traffic. Thresholds mirror docs/operations/performance-and-capacity.md
 * §3's real target table for the operations this script actually
 * exercises.
 */
export const options = {
    scenarios: {
        normal_launch_reduced: {
            executor: 'constant-vus',
            vus: 10,
            duration: '30s',
        },
    },
    thresholds: {
        'http_req_duration{route:homepage}': ['p(95)<500'],
        'http_req_duration{route:cemetery_directory}': ['p(95)<500'],
        'http_req_duration{route:faq_index}': ['p(95)<500'],
        http_req_failed: ['rate<0.01'],
    },
};

const BASE_URL = __ENV.K6_BASE_URL || 'http://127.0.0.1:8080';

export default function () {
    const homepage = http.get(`${BASE_URL}/`, { tags: { route: 'homepage' } });
    check(homepage, { 'homepage is 200': (r) => r.status === 200 });

    const directory = http.get(`${BASE_URL}/pemakaman`, { tags: { route: 'cemetery_directory' } });
    check(directory, { 'cemetery directory is 200': (r) => r.status === 200 });

    const faq = http.get(`${BASE_URL}/faq`, { tags: { route: 'faq_index' } });
    check(faq, { 'faq index is 200': (r) => r.status === 200 });
}
```

- [ ] **Step 2: Confirm the three routes are real before trusting the script**

```bash
grep -n "Route::get('/', \|Route::get('/pemakaman\|Route::get('/faq" routes/web.php
```

Expected: all three routes exist. If any path differs from what's used above (e.g. the cemetery directory or FAQ index route is spelled differently), correct the script in Step 1 to use the real path — do not guess a plausible-sounding one.

- [ ] **Step 3: Write the load-testing README**

Create `tests/load/README.md`:

```markdown
# Load Testing (k6)

Scripts here implement `docs/operations/performance-and-capacity.md`'s
documented load profiles using [k6](https://k6.io/).

## What's here

- `profile-a-normal-launch.js` — Profile A (normal launch), run in CI at a
  reduced, CI-runner-safe scale (10 VUs/30s against 3 unauthenticated GET
  routes). See the script's own header comment and
  `docs/superpowers/plans/2026-08-22-phase2-regression-gap-closing.md`
  Task 5 for exactly why this is reduced and what the reduction costs.

## What's NOT here yet, deliberately

Full-scale Profile A (50 VUs), Profile B (150 VUs, campaign/burst),
Profile C (10k-row import + concurrent critical webhook traffic), and
Profile D (concurrency invariants under load) all require, per
`performance-and-capacity.md` §9, "an isolated time window or temporary
environment" and load generation "from a separate machine" — the shared
dev/staging host this repo runs CI/UAT against is explicitly not accepted
as production-capacity evidence. These are deferred to Phase 3 (production
graduation) of the approved release-readiness roadmap, not silently
skipped.

## Running locally

```bash
K6_BASE_URL=http://127.0.0.1:8080 k6 run tests/load/profile-a-normal-launch.js
```

Needs a real served app (`php artisan serve`) pointed at a real Postgres
database with `php artisan bench:generate-grave-dataset` already run — see
`.github/workflows/ci.yml`'s `load-test` job for the exact setup sequence.
```

- [ ] **Step 4: Add the CI job**

In `.github/workflows/ci.yml`, find the `browser-test` job (it already establishes the exact `php artisan serve` + health-check pattern this new job reuses) and add a new sibling job after it, before `build-image`:

```yaml
  # ---------------------------------------------------------------------------
  # Load testing (k6) — docs/operations/performance-and-capacity.md's Profile
  # A, run at a documented reduced scale (see tests/load/README.md for why).
  # Runs the real AC4 grave-search benchmark against a real 100k-row dataset
  # first — that part is NOT reduced, since it is a single-connection query
  # benchmark, not concurrent load generation, and this CI runner genuinely is
  # "a separate machine" from the shared dev/staging host per
  # performance-and-capacity.md §9.
  # ---------------------------------------------------------------------------
  load-test:
    name: Load and performance benchmarks (k6, AC4)
    runs-on: ubuntu-latest
    services:
      postgres:
        image: postgres:18
        env:
          POSTGRES_USER: makam_test
          POSTGRES_PASSWORD: makam_test
          POSTGRES_DB: makam_test
        ports: ["5432:5432"]
        options: >-
          --health-cmd "pg_isready -U makam_test"
          --health-interval 10s --health-timeout 5s --health-retries 10
      redis:
        image: redis:8.2-alpine
        ports: ["6379:6379"]
        options: >-
          --health-cmd "redis-cli ping"
          --health-interval 10s --health-timeout 3s --health-retries 10

    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: "8.5"
          extensions: pdo_pgsql, pgsql, redis, zip, intl, bcmath
          tools: composer:v2
          coverage: none

      - uses: actions/setup-node@v4
        with:
          node-version-file: .nvmrc
          cache: npm

      - name: Install PHP dependencies from lockfile
        run: composer install --no-interaction --prefer-dist --no-progress

      - name: Install npm dependencies from lockfile
        run: npm ci --no-audit --no-fund

      - name: Create the extensions the application depends on
        env:
          PGPASSWORD: makam_test
        run: |
          psql -h 127.0.0.1 -U makam_test -d makam_test \
            -c 'CREATE EXTENSION IF NOT EXISTS pg_trgm;' \
            -c 'CREATE EXTENSION IF NOT EXISTS unaccent;'

      - name: Prepare test environment
        run: |
          cp .env.example .env
          php artisan key:generate
        env:
          DB_CONNECTION: pgsql

      - name: Migrate the application database
        run: php artisan migrate --force
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: makam_test
          DB_USERNAME: makam_test
          DB_PASSWORD: makam_test

      - name: Generate the 100k-row benchmark dataset
        run: php artisan bench:generate-grave-dataset
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: makam_test
          DB_USERNAME: makam_test
          DB_PASSWORD: makam_test

      - name: AC4 — grave search p50/p95/p99 at 100k records
        run: php artisan bench:grave-search
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: makam_test
          DB_USERNAME: makam_test
          DB_PASSWORD: makam_test

      - name: Build assets
        run: npm run build

      - name: Serve the app for k6
        run: |
          PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8080 > /tmp/artisan-serve.log 2>&1 &
          for i in $(seq 1 30); do
            curl -sf http://127.0.0.1:8080/ > /dev/null && break
            sleep 1
          done
          curl -sf http://127.0.0.1:8080/ > /dev/null \
            || { echo "app did not come up"; cat /tmp/artisan-serve.log; exit 1; }
        env:
          DB_CONNECTION: pgsql
          DB_HOST: 127.0.0.1
          DB_PORT: 5432
          DB_DATABASE: makam_test
          DB_USERNAME: makam_test
          DB_PASSWORD: makam_test

      - name: Run k6 — Profile A (reduced scale)
        uses: grafana/k6-action@v0.3.1
        with:
          filename: tests/load/profile-a-normal-launch.js
        env:
          K6_BASE_URL: http://127.0.0.1:8080
```

- [ ] **Step 5: Verify the workflow YAML is syntactically valid**

```bash
python3 -c "import yaml; yaml.safe_load(open('.github/workflows/ci.yml'))" && echo "YAML is valid"
```

Expected: `YAML is valid`. (CI's own `verify-yaml`/`OpenAPI and YAML validation` job also checks this on push — this is a quick local sanity check before committing, not a substitute for that.)

- [ ] **Step 6: Commit**

```bash
git add tests/load/profile-a-normal-launch.js tests/load/README.md .github/workflows/ci.yml
git commit -m "feat(ci): add k6 Profile-A load testing and real AC4 certification to CI"
```

---

### Task 6: Update `docs/testing/release-gates.md` with real evidence from Tasks 1–5

**Files:**
- Modify: `docs/testing/release-gates.md` (§H "Performance/capacity profiles pass or exceptions are formally accepted", and the §C/§G lines Tasks 3–4's new tests speak to)

**Interfaces:**
- Consumes: real CI run output from Task 5's `load-test` job (the AC4 benchmark's actual printed p50/p95/p99 numbers) and Tasks 3–4's real test results.
- Produces: nothing later tasks depend on (final task in this plan).

This task must run AFTER a real CI run of this branch's own PR exists — the evidence it records is the real numbers that run produces, not numbers estimated from local runs. If this task is reached before a CI run exists, push first (this plan's own worktree/PR, per `AGENTS.md`'s Development methodology), wait for `load-test` to complete, then continue.

- [ ] **Step 1: Get the real AC4 numbers from the CI run**

```bash
gh run view --job <load-test-job-id> --log | grep -A 10 "AC4"
```

(Find `<load-test-job-id>` via `gh pr checks <this-PR-number>` once pushed.) Record the real p50/p95/p99 and the pass/fail verdict — do not estimate or round in a way that changes the reported verdict.

- [ ] **Step 2: Update the §H performance box**

Find the line reading `- [ ] Performance/capacity profiles pass or exceptions are formally accepted.`. Replace it with (fill in the REAL numbers from Step 1 — the text below has placeholder brackets ONLY for the numbers this task's own CI run produces, not for anything else; every other word is real and final):

```markdown
- [ ] Performance/capacity profiles pass or exceptions are formally accepted. — AC4 (grave fuzzy search <500ms at 100,000 records) is REALLY certified, not deferred: `php artisan bench:grave-search` (added this pass) measured real p50/p95/p99 against a real 100,000-row dataset (`php artisan bench:generate-grave-dataset`) on real PostgreSQL 18 in CI — p50 [FILL IN]ms, p95 [FILL IN]ms, p99 [FILL IN]ms, verdict [PASS/FAIL] against the 500ms target (CI run: [FILL IN URL]). Profile A (normal launch) has real, CI-verified evidence at a documented reduced scale: `tests/load/profile-a-normal-launch.js` (k6, 10 VUs/30s against homepage/cemetery-directory/FAQ, all thresholds passing in CI) — this is a genuine result at 1/5 of the documented 50-VU scale, not a simulation. Profiles B (150 VUs campaign/burst), C (10k-row import + concurrent critical webhook traffic), and D (concurrency invariants under sustained load), and full-scale Profile A/AC4 certification against real concurrent user traffic, remain NOT TESTED at their documented scale — `performance-and-capacity.md` §9 requires "an isolated time window or temporary environment" and load generation "from a separate machine" for these, which this plan's CI job satisfies only at the reduced scale it actually ran; the shared dev/staging host is explicitly not accepted as production-capacity evidence. Deferred to Phase 3 (production graduation) per the roadmap, not silently skipped — `tests/load/README.md` states this explicitly.
```

- [ ] **Step 3: Update the relevant §C/§G lines for the two new race tests**

Find the §C "Merchant, quote, amount, signature, replay, retry, and concurrency tests pass." line and the §G "Authorization, audit, upload, migration, backup/restore, and rollback tests pass." line (read the file to confirm their exact current text and line numbers before editing — do not assume line numbers from an earlier read). For §C, append (do not remove existing content) a note citing the new webhook-replay race test:

```markdown
 Real cross-connection replay proof added this pass: `tests/Feature/Payment/WebhookReplayTwoConnectionTest.php` (a genuinely separate database session's identical delivery is proven to resolve as a duplicate via `ReceiveWebhook::resolveDuplicate()`, not a second effect) — passes against real Postgres in CI.
```

For §G, if it references outbox/queue concurrency at all, append a note citing the new outbox test:

```markdown
 Real overlapping-transaction proof added this pass: `tests/Feature/Outbox/OutboxPublisherClaimTwoConnectionTest.php` proves `SELECT ... FOR UPDATE SKIP LOCKED` genuinely excludes a row a still-open, uncommitted second connection has claimed (not merely an already-committed one) — passes against real Postgres in CI.
```

If §G's existing text is about a different topic entirely (re-read it — do not assume it's about outbox), add the outbox-race-test citation to whichever `release-gates.md` line most directly covers queue/outbox concurrency instead (§D's channel-failure line, or a nearby §C/§H line naming "queue/outbox" — search the file for "outbox" to find the right one), and note in your commit message which line you actually used and why.

- [ ] **Step 4: Verify the doc gates still pass**

```bash
bash ci/verify-docs.sh
```
Expected: all gates pass.

- [ ] **Step 5: Commit**

```bash
git add docs/testing/release-gates.md
git commit -m "docs(release-gates): record real AC4/Profile-A/replay/outbox-race evidence"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | `bench:generate-grave-dataset` command exists, its 3 tests pass, generates real cemetery/grave_record rows via bulk insert |
| 2 | `bench:grave-search` command exists, its 2 tests pass, reports real p50/p95/p99 and fails correctly on a threshold breach |
| 3 | `WebhookReplayTwoConnectionTest` passes against real Postgres (not skipped) |
| 4 | `OutboxPublisherClaimTwoConnectionTest` passes against real Postgres (not skipped), proving genuine `SKIP LOCKED` exclusion across overlapping transactions |
| 5 | New `load-test` CI job runs `bench:generate-grave-dataset` → `bench:grave-search` → k6 Profile A (reduced scale) and is green on this branch's own PR |
| 6 | `release-gates.md`'s §H performance box carries real, CI-sourced AC4/Profile-A numbers (not estimated); §C/§G carry real citations for the two new race tests; `ci/verify-docs.sh` passes |
| All | Real CI run on this branch's own PR is green (the authoritative gate per `CLAUDE.md`) |

## Execution notes

Tasks 1–2 are sequential (2 consumes 1's command). Tasks 3 and 4 are independent of everything else and of each other. Task 5 consumes Tasks 1–2's commands as CI steps. Task 6 must run last, after a real CI run exists to source real numbers from — it cannot be completed honestly from local-only evidence. Per `AGENTS.md` §Development methodology, this plan runs in its own worktree, gets a task-scoped review after each task and a whole-branch review at the end, and ships as one PR. Nothing in this plan touches security/authorization/financial/production-affecting code in `app/`'s domain logic (only new Artisan commands, new test files, and a new CI job), so it does not fall under `AGENTS.md`'s mandatory-human-review categories structurally — ordinary review discipline applies, though the user should still review the final PR per this repo's normal practice, especially the new CI job's resource footprint and the honest scope-deferral language in Task 6's evidence.
