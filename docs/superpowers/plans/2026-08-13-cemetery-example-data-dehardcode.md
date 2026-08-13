# Cemetery Example-Data De-hardcoding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the ten hardcoded example cemeteries (plus their packages, backfills, and grave records) from the data migrations and centralize them in one documented example-data generator that produces deterministic, per-environment data — preserving the invariant that every environment gets the same example rows without anything ever running `php artisan db:seed`.

**Architecture:** A single pure, deterministic generator class (`App\Support\ExampleData\CemeteryExampleData`) becomes the one place the ten example cemeteries, seven package rows, ten backfill rows, and fourteen grave-record rows are defined. The three existing data migrations become thin shims that call the generator (migrations remain the only data path in CI/deploy — verified below). A seeder calls the same generator for environments/people that run `db:seed`, guarded to be idempotent against an already-migrated database. Tests stop scattering slug/name literals and instead reference the generator's role constants through a new shared `tests/Support/CemeteryFixture` helper.

**Tech Stack:** PHP 8.5, Laravel 13, Illuminate DB query builder, PHPUnit (SQLite locally, PostgreSQL 18 in CI), Pint, PHPStan.

## Global Constraints

- `AGENTS.md` §Development methodology: work happens in an isolated git worktree (`.worktrees/`), never on the working checkout; plan committed at `docs/superpowers/plans/<date>-<slug>.md` before implementation (this research plan is written to `/home/ubuntu/.tmp/opencode/plan-cemetery-dehardcoding.md` and must be committed to `docs/superpowers/plans/2026-08-13-cemetery-example-data-dehardcode.md` as step 0 of Task 1).
- `AGENTS.md` §Database: rollback is non-load-bearing; do not add destructive production `down()` migrations; no new schema change is authorized by this plan (data representation only).
- `AGENTS.md` §Documentation: never duplicate canonical catalogue data in multiple hand-maintained locations. The generator is the single source for these example rows.
- `makam-testing` forbids domain factories (`database/factories/` holds only `UserFactory`); tests assert against real seeded rows. This plan does not add a factory.
- The honesty framing of `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` (fictional names, `Jl. Contoh` addresses, null coordinates/prices at seed time, `Contoh`-prefixed grave names, one deliberately-`draft` row) is a hard requirement and must be preserved verbatim in the generator's docblock and data.
- `cemeteries.slug` has a unique index (`2026_07_26_190000_create_cemeteries_table.php:109`); the seeder must not duplicate rows on an already-migrated database.
- `grave_records.cemetery_id` is RESTRICT on delete; nothing in this plan deletes cemeteries from an already-migrated environment.
- Migrations may not be re-run on `dev.makam.co.id` just by editing them (they are recorded as applied); the generator must reproduce the exact business columns of the currently-applied rows so no data migration is needed for existing environments.

---

## Design Decisions

### D1 — Approach A: a shared example-data generator, called from both the migration (shim) and a seeder. Option B and C rejected.

**Verified deploy reality (this plan's investigation, all confirmed):**
- `.github/workflows/ci.yml` runs `php artisan key:generate` and `php artisan test` only. Tests use `RefreshDatabase`, which runs *migrations*; no seeder is invoked.
- `Dockerfile` (runtime stage) runs `composer dump-autoload` + `php artisan package:discover` and then starts nginx+php-fpm. No `db:seed`.
- `docker/docker-entrypoint.sh` starts nginx+php-fpm and execs any other command (`horizon`, `queue:work`) directly. No `db:seed`.
- `/opt/makam/compose/compose.yml` runs `dev-web` from the immutable image with `depends_on: postgres healthy`; migrations are applied in the deploy flow (§10 of `docs/operations/dev-staging-environment.md`), not seeders.
- The seed migration's own docblock (and the identical precedent in `2026_07_26_170400_seed_faq_categories_and_articles.php` and `2026_08_08_100010_seed_example_grave_records.php`) states this explicitly: "nothing in this codebase's CI pipeline or deployment process ever runs `php artisan db:seed`, so real content that must exist in every environment automatically ships as a migration instead."

**Why B is rejected (and this is the honest reading):** Moving to a seeder + pipeline change is the only option that removes *every* named fixture from `database/migrations/`. But it requires (a) changing the image entrypoint or compose to run `db:seed --class=…` on every container start (this image is "one image, promoted everywhere" — `dev-web`, `stg-web`, `stg-horizon`, and workers all share it, so the seeder would run on every one of them, on every restart), (b) making the seeder idempotent under `cemeteries.slug`'s unique constraint, (c) changing CI so `RefreshDatabase` seeds after migrate, and (d) changing every developer's local flow. Blast radius is high, the risk of silently shipping an environment with zero cemeteries is real (a missed pipeline edit strips `dev.makam.co.id` of its entire directory), and it contradicts the architecture the repo has documented and shipped three times over. Zero user-visible benefit over A for that risk.

**Why C is rejected:** Reading the same inline arrays from a `config/` const list instead of a `$cemeteries` local is cosmetic — the data still lives inside a migration and still reads as baked-in content. The user explicitly called this out as "may not satisfy".

**What A actually does:** The ten example cemeteries (and their packages/backfills/grave records) move out of the migrations into one well-documented generator that deterministically produces them per environment. Migrations still run the generator on `migrate` (preserving the every-environment invariant with zero pipeline change), and a seeder makes `db:seed` produce the same data for anyone who runs it. This matches the user's approved scope verbatim: "move out of a hardcoded migration into a data-generation approach (seeder or generator) that produces example data per environment."

**Honest limitation, stated plainly:** the names still exist as strings — they must, because the honesty framing *requires* recognizable example fixtures and tests must address them. What changes is where they live (one documented generator instead of three interlocking migrations), that nothing depends on `db:seed`, and that no prose/tests read them as runtime coupling.

### D2 — The `2026_07_26_190300` migration is edited in place, despite the repo's "never rewrite an applied migration" rule. This one edit is the exception, and it is justified.

- The rule (documented in the backfill migration's docblock) exists to prevent data *drift* between environments that already applied a migration and those that will, and to protect rollback order. This edit produces **zero data drift**: the generator reproduces the exact business columns of the currently-applied rows (verified by the existing suite — `CemeterySeedTest` locks count/status/slugs, `GraveRecordSeedTest` locks the grave-record spread, `CemeteryPackageAvailabilityTest` locks the package data, `CemeterySeedTest::test_every_seeded_row_has_plausible_dummy_map_price_and_photo_data` locks the backfill).
- `dev.makam.co.id` already applied `2026_07_26_190300`/`210000`/`100010`; the migration files keep their filenames, so `migrate:status` records them as applied and they will **not re-run** on deploy. No new migration is needed for existing environments because their rows are already correct — the de-hardcoding is a code-representation refactor, not a data change.
- The alternative — a new additive migration that deletes the ten rows and reinserts them from the generator — is rejected: it is destructive (the `grave_records` RESTRICT FK forces deleting the 14 example records first), contradicts `AGENTS.md` §Database ("do not rely on destructive production `down()` migrations"), and churns a database that is already correct.
- New safety net: the plan adds a unit test (`CemeteryExampleDataTest`) that locks the generator's *internal* consistency (every slug referenced by packages/backfills/grave records exists in `cemeteries()`; exactly one draft; the all-restricted and draft roles have the grave-record rows the suite depends on), so a future data change is a deliberate generator edit, not silent drift.

### D3 — The generator owns both the data shape AND materialization (`seed()`, `applyBackfill()`, `seedGraveRecords()`).

- Three migrations currently re-enumerate the same ten slugs/names in `up()` **and again in their `down()` lists**. Moving the definitions into the generator and having the migrations call `CemeteryExampleData::seed()` etc. collapses all of that into one file. The migration `down()` methods keep their delete logic (rollback is migration-specific) but source their slug lists from `CemeteryExampleData::slugs()`.
- Without materialization on the generator, the migration and the seeder would each need their own copy of the three insert loops — the exact duplication this plan is removing.
- Cross-migration slug coupling (backfill updates by slug; grave records reference cemeteries by slug) is resolved structurally: all three migrations read from the same generator, so a slug change is a single-line edit in one class and the unit test fails loudly if a referenced slug no longer exists.

### D4 — The seeder is idempotent by construction, not by upsert.

- Because migrations already populate every real environment, a later `db:seed` (Laravel's own default flow, or a developer's manual call) must not hit `cemeteries.slug`'s unique constraint. The seeder checks whether any of the ten slugs already exist and returns early if so; otherwise it runs the full generator materialization. This is simpler and safer than per-row `updateOrInsert` (which would require inventing keys for profile/package/grave-record rows).

### D5 — Tests reference the generator's role constants + a shared `tests/Support/CemeteryFixture` helper; the three duplicated private `cemeteryId()` helpers are deleted.

- Three test files each define an identical private `cemeteryId(string $slug)` (`GraveRegistryPublicQueryTest`, `GraveRecordTrigramSearchTest`, `GraveSearchStatesTest`). They collapse into one `tests/Support/CemeteryFixture::id()` / `::cemetery()`, mirroring the existing `tests/Support/WeakMoneyCaller.php` convention.
- Slug literals in tests are replaced by the generator's role constants (`CemeteryExampleData::DRAFT_SLUG`, `::PACKAGE_CEMETERY_SLUGS`, `::ALL_RESTRICTED_SLUG`, `::OPEN_CEMETERY_SLUG`) and display-name literals are replaced by `CemeteryExampleData::bySlug(...)['name']`. Tests that only need *an arbitrary* published cemetery use `Cemetery::query()->published()->firstOrFail()` with no name at all.
- Numeric shape assertions (`10` cemeteries, `9` published, `1` draft, `14` records, `2` cemeteries with packages) stay **explicit**, not derived from the generator. They are the fixture-design contract the suite deliberately protects (`GraveRecordSeedTest`'s own docblock says so); deriving them would make a data change pass the shape test silently.

### D6 — No new migration, no behavior change, no `.kiro` spec change.

- This is a representation refactor; acceptance criteria in `.kiro/specs/` are unchanged. `docs/planning/retrofit-backlog.md` gains one completion row (the repo's established pattern for retrofit work) and `docs/planning/agent-execution-plan.md`'s "seeders were never the delivery mechanism" note gets a one-line pointer to the generator.

---

## File Map

**New files (3):**
- `app/Support/ExampleData/CemeteryExampleData.php` — the generator (data + role constants + materialization).
- `database/seeders/CemeteryExampleDataSeeder.php` — idempotent seeder over the generator.
- `tests/Support/CemeteryFixture.php` — shared test lookup helper.

**Modified — migrations/seeders (4):**
- `database/migrations/2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` — becomes a shim calling `CemeteryExampleData::seed()`; docblock rewritten; `down()` uses `CemeteryExampleData::slugs()`.
- `database/migrations/2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php` — `up()` calls `CemeteryExampleData::applyBackfill()`; `down()` uses `CemeteryExampleData::slugs()`.
- `database/migrations/2026_08_08_100010_seed_example_grave_records.php` — `up()` calls `CemeteryExampleData::seedGraveRecords()`.
- `database/seeders/DatabaseSeeder.php` — calls `$this->call(CemeteryExampleDataSeeder::class)`.

**Modified — runtime prose (2):**
- `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php:20` — doc comment drops the literal `tps-bekasi-harapan-indah`.
- `resources/views/livewire/public/renewal/grave-search.blade.php:456` — prose drops `TPS Jakarta Kemang`.

**Modified — tests (13):**
- `tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php`
- `tests/Feature/Domain/CemeteryDirectory/CemeteryPublicQueryTest.php`
- `tests/Feature/Domain/CemeteryCapability/CemeteryPackageAvailabilityTest.php`
- `tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryTest.php`
- `tests/Feature/Domain/GraveRegistry/GraveRecordSeedTest.php`
- `tests/Feature/Domain/GraveRegistry/GraveRecordTrigramSearchTest.php`
- `tests/Feature/Livewire/Public/HomePageRouteTest.php`
- `tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php`
- `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`
- `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php`
- `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoPackagesTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardSaveIntegrityTest.php`

**New test (1):**
- `tests/Unit/Support/ExampleData/CemeteryExampleDataTest.php` — generator internal-consistency lock (no DB).

**Docs (2, lightweight):**
- `docs/planning/retrofit-backlog.md` — completion row.
- `docs/planning/agent-execution-plan.md` — one-line pointer (optional; do in Task 12).

Counts: **4 new files, 6 modified non-test files, 13 modified test files, 2 lightweight doc files** = 25 files total.

---

### Task 0: Commit this plan

**Files:**
- Create: `docs/superpowers/plans/2026-08-13-cemetery-example-data-dehardcode.md` (copy of this document)

- [ ] **Step 1: Copy the plan into the repo**

Copy `/home/ubuntu/.tmp/opencode/plan-cemetery-dehardcoding.md` to `docs/superpowers/plans/2026-08-13-cemetery-example-data-dehardcode.md` in the implementation worktree.

- [ ] **Step 2: Commit**

```bash
git add docs/superpowers/plans/2026-08-13-cemetery-example-data-dehardcode.md
git commit -m "docs: plan cemetery example-data de-hardcoding retrofit"
```

---

### Task 1: Create the generator `App\Support\ExampleData\CemeteryExampleData`

**Files:**
- Create: `app/Support/ExampleData/CemeteryExampleData.php`
- Test: `tests/Unit/Support/ExampleData/CemeteryExampleDataTest.php`

**Interfaces:**
- Consumes: `App\Domain\CemeteryDirectory\{CemeteryType, CemeteryPublicationStatus, LaunchCityCode}`, `App\Domain\CemeteryCapability\{CemeteryPackageAvailabilityStatus, Models\CemeteryCapabilityProfile}`, `App\Domain\GraveRegistry\{GraveNameNormalizer, GraveRecordAccessMode, GraveRecordSource}`, `Illuminate\Support\Facades\DB`, `Illuminate\Support\Str`.
- Produces (consumed by Tasks 2–4 and all test tasks):
  - `public const string DRAFT_SLUG`
  - `public const array PACKAGE_CEMETERY_SLUGS`
  - `public const string ALL_RESTRICTED_SLUG`
  - `public const string OPEN_CEMETERY_SLUG`
  - `public static function cemeteries(): array` — `list<array{0:int,1:string,2:string,3:string,4:string,5:string,6:list<string>,7:string}>` (`[type, name, slug, city, address, operator_name, facilities, publication_status]`)
  - `public static function packages(): array` — `list<array{0:string,1:string,2:?string,3:string,4:?string,5:int}>` (`[slug, name, class_label, availability_status, description, sort_order]`)
  - `public static function backfills(): array` — `list<array{0:string,1:float,2:float,3:string,4:float,5:float,6:string}>` (`[slug, latitude, longitude, google_maps_url, price_min, price_max, primary_photo_path]`)
  - `public static function graveRecords(): array` — `list<array{0:string,1:string,2:string,3:?string,4:?string,5:string}>` (`[slug, deceased_name, block, death_date, due_date, access_mode]`)
  - `public static function slugs(): list<string>`
  - `public static function priceSourceLabel(): string`
  - `public static function bySlug(string $slug): array`
  - `public static function seed(): void`
  - `public static function applyBackfill(): void`
  - `public static function seedGraveRecords(): void`

- [x] **Step 1: Write the failing consistency test**

Create `tests/Unit/Support/ExampleData/CemeteryExampleDataTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Support\ExampleData\CemeteryExampleData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Locks the generator's INTERNAL consistency. The DB-facing shape contract
 * (ten cemeteries, nine published, one draft, the package spread, the
 * grave-record spread) is asserted by the existing feature suite
 * (CemeterySeedTest, GraveRecordSeedTest, CemeteryPackageAvailabilityTest)
 * and must stay there — those numbers are the fixture-design contract, not
 * derivations. This file asserts the cross-array invariants that would
 * otherwise be silent if a slug drifted between the four methods.
 */
final class CemeteryExampleDataTest extends TestCase
{
    public function test_every_referenced_slug_exists_in_cemeteries(): void
    {
        $slugs = array_column(CemeteryExampleData::cemeteries(), 2);

        $this->assertSame(count($slugs), count(array_unique($slugs)), 'Cemetery slugs must be unique.');

        foreach (array_merge(
            CemeteryExampleData::packages(),
            CemeteryExampleData::backfills(),
            CemeteryExampleData::graveRecords(),
        ) as $row) {
            $this->assertContains(
                $row[0],
                $slugs,
                "Slug [{$row[0]}] is referenced by example data but not defined in cemeteries()."
            );
        }
    }

    public function test_exactly_one_cemetery_is_deliberately_draft(): void
    {
        $draft = array_filter(
            CemeteryExampleData::cemeteries(),
            static fn (array $c): bool => $c[7] !== CemeteryPublicationStatus::PUBLISHED,
        );

        $this->assertCount(1, $draft);
        $this->assertSame(CemeteryExampleData::DRAFT_SLUG, reset($draft)[2]);
    }

    public function test_the_all_restricted_role_cemetery_has_only_restricted_grave_records(): void
    {
        $records = array_filter(
            CemeteryExampleData::graveRecords(),
            static fn (array $r): bool => $r[0] === CemeteryExampleData::ALL_RESTRICTED_SLUG,
        );

        $this->assertNotEmpty($records, 'The all-restricted fixture needs at least one grave record.');

        foreach ($records as $record) {
            $this->assertTrue(
                $record[5] === GraveRecordAccessMode::LIMITED || $record[5] === GraveRecordAccessMode::CLOSED,
                'Every record in the all-restricted cemetery must be privacy-limited.'
            );
        }
    }

    public function test_the_draft_cemetery_has_at_least_one_grave_record(): void
    {
        $records = array_filter(
            CemeteryExampleData::graveRecords(),
            static fn (array $r): bool => $r[0] === CemeteryExampleData::DRAFT_SLUG,
        );

        $this->assertNotEmpty($records, 'The draft cemetery needs a record so the negative fixture stays reachable.');
    }

    public function test_backfills_cover_every_cemetery_exactly_once(): void
    {
        $backfillSlugs = array_column(CemeteryExampleData::backfills(), 0);

        $this->assertSame(
            array_column(CemeteryExampleData::cemeteries(), 2),
            $backfillSlugs,
            'backfills() must cover every cemetery, in the same order.'
        );
    }

    public function test_by_slug_returns_the_expected_row_and_rejects_unknown_slugs(): void
    {
        $this->assertSame(
            CemeteryExampleData::DRAFT_SLUG,
            CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[2],
        );

        $this->expectException(InvalidArgumentException::class);
        CemeteryExampleData::bySlug('no-such-example-cemetery');
    }
}
```

- [x] **Step 2: Run it to verify it fails**

Run: `php artisan test --filter=CemeteryExampleDataTest`
Expected: FAIL — `Class "App\Support\ExampleData\CemeteryExampleData" not found`.

- [x] **Step 3: Write the generator**

Create `app/Support/ExampleData/CemeteryExampleData.php`. The class docblock must carry the honesty framing from the current seed migration verbatim (fictional names using ordinary neighbourhood words, `Jl. Contoh` addresses, null coordinates/prices/photo at seed time, generic `operator_name`, generic `facilities`, why `TPS Bekasi Harapan Indah` is seeded `draft`, why only two cemeteries get package rows, the capability-profile safe-defaults paragraph, and the `source`/`evidence`/`rollback_plan` Indonesian literals). The four data methods below reproduce the exact business columns of the current migrations.

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * (Full honesty docblock — see Task 1 Step 3 note above.)
 */
final class CemeteryExampleData
{
    /**
     * The one seeded cemetery that is deliberately `draft` — the negative
     * fixture that proves `Cemetery::scopePublished()` excludes something.
     */
    public const string DRAFT_SLUG = 'tps-bekasi-harapan-indah';

    /**
     * The two cemeteries that carry `cemetery_packages` example rows.
     * Order is load-bearing for tests: index 0 is the Jakarta TPU, index 1
     * the Depok TPU.
     */
    public const array PACKAGE_CEMETERY_SLUGS = ['tpu-jakarta-menteng', 'tpu-depok-sawangan'];

    /**
     * The cemetery whose EVERY grave record is privacy-restricted — the pure
     * privacy-limited fixture the renewal suite depends on.
     */
    public const string ALL_RESTRICTED_SLUG = 'tps-jakarta-kemang';

    /**
     * A plain published, openly-searchable cemetery used by tests that need
     * an arbitrary cemetery with no special role.
     */
    public const string OPEN_CEMETERY_SLUG = 'tpu-bogor-bantarjati';

    /**
     * Shape: [type, name, slug, city, address, operator_name, facilities, publication_status]
     *
     * @return list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: list<string>, 7: string}>
     */
    public static function cemeteries(): array
    {
        return [
            [CemeteryType::TPU, 'TPU Jakarta Menteng', self::PACKAGE_CEMETERY_SLUGS[0], LaunchCityCode::JAKARTA,
                'Jl. Contoh Sejahtera No. 10, Menteng, Jakarta Pusat',
                'Unit Pengelola Pemakaman Kota Jakarta',
                ['Area Parkir', 'Mushola', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Jakarta Kemang', self::ALL_RESTRICTED_SLUG, LaunchCityCode::JAKARTA,
                'Jl. Contoh Kemuning No. 21, Kemang, Jakarta Selatan',
                'Yayasan Pemakaman Swasta Jakarta',
                ['Area Parkir', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Bogor Bantarjati', self::OPEN_CEMETERY_SLUG, LaunchCityCode::BOGOR,
                'Jl. Contoh Melati No. 5, Bantarjati, Bogor Utara',
                'Unit Pengelola Pemakaman Kota Bogor',
                ['Area Parkir', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Bogor Cimanggu', 'tps-bogor-cimanggu', LaunchCityCode::BOGOR,
                'Jl. Contoh Anggrek No. 8, Cimanggu, Bogor Tengah',
                'Yayasan Pemakaman Swasta Bogor',
                ['Area Parkir', 'Mushola', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Depok Sawangan', self::PACKAGE_CEMETERY_SLUGS[1], LaunchCityCode::DEPOK,
                'Jl. Contoh Cempaka No. 17, Sawangan, Depok',
                'Unit Pengelola Pemakaman Kota Depok',
                ['Area Parkir', 'Mushola', 'Toilet Umum', 'Sumber Air'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Depok Cinere', 'tps-depok-cinere', LaunchCityCode::DEPOK,
                'Jl. Contoh Mawar No. 3, Cinere, Depok',
                'Yayasan Pemakaman Swasta Depok',
                ['Area Parkir'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Tangerang Cipondoh', 'tpu-tangerang-cipondoh', LaunchCityCode::TANGERANG,
                'Jl. Contoh Dahlia No. 14, Cipondoh, Tangerang',
                'Unit Pengelola Pemakaman Kota Tangerang',
                ['Area Parkir', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Tangerang Karawaci', 'tps-tangerang-karawaci', LaunchCityCode::TANGERANG,
                'Jl. Contoh Kenanga No. 9, Karawaci, Tangerang',
                'Yayasan Pemakaman Swasta Tangerang',
                ['Area Parkir', 'Mushola'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Bekasi Jatiasih', 'tpu-bekasi-jatiasih', LaunchCityCode::BEKASI,
                'Jl. Contoh Flamboyan No. 6, Jatiasih, Bekasi',
                'Unit Pengelola Pemakaman Kota Bekasi',
                ['Area Parkir', 'Mushola', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Bekasi Harapan Indah', self::DRAFT_SLUG, LaunchCityCode::BEKASI,
                'Jl. Contoh Teratai No. 11, Harapan Indah, Bekasi',
                'Yayasan Pemakaman Swasta Bekasi',
                ['Area Parkir', 'Ruang Tunggu'],
                // Deliberately seeded as `draft` — see the class doc block.
                CemeteryPublicationStatus::DRAFT],
        ];
    }

    /**
     * Shape: [slug, name, class_label, availability_status, description, sort_order]
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string, 4: ?string, 5: int}>
     */
    public static function packages(): array
    {
        return [
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::LIMITED,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', 'Kelas B', CemeteryPackageAvailabilityStatus::LIMITED,
                null, 3],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Single', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 4],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Single', null, CemeteryPackageAvailabilityStatus::UNAVAILABLE,
                'Penuh untuk periode saat ini.', 3],
        ];
    }

    /**
     * Shape: [slug, latitude, longitude, google_maps_url, price_min, price_max, primary_photo_path]
     * — `price_source` is the single literal `priceSourceLabel()` shared by
     * every row (see `applyBackfill()`).
     *
     * @return list<array{0: string, 1: float, 2: float, 3: string, 4: float, 5: float, 6: string}>
     */
    public static function backfills(): array
    {
        return [
            [self::PACKAGE_CEMETERY_SLUGS[0], -6.19, 106.83,
                self::mapsSearchUrl('TPU Jakarta Menteng', 'Jakarta'), 4_000_000.00, 7_500_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            [self::ALL_RESTRICTED_SLUG, -6.26, 106.81,
                self::mapsSearchUrl('TPS Jakarta Kemang', 'Jakarta'), 12_000_000.00, 22_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
            [self::OPEN_CEMETERY_SLUG, -6.57, 106.81,
                self::mapsSearchUrl('TPU Bogor Bantarjati', 'Bogor'), 3_000_000.00, 6_000_000.00,
                'images/cemeteries/illustration-03-path.svg'],
            ['tps-bogor-cimanggu', -6.63, 106.79,
                self::mapsSearchUrl('TPS Bogor Cimanggu', 'Bogor'), 9_000_000.00, 16_000_000.00,
                'images/cemeteries/illustration-04-garden.svg'],
            [self::PACKAGE_CEMETERY_SLUGS[1], -6.38, 106.76,
                self::mapsSearchUrl('TPU Depok Sawangan', 'Depok'), 3_500_000.00, 6_500_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            ['tps-depok-cinere', -6.33, 106.77,
                self::mapsSearchUrl('TPS Depok Cinere', 'Depok'), 10_000_000.00, 18_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
            ['tpu-tangerang-cipondoh', -6.19, 106.69,
                self::mapsSearchUrl('TPU Tangerang Cipondoh', 'Tangerang'), 3_200_000.00, 6_200_000.00,
                'images/cemeteries/illustration-03-path.svg'],
            ['tps-tangerang-karawaci', -6.23, 106.63,
                self::mapsSearchUrl('TPS Tangerang Karawaci', 'Tangerang'), 8_500_000.00, 15_000_000.00,
                'images/cemeteries/illustration-04-garden.svg'],
            ['tpu-bekasi-jatiasih', -6.27, 106.98,
                self::mapsSearchUrl('TPU Bekasi Jatiasih', 'Bekasi'), 3_000_000.00, 5_800_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            [self::DRAFT_SLUG, -6.15, 107.01,
                self::mapsSearchUrl('TPS Bekasi Harapan Indah', 'Bekasi'), 9_500_000.00, 17_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
        ];
    }

    /**
     * Shape: [cemetery slug, deceased name, block, death date, due date, access mode]
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: string}>
     */
    public static function graveRecords(): array
    {
        return [
            // --- TPU Jakarta Menteng: mixed open + one limited ---
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Budi Santoso', 'A-12', '2018-04-11', '2026-04-11', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Siti Rahayu', 'A-15', '2019-09-02', '2027-09-02', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Bambang Wijaya', 'B-03', '2020-01-27', '2026-01-27', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Sri Handayani', 'B-08', '2021-06-18', '2027-06-18', GraveRecordAccessMode::LIMITED],

            // --- TPS Jakarta Kemang: every row restricted (see class doc block) ---
            [self::ALL_RESTRICTED_SLUG, 'Contoh Agus Priyono', 'C-01', '2017-11-30', '2026-11-30', GraveRecordAccessMode::LIMITED],
            [self::ALL_RESTRICTED_SLUG, 'Contoh Dewi Anggraini', 'C-04', '2022-02-14', '2028-02-14', GraveRecordAccessMode::CLOSED],

            // --- TPU Bogor Bantarjati ---
            [self::OPEN_CEMETERY_SLUG, 'Contoh Joko Purnomo', 'D-07', '2016-08-05', '2026-08-05', GraveRecordAccessMode::OPEN],
            [self::OPEN_CEMETERY_SLUG, 'Contoh Rina Marlina', 'D-09', '2020-12-21', '2026-12-21', GraveRecordAccessMode::OPEN],

            // --- TPU Depok Sawangan ---
            // Deliberately missing a death date: the registry incompleteness
            // AC5's empty-state copy tells the public about must be real.
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Contoh Hendra Gunawan', 'E-02', null, '2027-03-15', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Contoh Lestari Wulandari', 'E-05', '2019-05-09', null, GraveRecordAccessMode::OPEN],

            // --- TPU Tangerang Cipondoh ---
            ['tpu-tangerang-cipondoh', 'Contoh Andi Kurniawan', 'F-11', '2021-10-03', '2027-10-03', GraveRecordAccessMode::OPEN],

            // --- TPU Bekasi Jatiasih ---
            ['tpu-bekasi-jatiasih', 'Contoh Yusuf Maulana', 'G-06', '2018-07-22', '2026-07-22', GraveRecordAccessMode::OPEN],
            ['tpu-bekasi-jatiasih', 'Contoh Nurul Hasanah', 'G-10', '2023-01-08', '2029-01-08', GraveRecordAccessMode::CLOSED],

            // --- TPS Bekasi Harapan Indah: the DRAFT cemetery (negative fixture) ---
            [self::DRAFT_SLUG, 'Contoh Rahmat Hidayat', 'H-01', '2020-03-30', '2026-03-30', GraveRecordAccessMode::OPEN],
        ];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::cemeteries(), 2);
    }

    public static function priceSourceLabel(): string
    {
        return 'Estimasi internal (data contoh)';
    }

    public static function bySlug(string $slug): array
    {
        foreach (self::cemeteries() as $cemetery) {
            if ($cemetery[2] === $slug) {
                return $cemetery;
            }
        }

        throw new InvalidArgumentException("Unknown example cemetery slug [{$slug}].");
    }

    public static function seed(): void
    {
        $now = now();

        $cemeteryIds = [];

        foreach (self::cemeteries() as [$type, $name, $slug, $city, $address, $operatorName, $facilities, $publicationStatus]) {
            $id = (string) Str::uuid();
            $cemeteryIds[$slug] = $id;

            $isPublished = $publicationStatus === CemeteryPublicationStatus::PUBLISHED;

            DB::table('cemeteries')->insert([
                'id' => $id,
                'type' => $type,
                'publication_status' => $publicationStatus,
                'name' => $name,
                'slug' => $slug,
                'city' => $city,
                'address' => $address,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'primary_photo_path' => null,
                'facilities' => json_encode($facilities),
                'price_min' => null,
                'price_max' => null,
                'price_currency' => 'IDR',
                'price_source' => null,
                'price_effective_at' => null,
                'operator_name' => $operatorName,
                'published_at' => $isPublished ? $now : null,
                'unpublished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $defaults = CemeteryCapabilityProfile::safeDefaults();

            DB::table('cemetery_capability_profiles')->insert([
                'cemetery_id' => $id,
                'version_number' => 1,
                'availability_mode' => $defaults['availability_mode'],
                'booking_mode' => $defaults['booking_mode'],
                'map_mode' => $defaults['map_mode'],
                'registry_mode' => $defaults['registry_mode'],
                'certificate_mode' => $defaults['certificate_mode'],
                'visitation_mode' => $defaults['visitation_mode'],
                'source' => 'seed:cemetery-directory-master-data',
                'owner' => 'Platform Admin (seed)',
                'evidence' => 'Data awal Sprint 4 (S4-T1) — belum ada evaluasi operator lapangan; seluruh mode mengikuti nilai aman default, bukan hasil aktivasi kapabilitas nyata.',
                'rollback_plan' => 'Tidak ada aktivasi untuk dibatalkan — profil ini adalah nilai default awal, bukan hasil aktivasi kapabilitas lanjutan.',
                'effective_at' => $now,
                'superseded_at' => null,
            ]);
        }

        foreach (self::packages() as [$cemeterySlug, $name, $classLabel, $availabilityStatus, $description, $sortOrder]) {
            DB::table('cemetery_packages')->insert([
                'cemetery_id' => $cemeteryIds[$cemeterySlug],
                'name' => $name,
                'class_label' => $classLabel,
                'availability_status' => $availabilityStatus,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function applyBackfill(): void
    {
        $now = now();

        foreach (self::backfills() as [$slug, $latitude, $longitude, $googleMapsUrl, $priceMin, $priceMax, $photoPath]) {
            DB::table('cemeteries')->where('slug', $slug)->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'google_maps_url' => $googleMapsUrl,
                'primary_photo_path' => $photoPath,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'price_source' => self::priceSourceLabel(),
                'price_effective_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function seedGraveRecords(): void
    {
        $now = now();

        $records = self::graveRecords();

        $cemeteryIds = DB::table('cemeteries')
            ->whereIn('slug', array_unique(array_column($records, 0)))
            ->pluck('id', 'slug');

        foreach ($records as [$slug, $name, $block, $deathDate, $dueDate, $accessMode]) {
            $cemeteryId = $cemeteryIds[$slug] ?? null;

            // A slug this data expects but cannot find means the cemetery
            // seed was rolled back or edited. Skip rather than fail: a
            // missing FIXTURE row must never block a real deployment's
            // migration run. (Same choice the previous grave-record seed
            // migration documented.)
            if ($cemeteryId === null) {
                continue;
            }

            DB::table('grave_records')->insert([
                'id' => (string) Str::uuid(),
                'cemetery_id' => $cemeteryId,
                'deceased_name' => $name,
                // GraveRecord::booted() derives this on Eloquent writes, but
                // the query builder does not fire model events — calling the
                // same normalizer keeps stored form identical to what
                // GraveRegistryPublicQuery searches against.
                'deceased_name_normalized' => GraveNameNormalizer::normalize($name),
                'block' => $block,
                'death_date' => $deathDate,
                'due_date' => $dueDate,
                'heir_contact_reference' => null,
                'access_mode' => $accessMode,
                'source' => GraveRecordSource::CONTOH,
                'source_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private static function mapsSearchUrl(string $name, string $city): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.urlencode("{$name}, {$city}, Indonesia");
    }
}
```

- [x] **Step 4: Run the test to verify it passes**

Run: `php artisan test --filter=CemeteryExampleDataTest`
Expected: PASS (6 tests). This is the generator's internal-consistency lock.

- [x] **Step 5: Commit**

```bash
git add app/Support/ExampleData/CemeteryExampleData.php tests/Unit/Support/ExampleData/CemeteryExampleDataTest.php
git commit -m "feat: centralize cemetery example data in a deterministic generator"
```

---

### Task 2: Rewire `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` to the generator

**Files:**
- Modify: `database/migrations/2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`

**Interfaces:**
- Consumes: `App\Support\ExampleData\CemeteryExampleData::seed()`, `::slugs()` (Task 1).
- Produces: unchanged behavior — the same ten cemeteries, ten capability profiles, seven packages on `migrate`.

- [x] **Step 1: Rewrite the migration as a shim**

Replace the entire `return new class extends Migration {...}` body (keep the filename). The honesty docblock moves to the generator's class docblock; this file keeps a short docblock that (a) points at the generator, (b) documents **why this in-place edit is the deliberate exception to the repo's "never rewrite an applied migration" rule** (byte-identical business columns; already-applied environments are skipped by `migrate:status`, so no data change; the existing suite is the output lock), and (c) preserves the `down()` FK-order warning.

```php
<?php

declare(strict_types=1);

use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `cemeteries`, one current-version `cemetery_capability_profiles`
 * row per cemetery, and the `cemetery_packages` example rows — all of it
 * now delegated to `App\Support\ExampleData\CemeteryExampleData` (the ONE
 * place the example data is defined; see that class's doc block for the
 * honesty framing this migration used to carry).
 *
 * ---------------------------------------------------------------------------
 * Why this migration still exists, and why it was EDITED in place
 * ---------------------------------------------------------------------------
 * Nothing in CI or any deployment process runs `php artisan db:seed`
 * (verified in `.github/workflows/ci.yml`, the Dockerfile, the entrypoint,
 * and compose), so example content that must exist in every environment
 * ships through `php artisan migrate`. This migration remains that path;
 * only the inline data has moved out.
 *
 * Editing an already-applied data migration is normally forbidden in this
 * repository (see `2026_07_26_210000`'s own doc block). This is the one
 * deliberate exception: the generator reproduces the EXACT business columns
 * this migration used to insert (the suite locks them — `CemeterySeedTest`,
 * `CemeteryPackageAvailabilityTest`, `GraveRecordSeedTest`), environments
 * that already applied this file are skipped by `migrate:status` so their
 * rows are untouched, and the alternative (a rebuild migration that deletes
 * and reinserts against `grave_records`' RESTRICT FK) is destructive in
 * the way `AGENTS.md` §Database forbids. The de-hardcoding is a
 * representation refactor, not a data change.
 *
 * Migration timestamp slot: see
 * `2026_07_26_190000_create_cemeteries_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        CemeteryExampleData::seed();
    }

    public function down(): void
    {
        // `cemeteries.id` now has FOUR inbound foreign keys with THREE
        // different delete behaviours — `cemetery_capability_profiles` and
        // `cemetery_packages` cascade, `grave_records` RESTRICTs
        // (2026_08_08_100000:130-132), `booking_drafts` SET NULLs
        // (2026_08_08_130000:67). The grave_records RESTRICT is a row-level
        // DELETE constraint that fires on the statement below. A sequential
        // `migrate:rollback` still works because
        // `2026_08_08_100010_seed_example_grave_records.php`'s own `down()`
        // removes its fixture rows first; a targeted rollback of just this
        // migration does NOT. Do not extend this to delete `grave_records`
        // — that table is owned by `GraveRegistry`, and per `AGENTS.md`
        // §Database we do not rely on destructive production `down()`
        // migrations in the first place.
        DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->delete();
    }
};
```

- [x] **Step 2: Run the affected suites**

Run: `php artisan test --filter=CemeterySeedTest`
Run: `php artisan test --filter=CemeteryCapabilityProfileSafeDefaultsTest`
Run: `php artisan test --filter=CemeteryPackageAvailabilityTest`
Expected: all PASS. This proves the generator reproduces the exact shape (10 rows, 9 published, 1 draft, correct slug/status/city per test, safe-default profiles, the two package cemeteries).

- [x] **Step 3: Commit**

```bash
git add database/migrations/2026_07_26_190300_seed_cemeteries_and_capability_profiles.php
git commit -m "refactor: seed cemeteries from the example-data generator"
```

---

### Task 3: Rewire `2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`

**Files:**
- Modify: `database/migrations/2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`

**Interfaces:**
- Consumes: `CemeteryExampleData::applyBackfill()`, `::slugs()`, `::priceSourceLabel()` (Task 1).
- Produces: unchanged behavior — same map/price/photo backfill on `migrate`.

- [x] **Step 1: Rewrite the migration as a shim**

Keep the class-level docblock (the dummy-data authorization rationale, the coordinates explanation, the maps-search URL rationale, the pricing rationale, the SVG-photo rationale — it is all still true and still valuable); change the references to "the seed migration's own doc block" to point at `CemeteryExampleData`. Replace the body:

```php
return new class extends Migration
{
    public function up(): void
    {
        // Dummy/demo backfill for the ten example cemeteries — values are
        // defined in App\Support\ExampleData\CemeteryExampleData::backfills().
        CemeteryExampleData::applyBackfill();
    }

    public function down(): void
    {
        // Restores the seed's original honesty-driven NULL state for these
        // columns — this migration is purely additive dummy/demo data on top
        // of the real seed rows, so rolling it back must not delete the
        // cemeteries themselves.
        DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->update([
            'latitude' => null,
            'longitude' => null,
            'google_maps_url' => null,
            'primary_photo_path' => null,
            'price_min' => null,
            'price_max' => null,
            'price_source' => null,
            'price_effective_at' => null,
            'updated_at' => now(),
        ]);
    }
};
```

Add the two `use` lines (`App\Support\ExampleData\CemeteryExampleData`, `Illuminate\Support\Facades\DB`) and drop the now-unused `$backfills` array, the private `mapsSearchUrl()`, and any now-unused imports.

- [x] **Step 2: Run the affected test**

Run: `php artisan test --filter=CemeterySeedTest` (specifically `test_every_seeded_row_has_plausible_dummy_price_map_and_photo_data`)
Expected: PASS. The dummy data (price_min<price_max, `IDR`, `data contoh` source, `images/cemeteries/*.svg`, in-bounds lat/lng, `https://` maps URL) is asserted per row here.

- [x] **Step 3: Commit**

```bash
git add database/migrations/2026_07_26_210000_backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php
git commit -m "refactor: backfill cemetery dummy data from the example-data generator"
```

---

### Task 4: Rewire `2026_08_08_100010_seed_example_grave_records.php`

**Files:**
- Modify: `database/migrations/2026_08_08_100010_seed_example_grave_records.php`

**Interfaces:**
- Consumes: `CemeteryExampleData::seedGraveRecords()` (Task 1).
- Produces: unchanged behavior — the same fourteen `Contoh`-prefixed grave records across the documented access-mode spread.

- [x] **Step 1: Rewrite the migration as a shim**

Keep the class-level docblock (the dummy-data warning, the "Contoh" marker rationale, the access-mode spread explanation, the negative-fixture explanation, the "why a data migration" paragraph now updated to say "the data is defined in `App\Support\ExampleData\CemeteryExampleData::graveRecords()` and materialized by `seedGraveRecords()`"). Replace the body:

```php
return new class extends Migration
{
    public function up(): void
    {
        // The fourteen example grave records are defined in
        // App\Support\ExampleData\CemeteryExampleData::graveRecords().
        // It resolves cemetery slug -> id at runtime and skips (never
        // fails on) a slug the cemetery seed did not produce, exactly like
        // the original inline loop.
        CemeteryExampleData::seedGraveRecords();
    }

    /**
     * Deletes exactly the rows `up()` inserted — the enumerated names,
     * further scoped to `source = contoh` so a later real-data batch's rows
     * can never be caught by this rollback even if one of them somehow
     * shared a name. Never a blanket truncate of `grave_records`
     * (`makam-migration`; `AGENTS.md` §Database).
     */
    public function down(): void
    {
        $names = array_column(CemeteryExampleData::graveRecords(), 1);

        DB::table('grave_records')
            ->where('source', GraveRecordSource::CONTOH)
            ->whereIn('deceased_name', $names)
            ->delete();
    }
};
```

Add `use App\Support\ExampleData\CemeteryExampleData;` and `use App\Domain\GraveRegistry\GraveRecordSource;`; drop the now-unused `GraveNameNormalizer`, `GraveRecordAccessMode`, `Str`, and the inline `$records` array.

- [x] **Step 2: Run the affected suite**

Run: `php artisan test --filter=GraveRecordSeedTest`
Run: `php artisan test --filter=GraveRegistryPublicQueryTest`
Expected: PASS. This proves the fourteen rows land on the right cemeteries (slug→id runtime resolution unchanged), with the right normalized names, access modes, and the draft-cemetery negative fixture.

- [x] **Step 3: Commit**

```bash
git add database/migrations/2026_08_08_100010_seed_example_grave_records.php
git commit -m "refactor: seed example grave records from the example-data generator"
```

---

### Task 5: Add the idempotent seeder and register it

**Files:**
- Create: `database/seeders/CemeteryExampleDataSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`

**Interfaces:**
- Consumes: `CemeteryExampleData::slugs()`, `::seed()`, `::applyBackfill()`, `::seedGraveRecords()` (Task 1).
- Produces: `php artisan db:seed` produces the same example data as `migrate`, without duplicating rows on an already-migrated database.

- [x] **Step 1: Write the seeder**

Create `database/seeders/CemeteryExampleDataSeeder.php`:

```php
<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Makes `php artisan db:seed` produce the same example cemeteries (and
 * their capability profiles, packages, dummy backfill, and grave records)
 * that the data migrations produce.
 *
 * In this repository the migrations are the delivery mechanism for every
 * environment (CI and deploy never run `db:seed`), so on any database that
 * has been `migrate`d this seeder is a no-op by design: the ten example
 * slugs already exist (under `cemeteries.slug`'s unique index) and a
 * second insert would violate it. The existence check below makes the
 * seeder idempotent across that case; it only materializes when the data
 * is genuinely absent (e.g. a hand-built database that never ran the data
 * migrations).
 */
final class CemeteryExampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->exists()) {
            return;
        }

        CemeteryExampleData::seed();
        CemeteryExampleData::applyBackfill();
        CemeteryExampleData::seedGraveRecords();
    }
}
```

- [x] **Step 2: Register it in `DatabaseSeeder`**

Modify `database/seeders/DatabaseSeeder.php` `run()` to add the call **before** the existing `User::factory()` line:

```php
    public function run(): void
    {
        $this->call(CemeteryExampleDataSeeder::class);

        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);
    }
```

- [x] **Step 3: Verify idempotency and materialization**

Run: `php artisan migrate:fresh --seed --env=testing` (or, if that env differs, run `php artisan migrate:fresh --seed` in the CI-style test database)
Run: `php artisan test --filter=CemeterySeedTest`
Expected: PASS, and the seeder must not throw a unique-constraint violation on the already-migrated database. Run `php artisan db:seed` a second time — still PASS, no violation, row count of `cemeteries` unchanged (verify with `php artisan tinker --execute="dump(\App\Domain\CemeteryDirectory\Models\Cemetery::count());"` before/after the second run).

- [x] **Step 4: Commit**

```bash
git add database/seeders/CemeteryExampleDataSeeder.php database/seeders/DatabaseSeeder.php
git commit -m "feat: idempotent db:seed path for cemetery example data"
```

---

### Task 6: Clean prose/references in runtime code

**Files:**
- Modify: `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php:20`
- Modify: `resources/views/livewire/public/renewal/grave-search.blade.php:456`

- [x] **Step 1: Drop the slug literal from `CemeteryPublicQuery`'s doc comment**

Current line 20-21: "One seeded cemetery (`tps-bekasi-harapan-indah`) is deliberately `draft` precisely so that exclusion is provable rather than vacuous."

Replace with:

```php
 * One seeded example cemetery is deliberately `draft` (see
 * `App\Support\ExampleData\CemeteryExampleData::DRAFT_SLUG`) precisely so
 * that exclusion is provable rather than vacuous.
```

- [x] **Step 2: Drop the "TPS Jakarta Kemang" prose from the grave-search blade**

Current comment (line 452-458): "It used to sit inside the open-results branch and be computed from the open rows alone, so a search whose every match was restricted (TPS Jakarta Kemang, both of whose seeded rows are withheld) showed fictional data with no disclosure at all."

Replace with:

```blade
                         It used to sit inside the open-results
                         branch and be computed from the open rows alone, so
                         a search whose every match was restricted (the
                         all-restricted example fixture —
                         CemeteryExampleData::ALL_RESTRICTED_SLUG) showed
                         fictional data with no disclosure at all.
```

- [x] **Step 3: Verify nothing else in runtime code names a seeded cemetery**

Run: `grep -rn -iE "menteng|kemang|harapan.indah|sawangan|bantarjati|cimanggu|cinere|cipondoh|karawaci|jatiasih" app/ resources/ routes/ config/`
Expected: no matches. (Any residual hit is a missed runtime-coupling reference — fix it in this task.)

- [x] **Step 4: Verify**

Run: `composer lint`
Expected: PASS (Pint, no style violations).

- [x] **Step 5: Commit**

```bash
git add app/Domain/CemeteryDirectory/CemeteryPublicQuery.php resources/views/livewire/public/renewal/grave-search.blade.php
git commit -m "refactor: drop seeded cemetery names from runtime prose"
```

---

### Task 7: Add `tests/Support/CemeteryFixture` and swap the three duplicated `cemeteryId()` helpers

**Files:**
- Create: `tests/Support/CemeteryFixture.php`
- Modify: `tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryTest.php`
- Modify: `tests/Feature/Domain/GraveRegistry/GraveRecordTrigramSearchTest.php`
- Modify: `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php`

**Interfaces:**
- Consumes: `CemeteryExampleData::PACKAGE_CEMETERY_SLUGS`, `::ALL_RESTRICTED_SLUG`, `::DRAFT_SLUG` (Task 1).
- Produces: `CemeteryFixture::id(string $slug): string` and `CemeteryFixture::cemetery(string $slug): Cemetery`, used by Tasks 8–12.

- [x] **Step 1: Write the helper**

Create `tests/Support/CemeteryFixture.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\CemeteryDirectory\Models\Cemetery;

/**
 * Shared test lookup for seeded example cemeteries. `RefreshDatabase`
 * rebuilds the database per test with fresh UUIDs, so tests can never hold
 * a cemetery id across tests — they must resolve by the deterministic
 * example-data slug at assertion time. This helper is the single place that
 * lookup happens, replacing the identical private `cemeteryId()` methods
 * that three suites each duplicated.
 *
 * Slugs come from `App\Support\ExampleData\CemeteryExampleData` role
 * constants, never literals scattered in tests.
 */
final class CemeteryFixture
{
    public static function id(string $slug): string
    {
        return (string) self::cemetery($slug)->id;
    }

    public static function cemetery(string $slug): Cemetery
    {
        return Cemetery::query()->where('slug', $slug)->sole();
    }
}
```

- [x] **Step 2: Update `GraveRegistryPublicQueryTest.php`**

- Delete the private `cemeteryId()` helper (lines 49-52).
- Add `use Tests\Support\CemeteryFixture;` and `use App\Support\ExampleData\CemeteryExampleData;`.
- Apply this mechanical replacement across the file (17 call sites):
  - `$this->cemeteryId('tpu-jakarta-menteng')` → `CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])`
  - `$this->cemeteryId('tps-jakarta-kemang')` → `CemeteryFixture::id(CemeteryExampleData::ALL_RESTRICTED_SLUG)`
  - `$this->cemeteryId('tps-bekasi-harapan-indah')` → `CemeteryFixture::id(CemeteryExampleData::DRAFT_SLUG)`
  - `$cemeteryId = $this->cemeteryId('tpu-jakarta-menteng');` → `$cemeteryId = CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);`

- [x] **Step 3: Update `GraveRecordTrigramSearchTest.php`**

- Delete the private `cemeteryId()` helper (lines 60-63).
- Add the two `use` statements (Step 2).
- Replace `$this->cemeteryId('tpu-jakarta-menteng')` → `CemeteryFixture::id(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])` (3 sites).

- [x] **Step 4: Update `GraveSearchStatesTest.php`**

- Delete the private `cemeteryId()` helper (lines 62-65).
- Add the two `use` statements.
- Apply the same three-slot mechanical replacement across all 27 call sites:
  - `'tpu-jakarta-menteng'` → `CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]` (inside `CemeteryFixture::id(...)`)
  - `'tps-jakarta-kemang'` → `CemeteryExampleData::ALL_RESTRICTED_SLUG`
  - `'tps-bekasi-harapan-indah'` → `CemeteryExampleData::DRAFT_SLUG`
  - `'tps-jakarta-kemang'`/`'tpu-jakarta-menteng'` variables already in `$cemeteryId = CemeteryFixture::id(...)` form (lines 574, 674) follow the same mapping.
  - Do **not** touch `'Contoh'`, `G-DATA-01`, `ZZ-99`, or the `blok`/`nama` query params — those are not cemetery names.

- [x] **Step 5: Verify**

Run: `php artisan test --filter=GraveRegistryPublicQueryTest`
Run: `php artisan test --filter=GraveSearchStatesTest`
Run: `php artisan test --filter=GraveRecordTrigramSearchTest`
Expected: PASS (TrigramSearchTest passes on PostgreSQL/CI; it self-skips on the local SQLite default).

- [x] **Step 6: Commit**

```bash
git add tests/Support/CemeteryFixture.php tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryTest.php tests/Feature/Domain/GraveRegistry/GraveRecordTrigramSearchTest.php tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php
git commit -m "test: replace duplicated cemeteryId helpers with shared CemeteryFixture"
```

---

### Task 8: Update `CemeteryDirectory` tests

**Files:**
- Modify: `tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php`
- Modify: `tests/Feature/Domain/CemeteryDirectory/CemeteryPublicQueryTest.php`

- [x] **Step 1: Update `CemeterySeedTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 75: `$this->assertSame('tps-bekasi-harapan-indah', $draft?->slug)` → `$this->assertSame(CemeteryExampleData::DRAFT_SLUG, $draft?->slug)`.
- Line 83: `$this->assertNotContains('tps-bekasi-harapan-indah', $published)` → `$this->assertNotContains(CemeteryExampleData::DRAFT_SLUG, $published)`.
- Line 91: `$this->assertSame('tpu-jakarta-menteng', $jakartaTpu->first()->slug)` → `$this->assertSame(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0], $jakartaTpu->first()->slug)` (that constant's index 0 is the Jakarta TPU, documented in the generator).
- Keep the numeric counts (10/9/1) explicit — they are the fixture contract (D5).
- Line 175 `'tpu-contoh-tanpa-peta'`: leave as-is — it is a test-local, in-memory model slug, not a seeded name.
- Class docblock (lines 14-19): reword "`2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` seeds ten EXAMPLE cemeteries" → "`App\Support\ExampleData\CemeteryExampleData` generates ten EXAMPLE cemeteries (materialized by `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` on `migrate`)". Keep the AC1/AC2 reference.

- [x] **Step 2: Update `CemeteryPublicQueryTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 39: `private const DRAFT_SLUG = 'tps-bekasi-harapan-indah';` → `private const DRAFT_SLUG = CemeteryExampleData::DRAFT_SLUG;`.
- Line 233: `where('slug', 'tpu-jakarta-menteng')` → `where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])`.
- Line 254: same replacement (this cemetery is the one that has package rows, which the test's own message requires).

- [x] **Step 3: Verify**

Run: `php artisan test --filter=CemeterySeedTest`
Run: `php artisan test --filter=CemeteryPublicQueryTest`
Expected: PASS.

- [x] **Step 4: Commit**

```bash
git add tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php tests/Feature/Domain/CemeteryDirectory/CemeteryPublicQueryTest.php
git commit -m "test: de-hardcode seeded cemetery slugs in CemeteryDirectory tests"
```

---

### Task 9: Update `CemeteryCapability` tests

**Files:**
- Modify: `tests/Feature/Domain/CemeteryCapability/CemeteryPackageAvailabilityTest.php`

`CemeteryCapabilityProfileSafeDefaultsTest.php` has **no** slug literals (it counts 10 and iterates) — no change, verified in Task 8's run.

- [x] **Step 1: Update `CemeteryPackageAvailabilityTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 28: `where('slug', 'tpu-jakarta-menteng')` → `where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])`.
- Line 50: same → `PACKAGE_CEMETERY_SLUGS[0]`.
- Line 51: `where('slug', 'tpu-depok-sawangan')` → `where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[1])`.
- Line 97 `assertSame(2, $cemeteriesWithPackages)`: keep explicit (fixture contract).

- [x] **Step 2: Verify**

Run: `php artisan test --filter=CemeteryPackageAvailabilityTest`
Run: `php artisan test --filter=CemeteryCapabilityProfileSafeDefaultsTest`
Expected: PASS.

- [x] **Step 3: Commit**

```bash
git add tests/Feature/Domain/CemeteryCapability/CemeteryPackageAvailabilityTest.php
git commit -m "test: de-hardcode seeded cemetery slugs in CemeteryCapability tests"
```

---

### Task 10: Update `GraveRegistry` seed test

**Files:**
- Modify: `tests/Feature/Domain/GraveRegistry/GraveRecordSeedTest.php`

- [x] **Step 1: Update slug literals and doc comments**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 112: `Cemetery::query()->where('slug', 'tps-jakarta-kemang')->sole()->id` → `Cemetery::query()->where('slug', CemeteryExampleData::ALL_RESTRICTED_SLUG)->sole()->id`.
- Line 145: `where('slug', 'tps-bekasi-harapan-indah')` → `where('slug', CemeteryExampleData::DRAFT_SLUG)`.
- Lines 203, 247, 263: `where('slug', 'tpu-bogor-bantarjati')` → `where('slug', CemeteryExampleData::OPEN_CEMETERY_SLUG)`. These tests only need an arbitrary cemetery id to create/validate a `GraveRecord`; the constant documents the role ("a plain open published cemetery") rather than the name.
- Doc comments: reword the named references to role references, keeping the fixture-design narrative — e.g. "TPS Jakarta Kemang has only restricted records" → "the all-restricted cemetery (`CemeteryExampleData::ALL_RESTRICTED_SLUG`) has only restricted records"; "`TPS Bekasi Harapan Indah` is seeded `draft` by `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`" → "`CemeteryExampleData::DRAFT_SLUG` is the deliberately-draft example cemetery"; the method name `test_tps_jakarta_kemang_has_only_restricted_records` may keep its historical name or be renamed to `test_the_all_restricted_cemetery_has_only_restricted_records` (rename — the test asserts the role, not the name).
- Counts (14, spread assertions) stay explicit.

- [x] **Step 2: Verify**

Run: `php artisan test --filter=GraveRecordSeedTest`
Expected: PASS.

- [x] **Step 3: Commit**

```bash
git add tests/Feature/Domain/GraveRegistry/GraveRecordSeedTest.php
git commit -m "test: de-hardcode seeded cemetery slugs in GraveRecordSeedTest"
```

---

### Task 11: Update Booking tests

**Files:**
- Modify: `tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoPackagesTest.php`
- Modify: `tests/Feature/Livewire/Public/Booking/BookingWizardSaveIntegrityTest.php`

- [x] **Step 1: Update `BookingWizardStepTwoPackagesTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;` and `use Tests\Support\CemeteryFixture;`.
- `menteng()` helper (lines 34-45) — rename to `packagesCemetery()` and source from the role constant:

```php
    private function packagesCemetery(): Cemetery
    {
        $cemetery = CemeteryFixture::cemetery(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);

        $this->assertSame(LaunchCityCode::JAKARTA, $cemetery->city);
        $this->assertTrue(
            CemeteryPublicQuery::activePackages($cemetery)->isNotEmpty(),
            'Fixture assumption: the packages example cemetery has active packages.',
        );

        return $cemetery;
    }
```

- Replace every `$this->menteng()` call site (lines 60, 73, 94, 104, 116, 135, 143, 148) with `$this->packagesCemetery()`.
- Inline comment at line 75-77 ("The seed gives Menteng 'Makam Tumpang' three times...") → "The packages example cemetery gives 'Makam Tumpang' three times..." and line 83's fixture-assumption message similarly.
- Class docblock (lines 17-29): reword "the seed migration (`2026_07_26_190300_...`) gives active packages to two REAL, PUBLISHED, PICKABLE cemeteries — TPU Jakarta Menteng and TPU Depok Sawangan" → "...two published, pickable example cemeteries (`CemeteryExampleData::PACKAGE_CEMETERY_SLUGS`)".

- [x] **Step 2: Update `BookingWizardSaveIntegrityTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 84: `Cemetery::query()->where('slug', 'tpu-jakarta-menteng')->firstOrFail()` → `Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->firstOrFail()`.
- Line 81-83 comment: "A different Jakarta cemetery — this one has packages" stays accurate; no name to remove.

- [x] **Step 3: Verify**

Run: `php artisan test --filter=BookingWizardStepTwoPackagesTest`
Run: `php artisan test --filter=BookingWizardSaveIntegrityTest`
Expected: PASS.

- [x] **Step 4: Commit**

```bash
git add tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoPackagesTest.php tests/Feature/Livewire/Public/Booking/BookingWizardSaveIntegrityTest.php
git commit -m "test: de-hardcode seeded cemetery references in Booking tests"
```

---

### Task 12: Update Renewal + Homepage tests

**Files:**
- Modify: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`
- Modify: `tests/Feature/Livewire/Public/HomePageRouteTest.php`

- [x] **Step 1: Update `RenewalStartTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;` and `use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;` (already imported).
- Add one private helper deriving role cemeteries from the generator:

```php
    /** @return array{name: string, city: string} the first published example cemetery in $city. */
    private function firstPublishedExampleName(string $city): string
    {
        foreach (CemeteryExampleData::cemeteries() as [$type, $name, $slug, $cemeteryCity, $address, $operatorName, $facilities, $publicationStatus]) {
            if ($cemeteryCity === $city && $publicationStatus === CemeteryPublicationStatus::PUBLISHED) {
                return $name;
            }
        }

        $this->fail("No published example cemetery exists for city [{$city}].");
    }
```

- `test_selecting_a_city_lists_its_published_cemeteries` (lines 82-90): replace the two `assertSee` name literals with `$this->firstPublishedExampleName(LaunchCityCode::JAKARTA)` and the `assertDontSee('TPU Bogor Bantarjati')` with `$this->firstPublishedExampleName(LaunchCityCode::BOGOR)`.
- `test_a_draft_cemetery_is_never_offered` (lines 97-103): `assertSee('TPU Bekasi Jatiasih')` → `$this->firstPublishedExampleName(LaunchCityCode::BEKASI)`; `assertDontSee('TPS Bekasi Harapan Indah')` → `assertDontSee(CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[1])` (index 1 of the row is `name`).
- Doc comment (lines 92-96): "`TPS Bekasi Harapan Indah` is seeded `draft`" → "`CemeteryExampleData::DRAFT_SLUG` is the deliberately-`draft` example cemetery".

- [x] **Step 2: Update `HomePageRouteTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;` and `use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;`.
- Rewrite `test_section_5_shows_published_dummy_cemeteries_and_excludes_the_draft_one` (lines 167-203) to **derive** the expected visible/hidden names from the generator using the exact ordering `HomePage::render()` applies (`orderBy('city')` then `orderBy('name')`, `take(6)`, published only):

```php
    public function test_section_5_shows_published_dummy_cemeteries_and_excludes_the_draft_one(): void
    {
        $response = $this->get('/');
        $response->assertOk();

        // HomePage::render() orders by (city, name) then ->take(6), over
        // published rows only. The expected list is DERIVED from the
        // example-data generator under that same ordering, so a change to
        // the seed data updates the expectation in one place instead of
        // leaving a frozen name snapshot that drifts from the seed.
        $published = collect(CemeteryExampleData::cemeteries())
            ->reject(fn (array $c): bool => $c[7] !== CemeteryPublicationStatus::PUBLISHED)
            ->sortBy([
                fn (array $c): string => $c[3],
                fn (array $c): string => $c[1],
            ])
            ->values();

        $expectedVisibleNames = $published->take(6)->pluck(1)->all();
        $expectedHiddenByCap = $published->skip(6)->pluck(1)->all();

        foreach ($expectedVisibleNames as $name) {
            $response->assertSee($name);
        }

        // Excluded by the draft-publication-status scope (Cemetery::published()):
        $response->assertDontSee(CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[1]);

        // Excluded purely by the ->take(6) display cap — asserted here so a
        // future cap change is a deliberate, visible test update:
        foreach ($expectedHiddenByCap as $name) {
            $response->assertDontSee($name);
        }
    }
```

- Doc comments (lines 147-166, 172-179): reword the named references ("the nine PUBLISHED fixture names", "(`tps-bekasi-harapan-indah`, per `CemeterySeedTest`)") to generator-constant references and drop the literal `tps-bekasi-harapan-indah` and the hand-enumerated six-name list (that enumeration now lives in the derivation).
- Line 195's `assertDontSee('TPS Bekasi Harapan Indah')` and lines 200-202's three `assertDontSee` cap names are superseded by the derivation above — remove them.

- [x] **Step 3: Verify**

Run: `php artisan test --filter=RenewalStartTest`
Run: `php artisan test --filter=HomePageRouteTest`
Expected: PASS. Confirm the derived six-name list still equals the current literal list (`TPU Bekasi Jatiasih`, `TPS Bogor Cimanggu`, `TPU Bogor Bantarjati`, `TPS Depok Cinere`, `TPU Depok Sawangan`, `TPS Jakarta Kemang`) and the hidden-by-cap set equals the three prior `assertDontSee` names — the derivation must reproduce the current behavior, not change it.

- [x] **Step 4: Commit**

```bash
git add tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php tests/Feature/Livewire/Public/HomePageRouteTest.php
git commit -m "test: derive renewal/homepage expectations from the example-data generator"
```

---

### Task 13: Update Directory route tests

**Files:**
- Modify: `tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`

- [x] **Step 1: Update `CemeteryDetailRouteTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 29: `private const string EXAMPLE_SLUG = 'tpu-jakarta-menteng';` → `private const string EXAMPLE_SLUG = CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0];`
- Class docblock (lines 20-23): "`tpu-jakarta-menteng` is used as the worked example throughout: it is one of the two seeded cemeteries that carry `cemetery_packages` rows..." → "`CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]` is used as the worked example throughout: it is one of the two example cemeteries that carry `cemetery_packages` rows...".
- `exampleCemetery()` (line 325-328) needs no change (it uses the constant).

- [x] **Step 2: Update `CemeteryDirectoryIndexRouteTest.php`**

- Add `use App\Support\ExampleData\CemeteryExampleData;`.
- Line 220: `where('slug', 'tpu-jakarta-menteng')` → `where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])`.
- Lines 148-149 (`$tps->count()`, `$tpu->count()`): dynamic, no change.

- [x] **Step 3: Verify**

Run: `php artisan test --filter=CemeteryDetailRouteTest`
Run: `php artisan test --filter=CemeteryDirectoryIndexRouteTest`
Expected: PASS.

- [x] **Step 4: Confirm no test file still names a seeded cemetery**

Run: `grep -rn -iE "tpu-|tps-|menteng|kemang|harapan.indah|sawangan|bantarjati|cimanggu|cinere|cipondoh|karawaci|jatiasih" tests/`
Expected: no matches (the only acceptable residual is `tests/Support/CemeteryFixture.php` and the generator constant definitions themselves, which contain none of these literal names — the generator's constants live in `app/`, not `tests/`).

- [x] **Step 5: Commit**

```bash
git add tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php
git commit -m "test: de-hardcode seeded cemetery slugs in Directory route tests"
```

---

### Task 14: Documentation touch-up

**Files:**
- Modify: `docs/planning/retrofit-backlog.md`
- Modify: `docs/planning/agent-execution-plan.md`

- [x] **Step 1: Add a retrofit-backlog completion row**

Append a row to `docs/planning/retrofit-backlog.md` §1 (the item table), following the established disposition format:

```
| 8 | `CemeteryDirectory` example data de-hardcoding | ✅ **Done** — 13 Aug 2026 | The ten example cemeteries, seven package rows, ten dummy backfills, and fourteen grave records moved out of `database/migrations/` into `App\Support\ExampleData\CemeteryExampleData` (deterministic, per-environment generation called by both the data migrations and a new idempotent `db:seed` path). No new migration, no data change — the generator reproduces the applied rows byte-for-byte; the existing suites (CemeterySeedTest, GraveRecordSeedTest, CemeteryPackageAvailabilityTest) are the output lock. Runtime prose and 13 test files stop naming seeded cemeteries; tests use `CemeteryExampleData` role constants + `tests/Support/CemeteryFixture`. |
```

- [x] **Step 2: Add a one-line pointer in `agent-execution-plan.md`**

Near line 274's "Seeder classes were never the delivery mechanism here" block, add:

```
> **Update, 13 Aug 2026:** `App\Support\ExampleData\CemeteryExampleData` now
> centralizes the example-cemetery data. Migrations still ship it (unchanged
> delivery mechanism); a seeder (`CemeteryExampleDataSeeder`) makes `db:seed`
> produce the same data idempotently for anyone who runs it.
```

- [x] **Step 3: Verify**

Run: `composer lint`
Expected: PASS.

- [x] **Step 4: Commit**

```bash
git add docs/planning/retrofit-backlog.md docs/planning/agent-execution-plan.md
git commit -m "docs: record cemetery example-data de-hardcoding retrofit"
```

---

### Task 15: Full verification

**Files:** none (verification only).

- [x] **Step 1: Lint and static analysis**

Run: `composer lint`
Run: `composer analyse`
Expected: PASS on both (Pint; PHPStan at the repo's configured level).

- [x] **Step 2: Full local suite**

Run: `composer test`
Expected: PASS (SQLite locally; `GraveRecordTrigramSearchTest` self-skips on SQLite — CI is the oracle for PostgreSQL).

- [x] **Step 3: Grep for residual coupling**

Run: `grep -rn -iE "tpu-|tps-|menteng|kemang|harapan.indah|sawangan|bantarjati|cimanggu|cinere|cipondoh|karawaci|jatiasih" app/ resources/ routes/ config/ database/ tests/`
Expected: matches ONLY in:
- `app/Support/ExampleData/CemeteryExampleData.php` (the single source of truth), and
- `database/seeders/CemeteryExampleDataSeeder.php` (imports the class only, no literals).

Any other match is a missed reference — fix it before merge.

- [x] **Step 4: Fresh-migrate smoke**

Run: `php artisan migrate:fresh` against the local test database, then `php artisan test --filter=CemeterySeedTest`
Expected: PASS. This exercises the generator through the real migration path on a brand-new database.

- [x] **Step 5: Whole-branch review + PR**

Per `AGENTS.md` §Development methodology, request a two-tier review (each task was reviewed against its brief as it landed; now review the whole branch once as a unit against this plan). Then open a PR against `docs/design-system-and-planning`. Record the CI run in `docs/planning/retrofit-backlog.md`'s new row.

- [ ] **Step 6: Post-deploy verification note (human, ops, not automated)**

After the deploy of the merged branch: `migrate:status` must show **no new pending migrations** (the edited files keep their original filenames and are recorded as applied on `dev.makam.co.id`). Row counts on dev: 10 cemeteries, 10 current capability profiles, 7 packages, 14 grave records — all unchanged. This is the observable proof that the in-place edit caused zero data drift (D2).

---

## Risk Register

| # | Risk | What breaks | How it's caught | Mitigation |
|---|------|-------------|-----------------|------------|
| 1 | **In-place edit of an already-applied data migration** violates the repo's "never rewrite an applied migration" rule. | Review rejection; or silent divergence if generator output ever differs from the applied rows. | Review (D2 rationale); `CemeterySeedTest`/`GraveRecordSeedTest`/`CemeteryPackageAvailabilityTest` as the byte-level output lock; Task 15 Step 4 fresh-migrate smoke; Task 15 Step 6 post-deploy `migrate:status` + row counts. | The edit reproduces the exact business columns; already-applied environments are skipped by `migrate:status`; no new migration and no data change. Documented as the deliberate exception in the migration's new docblock. |
| 2 | **Slug drift breaks the cross-migration coupling** (backfill updates by slug; grave records reference cemeteries by slug). | Backfill silently updates nothing; grave records silently skip (the `continue` path). | New `CemeteryExampleDataTest::test_every_referenced_slug_exists_in_cemeteries` (Task 1) — fails loudly if any package/backfill/grave-record slug is undefined. | All three migrations read from the one generator; slugs defined once in `cemeteries()`. |
| 3 | **Seeder duplicates rows** on an already-migrated DB (`cemeteries.slug` unique constraint). | `php artisan db:seed` throws on any environment where migrations already ran (every real environment). | Task 5 Step 3 runs `db:seed` twice and asserts no exception and an unchanged `cemeteries` count. | Idempotency guard: skip when any of the ten slugs already exists. |
| 4 | **Test expectations silently drift** if a generator data change passes because expectations were derived from the generator. | Shape changes (10/9/1/14/2) become invisible. | Not caught by derivation — this is why numeric shape assertions stay explicit (D5) and `GraveRecordSeedTest` keeps its fixture-contract role. | Deliberate: counts are the fixture contract; only *references* are generator-derived. |
| 5 | **HomePageRouteTest ordering derivation** changes what the test asserts if the ordering logic is copied wrong. | Test passes while the homepage shows different rows than the assertion claims. | Task 12 Step 3 explicitly requires the derived list to equal the current six-name literal list before removing it. | Derivation uses the exact `orderBy('city')` → `orderBy('name')` → `take(6)` ordering `HomePage::render()` applies, plus `published` filter. |
| 6 | **Mechanical helper swap** (27 call sites in `GraveSearchStatesTest` + 17 in `GraveRegistryPublicQueryTest`) introduces a transcription error. | Wrong cemetery id → wrong fixture state asserted → false pass or false fail. | Task 7 Step 5 runs all three suites; the wrong-cemetery failures are loud (e.g. Kemang's all-restricted records vs Menteng's open ones). | Three-slot mechanical mapping is enumerated explicitly; unrelated literals (`Contoh`, `G-DATA-01`, `ZZ-99`) are excluded. |
| 7 | **`db:seed` behavioral change** — `DatabaseSeeder` now also seeds cemeteries on a database that has never run migrations. | A `migrate:fresh --seed` still works (migrations ran first → guard skips); a bare `db:seed` on an unmigrated DB now writes rows the schema may not support. | Not applicable in practice: `db:seed` on an unmigrated DB was already broken (no tables) and `RefreshDatabase` never calls seeders. | Guard keeps the common path (migrated DB) a no-op; documented in the seeder's docblock. |
| 8 | **Missed runtime prose** still naming a seeded cemetery (blade, `app/`). | User-facing or doc-comment coupling remains. | Task 6 Step 3 and Task 15 Step 3 greps over `app/`, `resources/`, `routes/`, `config/`, `database/`, `tests/`. | Generator constants + role-based wording. |

---

## Option B Honest Assessment (rejected)

A pure seeder + pipeline change is the only design that removes *every* named fixture from `database/migrations/`. It was rejected after verifying the actual delivery path:

- `ci.yml` runs `php artisan test` only; tests use `RefreshDatabase` (migrations only). Making CI seed would require editing the test bootstrap, not just the pipeline.
- The image is "one image, promoted everywhere" (`dev-web`, `stg-web`, `stg-horizon`, workers). A container-start seeder would run in every one of those roles on every restart, requiring idempotency under `cemeteries.slug`'s unique index anyway, plus a migration/seed ordering guarantee.
- `docs/operations/dev-staging-environment.md` §10's deploy flow runs migrations, and three prior migrations document "seeder classes were never the delivery mechanism here." Changing that is an architecture change with no user-visible payoff, and a missed step silently strips `dev.makam.co.id` of its entire cemetery directory — a much worse failure mode than the one being fixed.

Option A keeps migrations as the delivery mechanism (no pipeline change, no environment can lose data), and the generator satisfies the user's approved scope ("move out of a hardcoded migration into a data-generation approach ... per environment"). The honest caveat — that names still exist as example-data definitions, because the honesty framing requires them and tests must address them — is stated in D1.
