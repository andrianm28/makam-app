# Demo Seed-Data Subsystem Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a demo seed-data subsystem that generates realistic, clearly-fake, cleanly-purgeable records across every major makam-app journey (booking, renewal, marketplace, care subscriptions, certificates, vendor accounts, cemetery-operator accounts, visitation) — safe to run directly against the live `makam.co.id` beta host for a live demo, and safe to fully remove afterward. Pre-need agreement seeding was found, during this plan's own research, to require a distinct 7-8-action lifecycle this plan does not otherwise touch — deliberately out of scope, see Task 9.

**Architecture:** One generator class per domain under `App\Support\ExampleData`, each constructing records through real, existing domain Actions (never raw `Model::create()`, except where no Action exists at all — confirmed narrow exceptions only). A shared `DemoContactData` helper is the single source of every fake email/phone/name. A new nullable `demo_batch_id` column tags every row this subsystem writes. A synchronous-listener-level suppression mechanism prevents any seeded record from ever queuing a real notification job, correctly accounting for Laravel's real async queue on beta. Two new artisan commands (`demo-data:seed`, `demo-data:purge`) orchestrate generation and removal.

**Tech Stack:** Laravel 13, PHP 8.5, PostgreSQL 18 (real, never SQLite), Filament 5 (read-only touch points only), PHPUnit, the pinned CI image `ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3`.

**Spec:** `docs/superpowers/specs/2026-09-03-demo-seed-data-design.md` — read it in full before this plan; this plan implements it, including one correction made to the spec itself during this plan's own writing (the notification-suppression mechanism — see Task 3).

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- Every generator constructs records via real, existing domain Actions — direct `Model::create()`/`query()->create()` is used ONLY for the four confirmed exceptions where no Action exists at all: `Vendor`, `VendorUser`, `App\Models\User` (account creation generally has no dedicated Action anywhere in this codebase), and `CemeteryVisitationPolicy`. Every other write goes through the real Action named in this plan's tasks.
- Every contact field (email, phone, name) in every generator calls `DemoContactData` — no generator invents its own fake value.
- Every row this subsystem writes carries the current run's `demo_batch_id` (uuid) via the `App\Support\ExampleData\Concerns\TaggedAsDemoData` helper introduced in Task 1 — an untagged row is unpurgeable by definition, so this is checked explicitly in each task's own review.
- Zero `random()`/`time()`/non-deterministic generation anywhere. Every generator takes a plain integer `$index` (or derives one) and produces byte-identical output for the same index every run, matching `CemeteryExampleData`'s existing convention exactly (index math, e.g. `intdiv($index, 2)`, never a random seed).
- Real Postgres 18 (never SQLite) via the pinned CI image for every task's tests. All verification commands (pint, phpstan, phpunit) run inside `docker run --network host --user 1000:1000 -v <worktree>:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 <command>` against disposable Postgres/Redis containers — spin up, use, tear down. Never the live `makam-nonprod-*` containers. **Never, under any circumstance, the live beta database** — no task in this plan runs `demo-data:seed` against anything but a disposable local Postgres instance; actually running it on beta is explicitly out of scope (spec decision 5), a separate action after this plan's PR is reviewed and merged.
- `vendor/bin/pint --test` must stay clean throughout.
- `vendor/bin/phpstan analyse --no-progress --memory-limit=1G` — **no file-path arguments**. This repo's `phpstan.neon` scopes `paths: app` only (confirmed by reading it directly); passing explicit test-file arguments produces false-positive errors unrelated to real CI (a lesson already learned this session). Verify test files via phpunit only; always run phpstan exactly as `.github/workflows/ci.yml` does.
- `bash ci/verify-docs.sh` must stay clean if any task touches `docs/`.
- Every Action call in this plan's own text is quoted from the real, current source (verified during this plan's writing) — if a task's implementer finds a signature has changed since, that is a real plan defect worth flagging in their report, not something to silently paper over.

---

## Task 1: `demo_batch_id` schema + `demo_data_batches` table + tagging helper

**Files:**
- Create: `database/migrations/2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php`
- Create: `database/migrations/2026_09_03_150010_create_demo_data_batches_table.php`
- Create: `app/Support/ExampleData/Concerns/TaggedAsDemoData.php`
- Test: `tests/Feature/Support/ExampleData/TaggedAsDemoDataTest.php`

**Interfaces:**
- Consumes: nothing (foundational task).
- Produces: `App\Support\ExampleData\Concerns\TaggedAsDemoData::tag(Model $model, string $batchId): void` — a static helper every later generator calls immediately after any Action returns a model, before moving on. `demo_data_batches` table (`id` uuid PK, `batch_id` uuid unique, `created_at` timestamp, `summary` json nullable) — Task 10/11 write/read this.

- [ ] **Step 1: Write the failing test for the tagging helper**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TaggedAsDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_tag_sets_demo_batch_id_and_saves(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        TaggedAsDemoData::tag($cemetery, $batchId);

        $this->assertSame($batchId, $cemetery->fresh()->demo_batch_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm --network host --user 1000:1000 -e APP_ENV=testing -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<disposable-pg-port> -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test -v <worktree>:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Support/ExampleData/TaggedAsDemoDataTest.php`

Expected: FAIL — `demo_batch_id` column does not exist on `cemeteries`, and `TaggedAsDemoData` class does not exist.

- [ ] **Step 3: Write the migration adding `demo_batch_id` to every touched table**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One nullable, indexed `demo_batch_id` (uuid) column added to every table
 * the demo seed-data subsystem writes to
 * (docs/superpowers/specs/2026-09-03-demo-seed-data-design.md, decision 1).
 * Nullable and inert for every real row — a temporary, beta-era marker, not
 * a permanent architectural decision. `cemeteries` already has real example
 * cemeteries identified by slug (`PurgeExampleDataCommand`); this column is
 * added there too so any NEW demo-specific cemetery this subsystem creates
 * (for the cemetery-operator account's scope grant) is independently
 * purgeable without touching the existing slug-based mechanism.
 */
return new class extends Migration
{
    private const array TABLES = [
        'booking_drafts', 'orders', 'renewals', 'care_plans', 'subscriptions',
        'agreements', 'certificates', 'vendors', 'vendor_users',
        'marketplace_orders', 'vendor_orders', 'visitation_bookings',
        'users', 'cemeteries', 'cemetery_visitation_policies',
        'actor_role_assignments', 'scope_assignments',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->uuid('demo_batch_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('demo_batch_id');
            });
        }
    }
};
```

- [ ] **Step 4: Write the `demo_data_batches` migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per `demo-data:seed` run (Task 10). `demo-data:purge` (Task 11)
 * reads the most recent row here when no explicit batch id is given, and
 * this table is itself the human-readable audit trail of what's been
 * seeded when — deliberately NOT tagged with its own demo_batch_id column
 * (it IS the batch registry, not seeded data itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_data_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->unique();
            $table->json('summary')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_data_batches');
    }
};
```

- [ ] **Step 5: Write the tagging helper**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData\Concerns;

use Illuminate\Database\Eloquent\Model;

/**
 * The single place every demo generator marks a row as this subsystem's
 * own, so `demo-data:purge` (Task 11) can find and remove it. Deliberately
 * a plain static helper, not a trait mixed into every touched model — the
 * models this subsystem writes to (Order, Renewal, CarePlan, Certificate,
 * VisitationBooking, ...) belong to many different domains this subsystem does
 * not own; adding a trait to each would mean touching files outside this
 * subsystem's boundary for no real benefit over a one-line call at the
 * generator's own call site.
 */
final class TaggedAsDemoData
{
    public static function tag(Model $model, string $batchId): void
    {
        $model->forceFill(['demo_batch_id' => $batchId])->save();
    }
}
```

- [ ] **Step 6: Run migrations, then the test to verify it passes**

Run: `... php artisan migrate --force` then `... vendor/bin/phpunit tests/Feature/Support/ExampleData/TaggedAsDemoDataTest.php`

Expected: PASS.

- [ ] **Step 7: Run pint and phpstan on the new files**

Run: `... vendor/bin/pint --test database/migrations/2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php database/migrations/2026_09_03_150010_create_demo_data_batches_table.php app/Support/ExampleData/Concerns/TaggedAsDemoData.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_03_150000_add_demo_batch_id_for_demo_seed_data.php database/migrations/2026_09_03_150010_create_demo_data_batches_table.php app/Support/ExampleData/Concerns/TaggedAsDemoData.php tests/Feature/Support/ExampleData/TaggedAsDemoDataTest.php
git commit -m "feat(demo-data): add demo_batch_id schema marker and tagging helper"
```

---

## Task 2: `DemoContactData` — the shared safe-contact-data helper

**Files:**
- Create: `app/Support/ExampleData/DemoContactData.php`
- Test: `tests/Unit/Support/ExampleData/DemoContactDataTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `DemoContactData::email(int $index): string`, `DemoContactData::phone(int $index): string`, `DemoContactData::personName(int $index): string` — every later generator (Tasks 4–9) calls these three for every contact field it needs. All three are pure, deterministic functions of `$index`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Support\ExampleData\DemoContactData;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class DemoContactDataTest extends TestCase
{
    public function test_email_is_deterministic_and_uses_a_reserved_domain(): void
    {
        $first = DemoContactData::email(0);
        $second = DemoContactData::email(0);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $first);
    }

    public function test_email_varies_by_index(): void
    {
        $this->assertNotSame(DemoContactData::email(0), DemoContactData::email(1));
    }

    #[DataProvider('phoneIndexes')]
    public function test_phone_is_deterministic_and_matches_the_reserved_block(int $index): void
    {
        $first = DemoContactData::phone($index);
        $second = DemoContactData::phone($index);

        $this->assertSame($first, $second);
        $this->assertMatchesRegularExpression('/^08118990\d{4}$/', $first);
    }

    /**
     * @return list<array{0: int}>
     */
    public static function phoneIndexes(): array
    {
        return [[0], [1], [9999]];
    }

    public function test_phone_matches_the_booking_wizard_customer_mobile_validation_pattern(): void
    {
        // The exact pattern `SaveBookingDraftStep::validateCustomer()` enforces
        // for customer_mobile — confirmed against the real regex during this
        // plan's own research: ^(\+62|62|0)[0-9]{9,13}$
        $this->assertMatchesRegularExpression('/^(\+62|62|0)[0-9]{9,13}$/', DemoContactData::phone(0));
    }

    public function test_person_name_is_deterministic_and_carries_the_contoh_marker(): void
    {
        $first = DemoContactData::personName(0);

        $this->assertSame($first, DemoContactData::personName(0));
        $this->assertStringContainsString('Contoh', $first);
    }

    public function test_person_name_varies_by_index(): void
    {
        $this->assertNotSame(DemoContactData::personName(0), DemoContactData::personName(1));
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL — class `App\Support\ExampleData\DemoContactData` does not exist.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

/**
 * The single source of every fake contact value this subsystem's
 * generators use (docs/superpowers/specs/2026-09-03-demo-seed-data-design.md,
 * decisions 2 and the email convention). No generator invents its own fake
 * email/phone/name — every one calls here.
 *
 * Email: `@example.com`/`.org`/`.net` — RFC 2606-reserved, guaranteed
 * non-deliverable, matching Faker's own `safeEmail()` convention.
 *
 * Phone: the `0811-8990-XXXX` block. `0811` is a real, allocated Telkomsel
 * mobile prefix — deliberately chosen so the generated value is
 * STRUCTURALLY VALID (passes `SaveBookingDraftStep::validateCustomer()`'s
 * `^(\+62|62|0)[0-9]{9,13}$` check the same way a real submission would),
 * which is both better demo fidelity and necessary for seeded booking
 * drafts to actually save. The `8990` block plus a 4-digit deterministic
 * suffix is a RESERVED, do-not-dial range for this codebase's own demo
 * data — never allocate a real customer or vendor a number in this exact
 * block. WhatsApp is not live yet (`WhatsAppMode` gate closed) so this
 * carries no live-notification risk today, but the reservation is
 * documented here so it still means something once WhatsApp is wired up.
 *
 * Name: extends the existing "Contoh" convention (`CemeteryExampleData`)
 * with realistic-looking Indonesian given/family name pairs — still
 * unmistakably fictional (every one contains the literal word "Contoh"),
 * but reads naturally in a live demo rather than as a placeholder string.
 */
final class DemoContactData
{
    private const array EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net'];

    private const array GIVEN_NAMES = [
        'Budi', 'Siti', 'Andi', 'Dewi', 'Agus', 'Rina', 'Joko', 'Sri',
        'Hendra', 'Wati',
    ];

    public static function email(int $index): string
    {
        $domain = self::EMAIL_DOMAINS[$index % count(self::EMAIL_DOMAINS)];

        return sprintf('demo.contoh%d@%s', $index, $domain);
    }

    public static function phone(int $index): string
    {
        $suffix = str_pad((string) ($index % 10000), 4, '0', STR_PAD_LEFT);

        return '08118990'.$suffix;
    }

    public static function personName(int $index): string
    {
        $given = self::GIVEN_NAMES[$index % count(self::GIVEN_NAMES)];
        $sequence = intdiv($index, count(self::GIVEN_NAMES)) + 1;

        return sprintf('%s Contoh %d', $given, $sequence);
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Expected: PASS, all 6 tests.

- [ ] **Step 5: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/DemoContactData.php tests/Unit/Support/ExampleData/DemoContactDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ExampleData/DemoContactData.php tests/Unit/Support/ExampleData/DemoContactDataTest.php
git commit -m "feat(demo-data): add DemoContactData safe email/phone/name helper"
```

---

## Task 3: `DemoDataSuppression` + wiring into the two synchronous outbox listeners

**Files:**
- Create: `app/Platform/Notification/DemoDataSuppression.php`
- Modify: `app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php`
- Modify: `app/Platform/Notification/Listeners/DispatchNotificationConsumerOnOutboxEventPublished.php`
- Test: `tests/Unit/Platform/Notification/DemoDataSuppressionTest.php`
- Test: `tests/Feature/Platform/Notification/DemoDataSuppressionIntegrationTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: `DemoDataSuppression::active(): bool`, `DemoDataSuppression::run(callable $callback): mixed` — Task 10's `demo-data:seed` command wraps its entire generator-running body in `DemoDataSuppression::run(fn () => ...)`.

**Correction from the spec, made during this plan's own research (read this before implementing):** the spec's original text said to check the suppression flag inside `SendNotificationChannelJob`. That is wrong — `SendNotificationChannelJob implements ShouldQueue` and, outside the test environment, runs on Laravel's real `database` queue driver in a separate `queue:work` worker process (`config/queue.php`'s default is `env('QUEUE_CONNECTION', 'database')`; only `phpunit.xml` overrides this to `sync`). A flag set in the seed command's own process would be invisible there. The real choke point is one step earlier and simpler: `ConsumeOutboxNotificationJob` (the actual queued job) is dispatched by exactly two **plain, non-queued** listeners on the synchronous `OutboxEventPublished` Laravel event — `DispatchOrderNotifications` (the order-lifecycle bridge) and `DispatchNotificationConsumerOnOutboxEventPublished` (the generic path for every other domain). Both run **synchronously, in the same PHP process** that wrote the outbox event — which, for every write this subsystem makes, is the `demo-data:seed` command's own CLI process. The spec document itself has already been corrected to reflect this (see its "Notification safety" section) — this task implements the corrected design, not the original.

- [ ] **Step 1: Read the two listener files in full before editing**

Read `app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php` and `app/Platform/Notification/Listeners/DispatchNotificationConsumerOnOutboxEventPublished.php` end to end. Confirm each one's exact `handle()` method and the exact line where it calls `ConsumeOutboxNotificationJob::dispatch(...)` — `DispatchOrderNotifications::handle()` was read in full during this plan's research and its dispatch call is at:

```php
ConsumeOutboxNotificationJob::dispatch($eventId, $matrixEventName)
    ->onQueue(OutboxQueueName::Notifications->value);
```

`DispatchNotificationConsumerOnOutboxEventPublished` was NOT read in full during this plan's research — its own dispatch call must exist (the generic path for every non-order domain) but its exact shape needs confirming at implementation time; it is very likely structurally identical (same `ConsumeOutboxNotificationJob::dispatch(...)->onQueue(...)` shape, driven by whichever outbox event name matched). If its shape differs meaningfully from `DispatchOrderNotifications`'s, treat that as a real finding to report, not something to force into an identical diff.

- [ ] **Step 2: Write the failing unit test for the flag itself**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\DemoDataSuppression;
use Tests\TestCase;

final class DemoDataSuppressionTest extends TestCase
{
    public function test_active_is_false_by_default(): void
    {
        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_makes_active_true_for_the_duration_of_the_callback(): void
    {
        $observedDuringRun = null;

        DemoDataSuppression::run(function () use (&$observedDuringRun): void {
            $observedDuringRun = DemoDataSuppression::active();
        });

        $this->assertTrue($observedDuringRun);
        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_clears_the_flag_even_when_the_callback_throws(): void
    {
        try {
            DemoDataSuppression::run(function (): void {
                throw new \RuntimeException('deliberate failure mid-run');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertFalse(DemoDataSuppression::active());
    }

    public function test_run_returns_the_callbacks_return_value(): void
    {
        $result = DemoDataSuppression::run(fn (): string => 'batch-summary');

        $this->assertSame('batch-summary', $result);
    }
}
```

- [ ] **Step 2b: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Write the implementation**

```php
<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * Prevents `demo-data:seed` (Task 10) from ever queuing a real notification
 * job. See `DispatchOrderNotifications` and
 * `DispatchNotificationConsumerOnOutboxEventPublished` — both check
 * `active()` immediately before dispatching `ConsumeOutboxNotificationJob`,
 * the actual queued/async notification job. A plain, static, in-process
 * flag is correct here (not a config value, not a database row) because
 * both call sites run synchronously in the SAME PHP process that raised
 * the outbox event in the first place — the seed command's own CLI
 * process. Nothing else in the system ever shares that process, so this
 * can never suppress or interfere with a real, concurrent customer
 * notification.
 */
final class DemoDataSuppression
{
    private static bool $active = false;

    public static function active(): bool
    {
        return self::$active;
    }

    /**
     * @template T
     * @param  callable(): T  $callback
     * @return T
     */
    public static function run(callable $callback): mixed
    {
        self::$active = true;

        try {
            return $callback();
        } finally {
            self::$active = false;
        }
    }
}
```

- [ ] **Step 4: Run to verify the unit tests pass**

Expected: PASS, all 4 tests.

- [ ] **Step 5: Wire the flag into `DispatchOrderNotifications`**

Modify the dispatch call found in Step 1:

```php
if (\App\Platform\Notification\DemoDataSuppression::active()) {
    \Illuminate\Support\Facades\Log::info('notification.suppressed_for_demo_seeding', [
        'outbox_event_id' => $eventId,
        'matrix_event_name' => $matrixEventName,
    ]);

    return;
}

ConsumeOutboxNotificationJob::dispatch($eventId, $matrixEventName)
    ->onQueue(OutboxQueueName::Notifications->value);
```

Add `use App\Platform\Notification\DemoDataSuppression;` and `use Illuminate\Support\Facades\Log;` to the file's imports (following this file's own existing import style).

- [ ] **Step 6: Wire the identical guard into `DispatchNotificationConsumerOnOutboxEventPublished`**

Read the file's real current `handle()` method (per Step 1's note that this wasn't read in full during research) and add the same `DemoDataSuppression::active()` check immediately before whatever its own `ConsumeOutboxNotificationJob::dispatch(...)` call looks like, logging the same way. Match this file's own existing variable names for the outbox event id / matrix event name rather than assuming they're identical to `DispatchOrderNotifications`'s.

- [ ] **Step 7: Write the integration test proving both listeners genuinely suppress dispatch**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Platform\Notification;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\Notification\DemoDataSuppression;
use App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * `SubmitBookingDraft`'s own MASUK-status outbox event is the real,
 * existing, order-lifecycle trigger `DispatchOrderNotifications` bridges —
 * this proves the suppression guard added to that listener genuinely
 * prevents the queued job, using a real order submission rather than a
 * synthetic outbox event.
 */
final class DemoDataSuppressionIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function realBookingSubmission(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::DISCOVERY, [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                ServiceCode::BASIC_CODES,
            ),
        ], 'idem-discovery-'.$draft->id);

        app(\App\Domain\OrderWorkflow\Actions\SubmitBookingDraft::class)($draft, 'idem-submit-'.$draft->id);
    }

    public function test_a_real_order_submission_during_suppression_never_queues_the_notification_job(): void
    {
        Queue::fake();

        DemoDataSuppression::run(fn () => $this->realBookingSubmission());

        Queue::assertNotPushed(ConsumeOutboxNotificationJob::class);
    }

    public function test_the_same_submission_outside_suppression_queues_the_notification_job_normally(): void
    {
        Queue::fake();

        $this->realBookingSubmission();

        Queue::assertPushed(ConsumeOutboxNotificationJob::class);
    }
}
```

- [ ] **Step 8: Run to verify both tests pass**

Expected: PASS, both tests — this is the load-bearing proof that suppression works AND that normal behavior is untouched outside a seed run.

- [ ] **Step 9: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Platform/Notification/DemoDataSuppression.php app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php app/Platform/Notification/Listeners/DispatchNotificationConsumerOnOutboxEventPublished.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 10: Commit**

```bash
git add app/Platform/Notification/DemoDataSuppression.php app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php app/Platform/Notification/Listeners/DispatchNotificationConsumerOnOutboxEventPublished.php tests/Unit/Platform/Notification/DemoDataSuppressionTest.php tests/Feature/Platform/Notification/DemoDataSuppressionIntegrationTest.php
git commit -m "feat(demo-data): suppress notification dispatch at the synchronous outbox listeners during a seed run"
```

---

## Task 4: `VendorAccountExampleData` + `CemeteryOperatorExampleData`

**Files:**
- Create: `app/Support/ExampleData/VendorAccountExampleData.php`
- Create: `app/Support/ExampleData/CemeteryOperatorExampleData.php`
- Test: `tests/Feature/Support/ExampleData/VendorAccountExampleDataTest.php`
- Test: `tests/Feature/Support/ExampleData/CemeteryOperatorExampleDataTest.php`

**Interfaces:**
- Consumes: `DemoContactData` (Task 2), `TaggedAsDemoData` (Task 1).
- Produces: `VendorAccountExampleData::seed(string $batchId): array{vendors: list<Vendor>, users: list<User>}` — Task 7 (`MarketplaceOrderExampleData`) consumes `vendors[0]->id` as the vendor to place demo marketplace orders against. `CemeteryOperatorExampleData::seed(string $batchId, string $cemeteryId): User` — takes an existing demo cemetery id (Task 5 or `CemeteryExampleData`'s own seeded cemeteries) to grant scope against.

**Real signatures this task uses (verified during this plan's research):**
- `App\Models\User` — fillable `['name', 'email', 'password']` (Laravel 13 `#[Fillable]` attribute style), `password` cast `'hashed'`. Role is NOT a `users` column.
- `App\Platform\IdentityAccess\Roles\Actions\GrantActorRole::__invoke(int|string $actorIdentifier, string $role, string $reason, int|string|null $grantedBy): ActorRoleAssignment` — console-only per its own doc block (fine, this generator runs from an artisan command).
- `App\Domain\Marketplace\Models\Vendor` — fillable `['name', 'is_active']` only.
- `App\Domain\Marketplace\Models\VendorUser` — fillable `['vendor_id', 'actor_identifier', 'revoked_at']`, no dedicated Action, `id` is `bigIncrements`. `actor_identifier` links to the `User`'s id (matching how `VendorPanelAccessPolicy`/`ScopesToCurrentVendor` resolve the current vendor from the authenticated user elsewhere in this codebase — confirm the exact identifier shape, likely the `users.id` cast to string, by reading one real existing caller, e.g. `app/Filament/Vendor` panel's auth resolution, before finalizing).
- `App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment::__invoke(int|string $actorIdentifier, string $entityType, int|string $entityId, ?string $grantLevel, string $reason, int|string|null $grantedBy): ScopeAssignment` — `$entityType = App\Platform\IdentityAccess\Scopes\ScopeEntityType::CEMETERY`.
- `App\Platform\IdentityAccess\Roles\ActorRole::VENDOR` / `::CEMETERY_OPERATOR` — both confirmed present in `KNOWN_ROLES`.

**Demo credentials (spec decision 4):** every demo account created by this task uses the fixed password `'DemoContoh2026!'` — deterministic, documented (Task 12 records it), never generated-and-shown-once.

- [ ] **Step 1: Write the failing test for `VendorAccountExampleData`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\Marketplace\Models\Vendor;
use App\Support\ExampleData\VendorAccountExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VendorAccountExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_a_vendor_a_user_and_links_them_with_a_working_login(): void
    {
        $batchId = (string) Str::uuid();

        $result = VendorAccountExampleData::seed($batchId);

        $this->assertNotEmpty($result['vendors']);
        $this->assertNotEmpty($result['users']);

        $vendor = $result['vendors'][0];
        $user = $result['users'][0];

        $this->assertSame($batchId, $vendor->fresh()->demo_batch_id);
        $this->assertSame($batchId, $user->fresh()->demo_batch_id);
        $this->assertStringContainsString('@example.', $user->email);
        $this->assertTrue(Hash::check('DemoContoh2026!', $user->fresh()->password));

        $this->assertDatabaseHas('vendor_users', [
            'vendor_id' => $vendor->id,
            'demo_batch_id' => $batchId,
        ]);
        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $user->id,
            'role' => \App\Platform\IdentityAccess\Roles\ActorRole::VENDOR,
            'demo_batch_id' => $batchId,
        ]);
        // The actual authorization grant — vendor_users alone is
        // membership metadata only. Without this, the seeded account
        // could not really log into /vendor.
        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => (string) $user->id,
            'entity_id' => $vendor->id,
            'demo_batch_id' => $batchId,
        ]);
    }

    public function test_seed_is_deterministic(): void
    {
        $batchId = (string) Str::uuid();

        // A raw assertDatabaseCount('vendors', ...) is wrong against this
        // repo's real migrated schema: 2026_08_14_100000_seed_vendors_and_
        // listings.php seeds 5 fixture vendors unconditionally on every
        // RefreshDatabase run, so the table never starts empty. Scope to
        // this batch instead.
        $first = VendorAccountExampleData::seed($batchId);
        $this->assertSame(
            count($first['vendors']),
            Vendor::query()->where('demo_batch_id', $batchId)->count(),
        );

        // A second seed call with the SAME batch id and a fresh database
        // produces the same vendor names — proving no randomness, matching
        // this subsystem's determinism constraint.
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Read the real `actor_role_assignments` write path and `VendorUser`'s `actor_identifier` shape before implementing**

Read one real existing caller that resolves a `VendorUser` from an authenticated `User` (e.g. `App\Filament\Vendor\Concerns\ScopesToCurrentVendor` or wherever `VendorPanelAccessPolicy` reads it) to confirm `actor_identifier`'s exact expected format (string-cast user id vs. something else) before writing this generator — do not guess.

- [ ] **Step 4: Write `VendorAccountExampleData`**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorUser;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Support\Facades\Hash;

/**
 * Two demo vendor accounts, each a real User (real login) linked to a real
 * Vendor via VendorUser. Direct model creation for Vendor/VendorUser/User
 * is correct here — confirmed during this subsystem's design that no
 * dedicated domain Action exists for any of the three anywhere in this
 * codebase (Filament's own CreateVendor page is a plain CreateRecord over
 * the Eloquent model).
 *
 * **Real gap this text corrects, found by Task 4's own implementer**:
 * `VendorUser` is membership metadata only ("authorization is decided by
 * `scope_assignments`, never by this table" — that model's own class doc
 * block, and its migration's) — `VendorPanelAccessPolicy::allows()`
 * genuinely requires BOTH the `ActorRole::VENDOR` role AND an active
 * `vendor:`-prefixed scope grant read via `CurrentVendorScope`. A role
 * grant alone (the earlier draft of this class) would create an account
 * that cannot actually log into `/vendor` — a broken deliverable, since
 * Task 12 documents this login as working. The `GrantScopeAssignment` call
 * below is not optional decoration; it is the actual authorization grant.
 */
final class VendorAccountExampleData
{
    private const string DEMO_PASSWORD = 'DemoContoh2026!';

    /**
     * @return array{vendors: list<Vendor>, users: list<User>}
     */
    public static function seed(string $batchId): array
    {
        $vendors = [];
        $users = [];

        foreach (range(0, 1) as $index) {
            $vendor = Vendor::query()->create([
                'name' => sprintf('Toko Contoh Demo %d', $index + 1),
                'is_active' => true,
            ]);
            TaggedAsDemoData::tag($vendor, $batchId);

            $user = User::query()->create([
                'name' => DemoContactData::personName($index),
                'email' => DemoContactData::email($index),
                'password' => Hash::make(self::DEMO_PASSWORD),
            ]);
            TaggedAsDemoData::tag($user, $batchId);

            $vendorUser = VendorUser::query()->create([
                'vendor_id' => $vendor->id,
                'actor_identifier' => (string) $user->id,
            ]);
            TaggedAsDemoData::tag($vendorUser, $batchId);

            $roleAssignment = (new GrantActorRole)(
                actorIdentifier: (string) $user->id,
                role: ActorRole::VENDOR,
                reason: 'Demo seed data — live demo vendor account.',
                grantedBy: null,
            );
            TaggedAsDemoData::tag($roleAssignment, $batchId);

            $scopeAssignment = (new GrantScopeAssignment)(
                actorIdentifier: (string) $user->id,
                entityType: ScopeEntityType::VENDOR,
                entityId: $vendor->id,
                grantLevel: null,
                reason: 'Demo seed data — the actual authorization grant for /vendor access (vendor_users is membership metadata only).',
                grantedBy: null,
            );
            TaggedAsDemoData::tag($scopeAssignment, $batchId);

            $vendors[] = $vendor;
            $users[] = $user;
        }

        return ['vendors' => $vendors, 'users' => $users];
    }
}
```

- [ ] **Step 5: Run to verify the test passes**

Expected: PASS.

- [ ] **Step 6: Write the failing test for `CemeteryOperatorExampleData`**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Support\ExampleData\CemeteryOperatorExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CemeteryOperatorExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_a_scoped_operator_with_a_working_login(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Operator',
            'slug' => 'tpu-contoh-operator-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        $user = CemeteryOperatorExampleData::seed($batchId, $cemetery->id);

        $this->assertSame($batchId, $user->fresh()->demo_batch_id);
        $this->assertTrue(Hash::check('DemoContoh2026!', $user->fresh()->password));
        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $user->id,
            'role' => ActorRole::CEMETERY_OPERATOR,
            'demo_batch_id' => $batchId,
        ]);
        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => (string) $user->id,
            'entity_id' => $cemetery->id,
            'demo_batch_id' => $batchId,
        ]);
    }
}
```

- [ ] **Step 7: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 8: Write `CemeteryOperatorExampleData`**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Illuminate\Support\Facades\Hash;

/**
 * One demo cemetery-operator account, real login, granted scope to exactly
 * one demo cemetery — enough for `/operator` and the plot floor map
 * (TPU/TPS dashboard roadmap, PRs #209-216) to have something real to show
 * in scoped mode, alongside an admin account seeing everything.
 */
final class CemeteryOperatorExampleData
{
    private const string DEMO_PASSWORD = 'DemoContoh2026!';

    private const int INDEX = 0;

    public static function seed(string $batchId, string $cemeteryId): User
    {
        $user = User::query()->create([
            'name' => DemoContactData::personName(self::INDEX + 100),
            'email' => DemoContactData::email(self::INDEX + 100),
            'password' => Hash::make(self::DEMO_PASSWORD),
        ]);
        TaggedAsDemoData::tag($user, $batchId);

        $roleAssignment = (new GrantActorRole)(
            actorIdentifier: (string) $user->id,
            role: ActorRole::CEMETERY_OPERATOR,
            reason: 'Demo seed data — live demo cemetery-operator account.',
            grantedBy: null,
        );
        TaggedAsDemoData::tag($roleAssignment, $batchId);

        $scopeAssignment = (new GrantScopeAssignment)(
            actorIdentifier: (string) $user->id,
            entityType: ScopeEntityType::CEMETERY,
            entityId: $cemeteryId,
            grantLevel: null,
            reason: 'Demo seed data — scoped to one demo cemetery.',
            grantedBy: null,
        );
        TaggedAsDemoData::tag($scopeAssignment, $batchId);

        return $user;
    }
}
```

- [ ] **Step 9: Run to verify the test passes**

Expected: PASS.

- [ ] **Step 10: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/VendorAccountExampleData.php app/Support/ExampleData/CemeteryOperatorExampleData.php tests/Feature/Support/ExampleData/VendorAccountExampleDataTest.php tests/Feature/Support/ExampleData/CemeteryOperatorExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 11: Commit**

```bash
git add app/Support/ExampleData/VendorAccountExampleData.php app/Support/ExampleData/CemeteryOperatorExampleData.php tests/Feature/Support/ExampleData/VendorAccountExampleDataTest.php tests/Feature/Support/ExampleData/CemeteryOperatorExampleDataTest.php
git commit -m "feat(demo-data): add vendor account and cemetery-operator account generators"
```

---

## Task 5: `BookingOrderExampleData`

**Files:**
- Create: `app/Support/ExampleData/BookingOrderExampleData.php`
- Test: `tests/Feature/Support/ExampleData/BookingOrderExampleDataTest.php`

**Interfaces:**
- Consumes: `DemoContactData` (Task 2), `TaggedAsDemoData` (Task 1).
- Produces: `BookingOrderExampleData::seed(string $batchId): list<Order>` — a 5-element list in the fixed order `[DIVERIFIKASI, PENAWARAN_TERKIRIM, DIBAYAR, SELESAI, DITOLAK]`. Task 9's `CertificateExampleData` consumes index `[2]` (the `DIBAYAR` order) specifically — `CertificateType::OrderSettlement`'s eligibility rule requires exactly that status, not `SELESAI`.

**Real signatures this task uses (verified during this plan's research):**
- `StartBookingDraft::__invoke(?int $userId = null): BookingDraft`
- `SaveBookingDraftStep::__invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey, ?int $expectedVersion = null): BookingDraft` — steps `BookingWizardStep::DISCOVERY=1, CUSTOMER_AND_DECEASED_DATA=2, PAYMENT=3, CONFIRMATION=4` (CONFIRMATION is read-only, never saved directly).
  - DISCOVERY payload: `city_code`, `cemetery_id`, `cemetery_package_id` (null for a cemetery with no active packages — use `CemeteryPublicQuery::activePackages($cemetery)->isEmpty()` to pick one, or reuse an existing package-less demo cemetery), `service_type` (a `BookingServiceType` value), `selected_services` (must include every `ServiceCode::BASIC_CODES` value, `[{code, quantity: 1}, ...]`).
  - CUSTOMER_AND_DECEASED_DATA payload: `customer_full_name`, `customer_mobile` (digits only, e.g. `DemoContactData::phone($i)` as-is — no dashes), `customer_email` (`DemoContactData::email($i)`), `customer_address` (≥10 chars), `customer_relationship` (a `BookingRelationshipCode` value), `customer_contact_channel` (a `BookingContactChannel` value), `privacy_notice_accepted: true`, `deceased_full_name`, `deceased_date_of_birth`/`deceased_date_of_death` (DOB before DOD, DOD not in the future), `deceased_relationship`, `deceased_gender` (optional). The three `document_*_path` fields must be omitted/null.
  - PAYMENT payload: `payment_method: BookingPaymentMethod::MANUAL` (never `ONLINE` — that depends on the `G-PAY-01` gate's live state, an environment dependency this generator must not carry), `payment_reference` (non-blank string).
- `SubmitBookingDraft::__invoke(BookingDraft $draft, string $idempotencyKey): Order`
- `VerifyOrder::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent`
- `IssueOrderQuote::__invoke(Order $order, CarbonInterface $expiresAt, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent`
- `ManualPaymentVerification::__invoke(Order $order, string $actorRef, string $actorRole, string $verificationNote, array $metadata = []): OrderStatusEvent`
- `MarkOrderPaid::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null): Order` — requires `IssueOrderQuote` to have already run (reads `Quote::currentFor($order)`); internally calls `ApplyPaidEffects`, which is the sole writer of `DIBAYAR` — **lands the order on `DIBAYAR`, not `SELESAI`**.
- `ProcessOrder::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` — the confirmed `DIBAYAR → DIPROSES` hop (read in full during this plan's own research to resolve exactly this gap).
- `RejectOrder::__invoke(Order $order, string $actorRef, string $actorRole, string $reason, array $metadata = []): OrderStatusEvent` — `$reason` required.
- `CompleteOrder::__invoke(Order $order, string $actorRef, string $actorRole, ?string $reason = null, array $metadata = []): OrderStatusEvent` — writes `SELESAI`, only legal from `DIPROSES` (`OrderTransition::ALLOWED['DIPROSES'] = ['SELESAI']`, confirmed by reading the transition table directly).
- `OrderStatus` is a real backed enum: `MASUK, DIVERIFIKASI, MENUNGGU_KETERSEDIAAN, PENAWARAN_TERKIRIM, DISETUJUI_PEMESAN, MENUNGGU_PEMBAYARAN, MENUNGGU_VERIFIKASI_PEMBAYARAN, DIBAYAR, DIPROSES, SELESAI, DITOLAK, DIBATALKAN, KEDALUWARSA`.
- `OrderTransition::ALLOWED` (confirmed verbatim): `DIBAYAR => [DIPROSES]`, `DIPROSES => [SELESAI]`, `SELESAI => []` — the full chain to a demo-complete order is `... → MarkOrderPaid (→DIBAYAR) → ProcessOrder (→DIPROSES) → CompleteOrder (→SELESAI)`, three hops, not two.

**Demo variety (5 orders, one per named state — widened from the spec's 4 during this plan's own research: `CertificateType::OrderSettlement`'s eligibility rule, confirmed by reading `CertificateEligibilityPolicy` directly, requires an order at EXACTLY `DIBAYAR`, not `SELESAI` — Task 9's certificate generator needs an order that stops there, which is also a real, meaningful, independently demo-worthy state in its own right: "payment verified, awaiting processing"):**
1. **Diverifikasi** — submitted, not yet verified. Stops after `SubmitBookingDraft`.
2. **Penawaran terkirim** — verified + quoted. `SubmitBookingDraft` → `VerifyOrder` → `IssueOrderQuote`.
3. **Dibayar** (paid, awaiting processing) — `SubmitBookingDraft` → `VerifyOrder` → `IssueOrderQuote` → `ManualPaymentVerification` → `MarkOrderPaid`. Stops here, at exactly `DIBAYAR` — this is the order Task 9's `CertificateExampleData` consumes.
4. **Selesai** (paid + processed + completed) — the full happy path, continuing past state 3: `ProcessOrder` → `CompleteOrder`.
5. **Ditolak** — verified then rejected. `SubmitBookingDraft` → `VerifyOrder` → `RejectOrder`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\ExampleData\BookingOrderExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class BookingOrderExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_orders_across_the_five_named_states(): void
    {
        $batchId = (string) Str::uuid();

        $orders = BookingOrderExampleData::seed($batchId);

        $statuses = array_map(static fn ($order): string => $order->status()->value, $orders);

        $this->assertContains(OrderStatus::DIVERIFIKASI->value, $statuses);
        $this->assertContains(OrderStatus::PENAWARAN_TERKIRIM->value, $statuses);
        $this->assertContains(OrderStatus::DIBAYAR->value, $statuses);
        $this->assertContains(OrderStatus::SELESAI->value, $statuses);
        $this->assertContains(OrderStatus::DITOLAK->value, $statuses);

        foreach ($orders as $order) {
            $this->assertSame($batchId, $order->fresh()->demo_batch_id);
        }
    }

    public function test_every_seeded_order_uses_safe_contact_data(): void
    {
        $batchId = (string) Str::uuid();

        BookingOrderExampleData::seed($batchId);

        $this->assertDatabaseMissing('booking_drafts', [
            'customer_email' => null,
        ]);

        $drafts = \App\Domain\Booking\Models\BookingDraft::query()->where('demo_batch_id', $batchId)->get();
        foreach ($drafts as $draft) {
            $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', (string) $draft->customer_email);
            $this->assertMatchesRegularExpression('/^08118990\d{4}$/', (string) $draft->customer_mobile);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement `BookingOrderExampleData`**

Read `app/Domain/Booking/BookingServiceType.php`, `app/Domain/Booking/BookingRelationshipCode.php` (or wherever it lives — confirm exact class/values), `app/Domain/Booking/BookingContactChannel.php`, `app/Domain/Booking/BookingGender.php`, and `app/Domain/ServiceCatalog/ServiceCode::BASIC_CODES` for their real current values before writing the payload arrays — this plan's research did not enumerate every value of these smaller enums. Use a real, package-less, published, granular-or-aggregate demo cemetery in `LaunchCityCode::JAKARTA` (reuse `CemeteryExampleData`'s own seeded cemeteries rather than creating a new one — check its `slugs()`/`cemeteries()` output for one with no active packages first, matching the DISCOVERY payload requirement above).

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingPaymentMethod;
use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\Public\CemeteryPublicQuery;
use App\Domain\OrderWorkflow\Actions\CompleteOrder;
use App\Domain\OrderWorkflow\Actions\IssueOrderQuote;
use App\Domain\OrderWorkflow\Actions\ManualPaymentVerification;
use App\Domain\OrderWorkflow\Actions\MarkOrderPaid;
use App\Domain\OrderWorkflow\Actions\ProcessOrder;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Actions\SubmitBookingDraft;
use App\Domain\OrderWorkflow\Actions\VerifyOrder;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;
use Carbon\CarbonImmutable;

final class BookingOrderExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @return list<Order>
     */
    public static function seed(string $batchId): array
    {
        $cemetery = self::packagelessDemoCemetery();
        $orders = [];

        $orders[] = self::submittedOnly($cemetery, $batchId, 0);
        $orders[] = self::quoted($cemetery, $batchId, 1);
        $orders[] = self::paid($cemetery, $batchId, 2);
        $orders[] = self::completed($cemetery, $batchId, 3);
        $orders[] = self::rejected($cemetery, $batchId, 4);

        return $orders;
    }

    private static function packagelessDemoCemetery(): Cemetery
    {
        return Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private static function discoveryPayload(Cemetery $cemetery, int $index): array
    {
        return [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => array_map(
                static fn (string $code): array => ['code' => $code, 'quantity' => 1],
                ServiceCode::BASIC_CODES,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerAndDeceasedPayload(int $index): array
    {
        return [
            'customer_full_name' => DemoContactData::personName($index),
            'customer_mobile' => DemoContactData::phone($index),
            'customer_email' => DemoContactData::email($index),
            'customer_address' => 'Jl. Contoh Demo No. '.($index + 1),
            'customer_relationship' => 'ANAK',
            'customer_contact_channel' => 'WHATSAPP',
            'privacy_notice_accepted' => true,
            'deceased_full_name' => 'Almarhum Contoh '.($index + 1),
            'deceased_date_of_birth' => '1950-01-01',
            'deceased_date_of_death' => '2026-08-01',
            'deceased_relationship' => 'ORANG_TUA',
        ];
    }

    private static function draftThroughPayment(Cemetery $cemetery, int $index): \App\Domain\Booking\Models\BookingDraft
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::DISCOVERY,
            self::discoveryPayload($cemetery, $index),
            "demo-discovery-{$index}-{$draft->id}",
        );
        $draft = (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
            self::customerAndDeceasedPayload($index),
            "demo-customer-{$index}-{$draft->id}",
        );

        return (new SaveBookingDraftStep)(
            $draft,
            BookingWizardStep::PAYMENT,
            [
                'payment_method' => BookingPaymentMethod::MANUAL,
                'payment_reference' => 'DEMO-REF-'.($index + 1),
            ],
            "demo-payment-{$index}-{$draft->id}",
        );
    }

    private static function submittedOnly(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $draft = self::draftThroughPayment($cemetery, $index);
        TaggedAsDemoData::tag($draft, $batchId);

        $order = app(SubmitBookingDraft::class)($draft, "demo-submit-{$index}-{$draft->id}");
        TaggedAsDemoData::tag($order, $batchId);

        return $order;
    }

    private static function quoted(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::submittedOnly($cemetery, $batchId, $index);

        app(VerifyOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);
        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(7), self::ACTOR_REF, self::ACTOR_ROLE);

        return $order->fresh();
    }

    private static function paid(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::quoted($cemetery, $batchId, $index);

        app(ManualPaymentVerification::class)($order, self::ACTOR_REF, self::ACTOR_ROLE, 'Bukti transfer demo diverifikasi.');
        app(MarkOrderPaid::class)($order, self::ACTOR_REF, self::ACTOR_ROLE); // -> DIBAYAR

        return $order->fresh();
    }

    private static function completed(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::paid($cemetery, $batchId, $index);

        app(ProcessOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE); // -> DIPROSES
        app(CompleteOrder::class)($order->fresh(), self::ACTOR_REF, self::ACTOR_ROLE); // -> SELESAI

        return $order->fresh();
    }

    private static function rejected(Cemetery $cemetery, string $batchId, int $index): Order
    {
        $order = self::submittedOnly($cemetery, $batchId, $index);

        app(VerifyOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE);
        app(RejectOrder::class)($order, self::ACTOR_REF, self::ACTOR_ROLE, 'Data pemesan demo tidak lengkap.');

        return $order->fresh();
    }
}
```

- [ ] **Step 4: Run to verify tests pass**

Expected: PASS, both tests.

- [ ] **Step 5: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/BookingOrderExampleData.php tests/Feature/Support/ExampleData/BookingOrderExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ExampleData/BookingOrderExampleData.php tests/Feature/Support/ExampleData/BookingOrderExampleDataTest.php
git commit -m "feat(demo-data): add booking order generator across 4 states"
```

---

## Task 6: `RenewalExampleData`

**Files:**
- Create: `app/Support/ExampleData/RenewalExampleData.php`
- Test: `tests/Feature/Support/ExampleData/RenewalExampleDataTest.php`

**Interfaces:**
- Consumes: `TaggedAsDemoData` (Task 1). Needs at least one real `GraveRecord` with a non-null `due_date` and a fully-priced cemetery (`price_min`/`price_source`/`price_effective_at` all non-null) — reuse `CemeteryExampleData`'s own seeded grave records if one qualifies; if none does, this task must create one directly (grave records have no dedicated "create" Action in this codebase per this session's broader familiarity with the domain — confirm this at implementation time before assuming, since it wasn't explicitly checked during this plan's research).
- Produces: `RenewalExampleData::seed(string $batchId): list<Renewal>`.

**Real signatures this task uses (verified during this plan's research):**
- `OpenRenewal::__invoke(GraveRecord $grave): Renewal` — internally calls `QuoteRenewal`, writes `renewals` + `renewal_quotes` in one transaction. Throws `DuplicateRenewalPeriodException` on a `(grave_record_id, target_due_period)` collision — use distinct grave records per demo renewal, not the same one twice.
- `MarkRenewalPaidExternally::__invoke(Renewal $renewal, string $evidence, string $reason, string $actorRef, string $actorRole): void` — transitions MENUNGGU_PEMBAYARAN→DIBAYAR, `$reason` non-blank.
- `ExpireRenewal::__invoke(Renewal $renewal, string $actorRef, string $actorRole, ?string $reason = null): void`.
- `RenewalJourneyStep`: `SEARCH=1, FEE_AND_PAYMENT=2, CONFIRMATION=3` — not directly used by this generator (renewals are opened server-side via `OpenRenewal`, not through the wizard's own step-save actions), included here only as confirmed-current context.

**Demo variety (3 renewals):**
1. **Menunggu pembayaran** — `OpenRenewal` only.
2. **Dibayar** — `OpenRenewal` → `MarkRenewalPaidExternally`.
3. **Kedaluwarsa** — `OpenRenewal` → `ExpireRenewal`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Support\ExampleData\RenewalExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RenewalExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_renewals_across_three_states(): void
    {
        $batchId = (string) Str::uuid();

        $renewals = RenewalExampleData::seed($batchId);

        $statuses = array_map(static fn ($renewal): string => $renewal->status, $renewals);

        $this->assertContains('MENUNGGU_PEMBAYARAN', $statuses);
        $this->assertContains('DIBAYAR', $statuses);
        $this->assertContains('KEDALUWARSA', $statuses);

        foreach ($renewals as $renewal) {
            $this->assertSame($batchId, $renewal->fresh()->demo_batch_id);
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Confirm real, currently-existing, fully-priced grave records with due dates**

Before implementing, query the disposable test database directly (`php artisan tinker` inside the pinned image, or a quick throwaway test) after running `CemeteryExampleDataSeeder` to confirm at least 3 distinct `grave_records` rows exist with non-null `due_date` and a fully-priced parent cemetery. If fewer than 3 qualify, this generator needs its own small grave-record creation step first — read how `CemeteryExampleData::graveRecords()` builds its rows (already read in full during Task-independent research for this plan's spec) and mirror that shape for any additional demo-specific grave records needed, tagging each with `TaggedAsDemoData` too.

- [ ] **Step 4: Implement `RenewalExampleData`**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CemeteryDirectory\Models\GraveRecord;
use App\Domain\Renewal\Actions\ExpireRenewal;
use App\Domain\Renewal\Actions\MarkRenewalPaidExternally;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

final class RenewalExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'system';

    /**
     * @param  list<GraveRecord>  $graveRecords  three distinct, already-qualifying grave records
     * @return list<Renewal>
     */
    public static function seed(string $batchId, array $graveRecords): array
    {
        [$pending, $paid, $expired] = $graveRecords;

        $renewals = [];

        $renewal = (new OpenRenewal)($pending);
        TaggedAsDemoData::tag($renewal, $batchId);
        $renewals[] = $renewal;

        $renewal = (new OpenRenewal)($paid);
        TaggedAsDemoData::tag($renewal, $batchId);
        (new MarkRenewalPaidExternally)(
            $renewal,
            evidence: 'DEMO-BUKTI-TRANSFER-001',
            reason: 'Pembayaran perpanjangan demo diverifikasi manual.',
            actorRef: self::ACTOR_REF,
            actorRole: self::ACTOR_ROLE,
        );
        $renewals[] = $renewal->fresh();

        $renewal = (new OpenRenewal)($expired);
        TaggedAsDemoData::tag($renewal, $batchId);
        (new ExpireRenewal)($renewal, self::ACTOR_REF, self::ACTOR_ROLE, 'Batas waktu pembayaran demo terlewati.');
        $renewals[] = $renewal->fresh();

        return $renewals;
    }
}
```

**Note the changed signature from the spec's own high-level sketch:** this generator takes `list<GraveRecord> $graveRecords` as a second parameter rather than resolving its own — Task 10's orchestration command is responsible for finding/creating three qualifying grave records (per Step 3 above) and passing them in, keeping this generator itself simple and directly testable without duplicating grave-record-qualification logic.

- [ ] **Step 5: Update the test to pass real qualifying grave records, then run to verify it passes**

Adjust the test's `RenewalExampleData::seed($batchId)` call to `RenewalExampleData::seed($batchId, $graveRecords)` with three real, qualifying `GraveRecord` rows built in the test's own setup (mirroring whatever Step 3 found/confirmed), matching this generator's real signature.

Expected: PASS.

- [ ] **Step 6: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/RenewalExampleData.php tests/Feature/Support/ExampleData/RenewalExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add app/Support/ExampleData/RenewalExampleData.php tests/Feature/Support/ExampleData/RenewalExampleDataTest.php
git commit -m "feat(demo-data): add renewal generator across 3 states"
```

---

## Task 7: `MarketplaceOrderExampleData`

**Files:**
- Create: `app/Support/ExampleData/MarketplaceOrderExampleData.php`
- Test: `tests/Feature/Support/ExampleData/MarketplaceOrderExampleDataTest.php`

**Interfaces:**
- Consumes: `Vendor $vendor` from Task 4's `VendorAccountExampleData::seed()['vendors'][0]`, `TaggedAsDemoData` (Task 1). Needs a real `VendorListing` under that vendor and a real `ServiceArea` — reuse `VendorListingExampleData`'s own seeded listings if the chosen demo vendor has one, or create a small demo-specific listing directly under the Task-4 vendor (no dedicated "create listing" domain Action was confirmed during this plan's research — check for one before assuming direct creation is needed, since `app/Filament/Vendor/Resources/VendorListings/Pages/CreateVendorListing.php` existing as a plain Filament page, like `CreateVendor`, would be the same kind of confirmed exception).
- Produces: `MarketplaceOrderExampleData::seed(string $batchId, Vendor $vendor): list<MarketplaceOrder>`.

**Real signatures this task uses (verified during this plan's research — note the `handle()` vs `__invoke()` split):**
- `AddToCart::handle(Cart $cart, VendorListing $listing, int $quantity, ?int $variantId = null): CartConflict|CartItem`
- `PlaceMarketplaceOrder::handle(Cart $cart, string $customerRef, ServiceArea $area, string $idempotencyKey, string $recipientName, string $recipientPhone, string $recipientEmail, ?string $scheduledFor = null, ?CarbonImmutable $now = null): MarketplaceOrder`
- `UpdateVendorOrderStatus::__invoke(VendorOrder $order, string $status, int|string|null $actorReference = null, string $actorRole = 'vendor', AuditSource $auditSource = AuditSource::Panel, ?string $notes = null): VendorOrder`
- `MarkMarketplaceOrderPaid::__invoke(MarketplaceOrder $order, int $amountMinor, bool $fulfilmentEvidenceAccepted = false, ?CarbonImmutable $disputeWindowEndsAt = null, ?string $actorRef = null, string $actorRole = 'system', ?string $correlationId = null, AuditSource $source = AuditSource::Job, ?CarbonImmutable $now = null): MarketplaceOrder`
- Cart resolution — no dedicated Action, matches this codebase's own real caller pattern: `Cart::query()->firstOrCreate(['customer_ref' => $ref, 'session_ref' => null])`.
- `vendor_orders.id` is a **bigint PK**, unlike almost every other table in this schema (uuid everywhere else) — note this explicitly when tagging with `TaggedAsDemoData` (the helper itself is PK-type-agnostic since it just calls `->save()`, but do not assume `$vendorOrder->id` is a uuid string anywhere else in this generator).

**Demo variety (2 marketplace orders):**
1. **Placed, unpaid** — `AddToCart` → `PlaceMarketplaceOrder`.
2. **Paid, vendor processing** — `AddToCart` → `PlaceMarketplaceOrder` → `MarkMarketplaceOrderPaid` → `UpdateVendorOrderStatus` (to whatever `VendorProcessingStatus` value represents "in progress" — confirm the real enum values by reading `App\Domain\Marketplace\VendorProcessingStatus` before finalizing, this plan's research did not enumerate them).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Support\ExampleData\MarketplaceOrderExampleData;
use App\Support\ExampleData\VendorAccountExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MarketplaceOrderExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_marketplace_orders_for_the_given_vendor(): void
    {
        $batchId = (string) Str::uuid();
        $vendor = VendorAccountExampleData::seed($batchId)['vendors'][0];

        $orders = MarketplaceOrderExampleData::seed($batchId, $vendor);

        $this->assertNotEmpty($orders);
        foreach ($orders as $order) {
            $this->assertSame($vendor->id, $order->vendor_id);
            $this->assertSame($batchId, $order->fresh()->demo_batch_id);
            $this->assertMatchesRegularExpression('/@example\.(com|org|net)$/', $order->recipient_email ?? '');
        }
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Confirm the real `VendorListing`/`ServiceArea`/`VendorProcessingStatus` shapes before implementing**

Read `App\Domain\Marketplace\Models\VendorListing`, `App\Domain\Marketplace\Models\ServiceArea`, and `App\Domain\Marketplace\VendorProcessingStatus` (its real case list) — none were read in full during this plan's research. Confirm whether the Task-4 demo vendor needs its own demo `VendorListing`/`ServiceArea` created here, or whether `VendorListingExampleData`'s existing seeded listings can be reused by looking up one belonging to the Task-4 vendor's name pattern (unlikely, since Task 4 creates a NEW vendor rather than reusing `VendorListingExampleData`'s existing ones — most likely this generator needs to create one small demo listing + service area directly, following whatever the real `VendorListing`/`ServiceArea` creation pattern is, Action-based if one exists or direct-model if confirmed as another exception).

- [ ] **Step 4: Implement `MarketplaceOrderExampleData`**

Write the implementation using the confirmed real signatures above and whatever Step 3 established for listing/service-area setup. Follow the exact shape of Task 5/6's generators (private per-state helper methods, `TaggedAsDemoData::tag()` after every write, `DemoContactData` for every contact field). Because this task's exact listing/service-area creation path depends on Step 3's findings (not fully resolved during this plan's own research), the implementer has latitude here — but must still follow every Global Constraint (real Actions where they exist, tagged writes, safe contact data, determinism) exactly.

- [ ] **Step 5: Run to verify the test passes**

Expected: PASS.

- [ ] **Step 6: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/MarketplaceOrderExampleData.php tests/Feature/Support/ExampleData/MarketplaceOrderExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add app/Support/ExampleData/MarketplaceOrderExampleData.php tests/Feature/Support/ExampleData/MarketplaceOrderExampleDataTest.php
git commit -m "feat(demo-data): add marketplace order generator across 2 states"
```

---

## Task 8: `CareSubscriptionExampleData`

**Files:**
- Create: `app/Support/ExampleData/CareSubscriptionExampleData.php`
- Test: `tests/Feature/Support/ExampleData/CareSubscriptionExampleDataTest.php`

**Interfaces:**
- Consumes: `TaggedAsDemoData` (Task 1), `User $customer` (a real seeded user id — reuse one of Task 4's vendor-account users, or create one directly here since `customer_id` throughout this domain is a bigint `users.id`, never a uuid), a real `grave_id` (a `grave_records.id` uuid, reuse from `CemeteryExampleData`'s seeded rows or Task 6's qualifying grave records).
- Produces: `CareSubscriptionExampleData::seed(string $batchId, int $customerId, string $graveId): list<Subscription>`.

**Real signatures and call order this task uses (verified during this plan's research — this is the densest chain in the whole subsystem):**

```
CreateCarePlan → CreateSubscription(carePlan, grave, customer) [auto-creates cycle #1, SCHEDULED]
  → MarkCyclePaid(cycle, amountMinor=invoice.amount_minor, ...) [cycle→PAID, subscription DRAFT→ACTIVE]
  → CreateWorkOrderFromCycle(cycle, carePlan)
  → AssignWorkOrder(workOrder, vendorId, ...) [requires status===Pending first]
  → CompleteTask(task) [once per work_order_task]
  → AcceptService(workOrder, customerId, rating, notes)
```

- `CreateCarePlan::__invoke(string $name, string $productCode, CarePlanFrequency $frequency, int $priceMinor, string $currency = 'IDR', ?string $description = null, ?string $vendorId = null, ?array $checklistTemplate = null, string $actorRef = 'system', string $actorRole = 'admin', AuditSource $auditSource = AuditSource::Panel): CarePlan` — `$checklistTemplate` MUST be a real, non-empty array (`[['name' => ..., 'required_evidence' => bool], ...]`) or `CreateWorkOrderFromCycle` produces zero tasks, nothing to demo.
- `CreateSubscription::__invoke(CarePlan $carePlan, string $graveId, int $customerId, CarePlanFrequency $frequency, string $actorReference, string $actorRole): Subscription`
- `MarkCyclePaid::__invoke(SubscriptionCycle $cycle, int $amountMinor, string $paidSourceRef, string $actorReference): SubscriptionCycle` — `$amountMinor` MUST exactly equal `$cycle->invoice->amount_minor` (read it off the real created invoice, never hardcode a guessed price, or `CyclePaymentAmountMismatchException` throws).
- `CreateWorkOrderFromCycle::__invoke(SubscriptionCycle $cycle, CarePlan $carePlan, bool $forceNew = false): WorkOrder`
- `AssignWorkOrder::__invoke(WorkOrder $workOrder, string $vendorId, string $actorReference): WorkOrder`
- `CompleteTask::__invoke(WorkOrderTask $task, string $actorRef = 'system', string $actorRole = 'vendor', AuditSource $auditSource = AuditSource::Panel): WorkOrderTask`
- `AcceptService::__invoke(WorkOrder $workOrder, int $customerId, ?int $rating, ?string $notes): ServiceAcceptance` — `$rating` 1–5 or null.
- `FileComplaint::__invoke(WorkOrder $workOrder, int $customerId, string $complaintText): ServiceComplaint` — used for the third demo work order (below), an independent alternate outcome, not chained after `AcceptService`.

**Confirmed real gap, resolved here rather than left as a placeholder:** nothing among the real Actions above (or anywhere else in `app/`, confirmed by grepping for every write of `WorkOrderStatus::Completed`) ever transitions a `WorkOrder`'s own `status` column to `Completed` — `AcceptService`'s doc block literally assumes a "completed work order" as a precondition it never itself creates. This is a genuine, pre-existing gap in the application's own domain modeling, not something this task invents — **flag it explicitly in this task's final report as a finding for the broader UAT sweep** (a `WorkOrder` may never visibly read as "Selesai" anywhere in the app today, only in this generator's own demo data). For this generator's own purposes, resolve it the same way Task 4 resolves the vendor-account gap: a narrow, explicitly-commented, direct write —

```php
// Confirmed gap (2026-09-03 plan research): no real Action transitions
// WorkOrder.status to Completed anywhere in this codebase — AcceptService's
// own doc block assumes it as a precondition it never creates. Narrow,
// explicit exception to "always use real Actions", matching this same
// generator's need for a work order that actually reads as finished in a
// demo. Flagged separately as a real product gap, not silently normalized.
$workOrder->forceFill(['status' => \App\Domain\VendorFulfillment\WorkOrderStatus::Completed->value])->save();
```
— placed after every task on that work order is completed via `CompleteTask`, and before `AcceptService` is called on it.

**Confirmed real scope decision, made during this plan's research, not left ambiguous:** `UploadEvidence` is deliberately **excluded** from this generator. It hard-requires a real `documents` row with `state === DocumentState::Accepted`, which in turn requires driving DocumentVault's real upload+scan pipeline — and `config('document-vault.malware_scanner')` resolves to `null` outside the `development` environment (confirmed by reading `config/document-vault.php` earlier this session), meaning there is currently no way to produce a real Accepted document outside `development` at all without either bypassing a real security control or depending on a pipeline that is not actually functional on beta today. Faking an Accepted `documents` row via direct insert would misrepresent that a real scan happened — this generator does not do that. **This is a real, separate platform finding worth surfacing prominently: certificate/evidence document uploads may not work AT ALL on the beta host today outside development, independent of any seed data.** Report this explicitly; it belongs to the broader UAT sweep, not a silent workaround here.

**Demo variety (3 subscriptions, one care plan reused across all three... or one plan + one alternate — implementer's call, keep to the spec's 2–3-per-state guidance):**
1. **Active, one accepted service** — the full chain above through `AcceptService`.
2. **Active, one filed complaint** — same chain through `CreateWorkOrderFromCycle`/`AssignWorkOrder`/`CompleteTask`, then `FileComplaint` instead of `AcceptService` (CARE-SUB-06's real, tested complaint path — worth demonstrating, per this session's own earlier release-gates.md research).
3. **Draft/unpaid** — `CreateCarePlan` → `CreateSubscription` only, stopping before `MarkCyclePaid`, showing the "just signed up" state.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Support\ExampleData\CareSubscriptionExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CareSubscriptionExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_subscriptions_across_three_states(): void
    {
        $batchId = (string) Str::uuid();
        $customer = \App\Models\User::query()->create([
            'name' => 'Contoh Pelanggan',
            'email' => 'demo.customer.care@example.com',
            'password' => \Illuminate\Support\Facades\Hash::make('DemoContoh2026!'),
        ]);
        $grave = \App\Domain\CemeteryDirectory\Models\GraveRecord::query()->firstOrFail();

        $subscriptions = CareSubscriptionExampleData::seed($batchId, $customer->id, $grave->id);

        $this->assertCount(3, $subscriptions);
        foreach ($subscriptions as $subscription) {
            $this->assertSame($batchId, $subscription->fresh()->demo_batch_id);
        }

        $this->assertDatabaseHas('service_acceptances', ['customer_id' => $customer->id]);
        $this->assertDatabaseHas('service_complaints', ['customer_id' => $customer->id]);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 3: Implement `CareSubscriptionExampleData`**

Follow the confirmed call order, signatures, and the two resolved gaps above exactly. Use a real demo vendor id for `AssignWorkOrder` (Task 4's `VendorAccountExampleData` vendor, or `CreateCarePlan`'s own `$vendorId` param). Tag every write with `TaggedAsDemoData`.

- [ ] **Step 4: Run to verify the test passes**

Expected: PASS.

- [ ] **Step 5: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/CareSubscriptionExampleData.php tests/Feature/Support/ExampleData/CareSubscriptionExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add app/Support/ExampleData/CareSubscriptionExampleData.php tests/Feature/Support/ExampleData/CareSubscriptionExampleDataTest.php
git commit -m "feat(demo-data): add care subscription generator across 3 states, evidence upload excluded (see doc block)"
```

---

## Task 9: `CertificateExampleData` + `VisitationExampleData`

**Scope correction from the spec, made during this plan's own research (read before implementing):** the spec named this generator `CertificateAndAgreementExampleData`, covering both. During this plan's research, `AgreementType` (the closed set `agreements.type` may hold) was read in full and confirmed to have **exactly one case**: `PreNeedAgreement`, whose eligibility is a *settled* `PreNeedCase` — reached only through a distinct 7-8-action lifecycle (`RegisterPreNeedInterest → ProposePreNeedPackage → [ReservePreNeedPlot] → QuotePreNeed → AcceptPreNeedAgreement → SchedulePreNeedPayments → SettlePreNeed → ActivatePreNeed`, per `PreNeedCaseStatus::allowedNext()`), none of whose real signatures this plan's research covers. Task 5's booking-order generator only ever produces `AT_NEED_SERVICE_ORDER` orders (`BookingServiceType::NEW_GRAVE`) — a real `PreNeedCase` needs `BookingServiceType::PRE_NEED` instead, a whole separate chain this plan does not otherwise touch.

Rather than hand the implementer an unverified 8-action chain (the same category of risk this plan's own research process exists to catch — see the `MarkOrderPaid`→`SELESAI` gap this same research found and fixed a few tasks up), **agreement seeding is out of scope for this plan.** This generator is renamed `CertificateExampleData` and seeds certificates only. This is a disclosed, deliberate limitation — Task 12's documentation records it explicitly — not a silent gap, and a real, narrower follow-up plan can add pre-need agreement seeding later if the demo specifically needs to show that journey.

**Files:**
- Create: `app/Support/ExampleData/CertificateExampleData.php`
- Create: `app/Support/ExampleData/VisitationExampleData.php`
- Test: `tests/Feature/Support/ExampleData/CertificateExampleDataTest.php`
- Test: `tests/Feature/Support/ExampleData/VisitationExampleDataTest.php`

**Interfaces:**
- Consumes: `TaggedAsDemoData` (Task 1). `CertificateExampleData` needs Task 5's `DIBAYAR`-status order specifically (index `[2]` of `BookingOrderExampleData::seed()`'s return — see Task 5's own updated Interfaces note). `VisitationExampleData` needs a real `Cemetery`.
- Produces: `CertificateExampleData::seed(string $batchId, Order $dibayarOrder): list<Certificate>`, `VisitationExampleData::seed(string $batchId, Cemetery $cemetery): list<VisitationBooking>`.

**Real signatures this task uses (verified during this plan's research; note the corrected namespace):**
- Certificate Actions live under **`App\Domain\AgreementCertificate\Actions`** (the spec's own guess of a separate `Certificate` namespace was wrong — corrected here as an implementation-level precision detail).
- `IssueCertificate::__invoke(CertificateType $type, Model $subject, int|string $issuerReference, string $issuerRole, ?string $documentId, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel, ?string $reference = null): Certificate` — pass `$documentId = null` (skips the DocumentVault Accepted-document check entirely — deliberately, per the same reasoning Task 8 documents for `UploadEvidence`; unlike `UploadEvidence`, `IssueCertificate` has an explicit null-skip path, confirmed by reading its signature directly, so this is not the same kind of unresolved gap). `$type = CertificateType::OrderSettlement` (the only case with an `Order` subject — confirmed by reading `CertificateEligibilityPolicy` directly: `$subject instanceof Order && $subject->status() === OrderStatus::DIBAYAR`, exactly, not `SELESAI` — this is why Task 5 now produces a dedicated `DIBAYAR` order). `$issuerRole` must be `ActorRole::ADMIN` or `ActorRole::RESTRICTED_ADMIN` (confirmed by reading `CertificateIssuerAuthorizer::assertCanIssue()` directly — any other role throws before anything is written).
- `RevokeCertificate::__invoke(Certificate $certificate, int|string $actorReference, string $actorRole, string $reason, AuditSource $auditSource = AuditSource::Panel): Certificate` — `$reason` non-blank, certificate must currently be `Issued`.
- `RequestVisitation::__invoke(Cemetery $cemetery, string $visitDate, int $visitorCount, string $contactPhone, ?string $contactEmail, ?string $accessibilityNeeds, array $facilityRequests, string $idempotencyKey, int|string $actorReference, string $actorRole = 'customer', AuditSource $auditSource = AuditSource::Api): VisitationBooking` — **throws immediately unless a `CemeteryVisitationPolicy` row already exists for `$cemetery`.**
- `ChangeVisitationBookingStatus::__invoke(VisitationBooking $booking, string $to, int|string $actorReference, string $actorRole, ?string $reason = null, AuditSource $auditSource = AuditSource::Panel): VisitationBooking` — transition matrix: `requested→confirmed`, `requested→cancelled`, `confirmed→cancelled`, `requested→no_show`; anything else throws.

**Confirmed real gap, resolved here:** no dedicated Action creates a `CemeteryVisitationPolicy` row anywhere in this codebase (confirmed by reading the model directly — `$fillable = ['cemetery_id', 'operating_hours', 'daily_capacity']`, no companion Action file exists). Direct model creation here is the same kind of confirmed exception as `Vendor`/`VendorUser`/`User` in Task 4 — not a plan defect.

**Demo variety:**
- Certificates: 1 issued + 1 issued-then-revoked (2 records total, both against the SAME `DIBAYAR` order — `CertificateType`/subject uniqueness was not confirmed as constrained during this plan's research, and showing the audit trail a revoke leaves is the point of the second one).
- Visitation: 3 bookings — `requested`, `confirmed`, `cancelled` (via `ChangeVisitationBookingStatus` from `confirmed`, showing both transition arms are real).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Support\ExampleData\BookingOrderExampleData;
use App\Support\ExampleData\CertificateExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CertificateExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_an_issued_and_a_revoked_certificate(): void
    {
        $batchId = (string) Str::uuid();
        $order = BookingOrderExampleData::seed($batchId)[2]; // the DIBAYAR one
        $this->assertSame(OrderStatus::DIBAYAR->value, $order->status()->value);

        $certificates = CertificateExampleData::seed($batchId, $order);

        $this->assertCount(2, $certificates);
        $this->assertSame('issued', $certificates[0]->fresh()->status);
        $this->assertSame('revoked', $certificates[1]->fresh()->status);
        foreach ($certificates as $certificate) {
            $this->assertSame($batchId, $certificate->fresh()->demo_batch_id);
        }
    }
}
```

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\VisitationExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class VisitationExampleDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_bookings_across_three_statuses(): void
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'TPU Contoh Kunjungan',
            'slug' => 'tpu-contoh-kunjungan-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
        $batchId = (string) Str::uuid();

        $bookings = VisitationExampleData::seed($batchId, $cemetery);

        $statuses = array_map(static fn ($booking): string => $booking->status, $bookings);
        $this->assertContains('requested', $statuses);
        $this->assertContains('confirmed', $statuses);
        $this->assertContains('cancelled', $statuses);

        foreach ($bookings as $booking) {
            $this->assertSame($batchId, $booking->fresh()->demo_batch_id);
        }
    }
}
```

- [ ] **Step 2: Run to verify both fail**

Expected: FAIL — classes do not exist.

- [ ] **Step 3: Implement `CertificateExampleData`**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\AgreementCertificate\Actions\IssueCertificate;
use App\Domain\AgreementCertificate\Actions\RevokeCertificate;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Certificate;
use App\Domain\OrderWorkflow\Models\Order;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

final class CertificateExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    /**
     * @param  Order  $dibayarOrder  MUST be at exactly OrderStatus::DIBAYAR —
     *                                CertificateEligibilityPolicy's OrderSettlement
     *                                rule requires it, confirmed by reading the
     *                                policy class directly.
     * @return list<Certificate>
     */
    public static function seed(string $batchId, Order $dibayarOrder): array
    {
        $issued = (new IssueCertificate)(
            CertificateType::OrderSettlement,
            $dibayarOrder,
            self::ACTOR_REF,
            ActorRole::ADMIN,
            documentId: null,
        );
        TaggedAsDemoData::tag($issued, $batchId);

        $revoked = (new IssueCertificate)(
            CertificateType::OrderSettlement,
            $dibayarOrder,
            self::ACTOR_REF,
            ActorRole::ADMIN,
            documentId: null,
        );
        TaggedAsDemoData::tag($revoked, $batchId);
        (new RevokeCertificate)($revoked, self::ACTOR_REF, ActorRole::ADMIN, 'Sertifikat demo diganti dengan versi terbaru.');

        return [$issued->fresh(), $revoked->fresh()];
    }
}
```

- [ ] **Step 4: Implement `VisitationExampleData`**

```php
<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\ChangeVisitationBookingStatus;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

final class VisitationExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'customer';

    /**
     * @return list<VisitationBooking>
     */
    public static function seed(string $batchId, Cemetery $cemetery): array
    {
        $policy = CemeteryVisitationPolicy::query()->firstOrCreate(
            ['cemetery_id' => $cemetery->id],
            [
                'operating_hours' => [
                    'mon' => ['open' => '08:00', 'close' => '17:00'],
                    'tue' => ['open' => '08:00', 'close' => '17:00'],
                    'wed' => ['open' => '08:00', 'close' => '17:00'],
                    'thu' => ['open' => '08:00', 'close' => '17:00'],
                    'fri' => ['open' => '08:00', 'close' => '17:00'],
                    'sat' => ['open' => '08:00', 'close' => '15:00'],
                    'sun' => ['open' => '08:00', 'close' => '15:00'],
                ],
                'daily_capacity' => 50,
            ],
        );
        TaggedAsDemoData::tag($policy, $batchId);

        $bookings = [];

        foreach (range(0, 2) as $index) {
            $booking = (new RequestVisitation)(
                $cemetery,
                visitDate: now()->addDays($index + 3)->toDateString(),
                visitorCount: 2,
                contactPhone: DemoContactData::phone($index + 200),
                contactEmail: DemoContactData::email($index + 200),
                accessibilityNeeds: null,
                facilityRequests: [],
                idempotencyKey: "demo-visitation-{$batchId}-{$index}",
                actorReference: self::ACTOR_REF,
                actorRole: self::ACTOR_ROLE,
            );
            TaggedAsDemoData::tag($booking, $batchId);
            $bookings[] = $booking;
        }

        (new ChangeVisitationBookingStatus)($bookings[1], 'confirmed', self::ACTOR_REF, 'admin');
        $bookings[1] = $bookings[1]->fresh();

        (new ChangeVisitationBookingStatus)($bookings[2], 'confirmed', self::ACTOR_REF, 'admin');
        (new ChangeVisitationBookingStatus)($bookings[2]->fresh(), 'cancelled', self::ACTOR_REF, self::ACTOR_ROLE, 'Rencana kunjungan demo dibatalkan.');
        $bookings[2] = $bookings[2]->fresh();

        return $bookings;
    }
}
```

- [ ] **Step 5: Run to verify both `CertificateExampleDataTest` and `VisitationExampleDataTest` pass**

Expected: both PASS.

- [ ] **Step 6: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Support/ExampleData/CertificateExampleData.php app/Support/ExampleData/VisitationExampleData.php tests/Feature/Support/ExampleData/CertificateExampleDataTest.php tests/Feature/Support/ExampleData/VisitationExampleDataTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add app/Support/ExampleData/CertificateExampleData.php app/Support/ExampleData/VisitationExampleData.php tests/Feature/Support/ExampleData/CertificateExampleDataTest.php tests/Feature/Support/ExampleData/VisitationExampleDataTest.php
git commit -m "feat(demo-data): add certificate and visitation generators (agreement seeding deliberately out of scope, see doc block)"
```

---

## Task 10: `demo-data:seed` orchestration command

**Files:**
- Create: `app/Console/Commands/DemoDataSeedCommand.php`
- Test: `tests/Feature/Console/DemoDataSeedCommandTest.php`

**Interfaces:**
- Consumes: every generator from Tasks 4–9, `DemoDataSuppression::run()` (Task 3), the `demo_data_batches` table (Task 1).
- Produces: a `demo-data:seed` artisan command; writes one `demo_data_batches` row per run.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DemoDataSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_every_domain_and_records_a_batch(): void
    {
        $this->artisan('demo-data:seed')->assertSuccessful();

        $this->assertDatabaseCount('demo_data_batches', 1);

        $batchId = \App\Models\DemoDataBatch::query()->value('batch_id');

        $this->assertGreaterThan(0, \App\Domain\OrderWorkflow\Models\Order::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, \App\Domain\Renewal\Models\Renewal::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, \App\Domain\Marketplace\Models\Vendor::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, \App\Domain\CareSubscription\Models\Subscription::query()->where('demo_batch_id', $batchId)->count());
        $this->assertGreaterThan(0, \App\Domain\Visitation\Models\VisitationBooking::query()->where('demo_batch_id', $batchId)->count());
    }

    /**
     * A bare `Queue::fake()` also fakes `PublishOutboxEventJob` — the job
     * that actually fires `OutboxEventPublished` in the first place (see
     * Task 3's own real finding, and `handle()`'s doc comment on why the
     * command forces `queue.default` to `sync` and drains the outbox
     * itself). Faking it would mean nothing ever reaches the two
     * suppression-guarded listeners at all, and this test would pass for
     * the wrong reason — proving nothing ran, not that suppression
     * worked. Scope the fake to the one job that must never be queued.
     */
    public function test_seeding_never_queues_a_real_notification_job(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob::class]);

        $this->artisan('demo-data:seed')->assertSuccessful();

        \Illuminate\Support\Facades\Queue::assertNotPushed(\App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob::class);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Expected: FAIL — command and `App\Models\DemoDataBatch` do not exist yet.

- [ ] **Step 3: Add the `DemoDataBatch` Eloquent model** (small, needed for the test above and Task 11)

```php
<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class DemoDataBatch extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $table = 'demo_data_batches';

    protected $fillable = ['id', 'batch_id', 'summary', 'created_at'];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
```

- [ ] **Step 4: Implement `demo-data:seed`**

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\CemeteryDirectory\Models\GraveRecord;
use App\Models\DemoDataBatch;
use App\Models\User;
use App\Platform\Notification\DemoDataSuppression;
use App\Support\ExampleData\BookingOrderExampleData;
use App\Support\ExampleData\CareSubscriptionExampleData;
use App\Support\ExampleData\CemeteryOperatorExampleData;
use App\Support\ExampleData\CertificateExampleData;
use App\Support\ExampleData\MarketplaceOrderExampleData;
use App\Support\ExampleData\RenewalExampleData;
use App\Support\ExampleData\VendorAccountExampleData;
use App\Support\ExampleData\VisitationExampleData;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates every demo generator (Tasks 4-9) in dependency order, inside
 * one call to `DemoDataSuppression::run()`, with `queue.default` forced to
 * `sync` and the outbox drained in-process before the run ends — see the
 * comment above `handle()`'s body for why all three are necessary together
 * (a real finding from Task 3's own implementation: a domain write alone
 * never synchronously fires `OutboxEventPublished`, so without the
 * sync-forcing and the drain, the suppression guard is correctly placed
 * but unreachable on a real host). Each domain runs in its own DB::transaction()
 * so one domain's failure never corrupts an earlier domain's already-
 * committed data — see docs/superpowers/specs/2026-09-03-demo-seed-data-design.md
 * §Error handling.
 *
 * NEVER run this against anything but a disposable local/test database.
 * This command has no way to positively verify the database it's pointed
 * at is safe — that responsibility belongs to whoever invokes it, per this
 * plan's own Global Constraints and the spec's decision 5 (running this
 * against the live beta host is a separate, explicitly-confirmed action,
 * never bundled with merging the PR that adds this command).
 */
final class DemoDataSeedCommand extends Command
{
    protected $signature = 'demo-data:seed';

    protected $description = 'Seed realistic, safely-tagged demo data across every major journey for a live demo.';

    public function handle(): int
    {
        $batchId = (string) Str::uuid();
        $summary = [];

        // Real finding from Task 3's implementer, confirmed independently
        // (2026_09_03): a domain write does NOT synchronously fire
        // OutboxEventPublished. It only writes an outbox_events row;
        // `PublishOutboxEventJob` — the thing that actually fires that
        // event — is itself `ShouldQueue`, dispatched only by
        // `OutboxPublisher::publishBatch()`, whose only real caller is the
        // `outbox:publish` scheduled command running EVERY MINUTE IN A
        // SEPARATE PROCESS. On beta, `QUEUE_CONNECTION=database` (real,
        // async) — so even calling `publishBatch()` from inside this
        // command would just enqueue the job for a separate `queue:work`
        // worker to pick up later, in a process where
        // `DemoDataSuppression::active()` has already gone back to false.
        // Task 3's guard is correctly placed, but nothing reaches it at
        // all on beta without this fix: force the queue driver to `sync`
        // for the whole run (so every job this run dispatches — including
        // PublishOutboxEventJob, ConsumeOutboxNotificationJob if the guard
        // were ever bypassed, and anything else in the chain — executes
        // immediately, in-process, in the SAME process the suppression
        // flag is true in), and drain the outbox ourselves rather than
        // waiting for the scheduler.
        $originalQueueDriver = config('queue.default');
        config(['queue.default' => 'sync']);

        try {
            DemoDataSuppression::run(function () use ($batchId, &$summary): void {
            $summary['vendor_accounts'] = $this->runDomain('vendor accounts', function () use ($batchId) {
                return VendorAccountExampleData::seed($batchId);
            });
            $vendor = $summary['vendor_accounts']['vendors'][0];

            $cemetery = Cemetery::query()->firstOrFail();

            $summary['cemetery_operator'] = $this->runDomain('cemetery operator', function () use ($batchId, $cemetery) {
                return CemeteryOperatorExampleData::seed($batchId, $cemetery->id);
            });

            $summary['booking_orders'] = $this->runDomain('booking orders', function () use ($batchId) {
                return BookingOrderExampleData::seed($batchId);
            });

            $graveRecords = GraveRecord::query()->whereNotNull('due_date')->limit(3)->get()->all();
            $summary['renewals'] = $this->runDomain('renewals', function () use ($batchId, $graveRecords) {
                return RenewalExampleData::seed($batchId, $graveRecords);
            });

            $summary['marketplace_orders'] = $this->runDomain('marketplace orders', function () use ($batchId, $vendor) {
                return MarketplaceOrderExampleData::seed($batchId, $vendor);
            });

            // A DEDICATED customer user, not an arbitrary demo_batch_id-tagged
            // row — by this point Task 4 has already tagged 3 users (2 vendor
            // accounts + 1 cemetery operator) with this same batch id, so
            // `User::where('demo_batch_id', $batchId)->firstOrFail()` would
            // non-deterministically hand one of THOSE personas to
            // CareSubscriptionExampleData as "the customer". Found during
            // this skill's own pre-flight cross-task scan, fixed before any
            // implementer touched it.
            $customer = User::query()->create([
                'name' => \App\Support\ExampleData\DemoContactData::personName(300),
                'email' => \App\Support\ExampleData\DemoContactData::email(300),
                'password' => \Illuminate\Support\Facades\Hash::make('DemoContoh2026!'),
            ]);
            \App\Support\ExampleData\Concerns\TaggedAsDemoData::tag($customer, $batchId);

            $grave = $graveRecords[0] ?? GraveRecord::query()->firstOrFail();
            $summary['care_subscriptions'] = $this->runDomain('care subscriptions', function () use ($batchId, $customer, $grave) {
                return CareSubscriptionExampleData::seed($batchId, $customer->id, $grave->id);
            });

            $dibayarOrder = $summary['booking_orders'][2]; // index 2 = DIBAYAR, per Task 5's 5-state ordering
            $summary['certificates'] = $this->runDomain('certificates', function () use ($batchId, $dibayarOrder) {
                return CertificateExampleData::seed($batchId, $dibayarOrder);
            });

            $summary['visitation'] = $this->runDomain('visitation', function () use ($batchId, $cemetery) {
                return VisitationExampleData::seed($batchId, $cemetery);
            });

            // Drain the outbox OURSELVES, in-process, still inside the
            // suppression window — see the comment above handle()'s start
            // for why this is load-bearing, not defensive. Loop until a
            // batch comes back empty rather than trusting one call: this
            // run's own record count is small (well under the 50-row
            // default batch size) but looping is what actually GUARANTEES
            // full drainage regardless of volume, rather than assuming it.
            $publisher = new \App\Platform\Outbox\OutboxPublisher;
            while ($publisher->publishBatch() > 0) {
                // keep draining
            }
        });
        } finally {
            config(['queue.default' => $originalQueueDriver]);
        }

        DemoDataBatch::query()->create([
            'id' => (string) Str::uuid(),
            'batch_id' => $batchId,
            'summary' => array_map(static fn ($v) => is_array($v) ? count($v) : 1, $summary),
            'created_at' => now(),
        ]);

        $this->info("Demo data seeded. Batch id: {$batchId}");
        $this->table(['Domain', 'Result'], array_map(
            static fn (string $domain, mixed $result): array => [$domain, is_array($result) ? 'seeded' : 'seeded'],
            array_keys($summary),
            $summary,
        ));

        return self::SUCCESS;
    }

    private function runDomain(string $label, callable $callback): mixed
    {
        try {
            return DB::transaction($callback);
        } catch (Throwable $e) {
            $this->error("Domain [{$label}] failed: {$e->getMessage()}");

            throw $e;
        }
    }
}
```

- [ ] **Step 5: Run to verify both tests pass**

Expected: PASS. If any generator's real signature drifted from what earlier tasks assumed (a real risk given the density of this chain), fix the drift here rather than in the generator, unless the generator itself is wrong.

- [ ] **Step 6: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Console/Commands/DemoDataSeedCommand.php app/Models/DemoDataBatch.php tests/Feature/Console/DemoDataSeedCommandTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 7: Commit**

```bash
git add app/Console/Commands/DemoDataSeedCommand.php app/Models/DemoDataBatch.php tests/Feature/Console/DemoDataSeedCommandTest.php
git commit -m "feat(demo-data): add demo-data:seed orchestration command"
```

---

## Task 11: `demo-data:purge` command + full-cycle and partial-failure integration tests

**Files:**
- Create: `app/Console/Commands/DemoDataPurgeCommand.php`
- Test: `tests/Feature/Console/DemoDataPurgeCommandTest.php`

**Interfaces:**
- Consumes: `demo_data_batches` (Task 1/10), every table `demo-data:seed` writes to.
- Produces: a `demo-data:purge {batchId?} {--force}` artisan command.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DemoDataPurgeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_without_force_refuses(): void
    {
        $this->artisan('demo-data:purge')->assertFailed();
    }

    public function test_seed_then_purge_returns_the_database_to_its_pre_seed_state(): void
    {
        $before = [
            'orders' => \App\Domain\OrderWorkflow\Models\Order::query()->count(),
            'renewals' => \App\Domain\Renewal\Models\Renewal::query()->count(),
            'vendors' => \App\Domain\Marketplace\Models\Vendor::query()->count(),
            'users' => \App\Models\User::query()->count(),
        ];

        $this->artisan('demo-data:seed')->assertSuccessful();
        $this->artisan('demo-data:purge --force')->assertSuccessful();

        $this->assertSame($before['orders'], \App\Domain\OrderWorkflow\Models\Order::query()->count());
        $this->assertSame($before['renewals'], \App\Domain\Renewal\Models\Renewal::query()->count());
        $this->assertSame($before['vendors'], \App\Domain\Marketplace\Models\Vendor::query()->count());
        $this->assertSame($before['users'], \App\Models\User::query()->count());
    }

    public function test_a_forced_mid_run_domain_failure_leaves_earlier_domains_data_intact(): void
    {
        // Same FK-drop technique BookingWizardDegradedReadsTest established
        // (tests/Feature/Livewire/Public/Booking/BookingWizardDegradedReadsTest.php)
        // — force a genuine, real DB-level failure partway through seeding
        // (e.g. drop a FK a later domain's Action needs) so this proves
        // real transactional isolation, not a simulated one.
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropForeign(['customer_id']);
        });
        Schema::dropIfExists('users'); // whichever earlier domain's table the later domain genuinely needs — confirm the real FK chain at implementation time and adjust which table is dropped so the failure lands specifically in care-subscription seeding, after vendor/booking/renewal domains have already committed.

        $result = $this->artisan('demo-data:seed');
        $result->assertFailed();

        // Earlier-seeded domains (vendor accounts, booking orders, renewals)
        // survive — each ran in its own committed transaction before the
        // failure. The exact assertion here depends on which domain the
        // forced failure actually lands in; confirm via the command's own
        // error output during implementation and adjust the assertion to
        // check the LAST domain that should have succeeded before the drop
        // took effect.
    }
}
```

- [ ] **Step 2: Run to verify they fail**

Expected: FAIL — command does not exist.

- [ ] **Step 3: Implement `demo-data:purge`**

Read `PurgeExampleDataCommand.php`'s exact code style (confirmed during this plan's research: `--force`-required, `DB::transaction()`-wrapped, FK-respecting deletion order, per-table count summary) and mirror it, extended to every table this subsystem's generators write to. Delete in FK-safe reverse-dependency order: work-order-related rows before work orders, subscription cycles before subscriptions, agreements/certificates/visitation bookings/marketplace orders (leaf tables) before vendors/vendor_users, orders before booking_drafts, then role/scope assignments before users, then users, then any demo-created cemetery/grave-record rows last. Default to the most recent `demo_data_batches` row when no `{batchId}` argument is given.

```php
<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DemoDataBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class DemoDataPurgeCommand extends Command
{
    protected $signature = 'demo-data:purge {batchId?} {--force : Required. Purges without this flag are refused.}';

    protected $description = 'Remove every row tagged with a demo_batch_id, defaulting to the most recently seeded batch.';

    /**
     * FK-safe reverse-dependency order — confirm each table's real
     * incoming foreign keys at implementation time before finalizing this
     * list; this is the plan's best current ordering, not a guarantee no
     * table needs reordering once the real schema is checked table-by-table.
     *
     * @var list<string>
     */
    private const array DELETE_ORDER = [
        'work_evidence', 'service_acceptances', 'service_complaints', 'work_order_tasks',
        'work_orders', 'subscription_invoices', 'subscription_payment_references',
        'subscription_cycles', 'subscriptions', 'care_plans',
        'agreements', 'certificates', 'visitation_bookings', 'cemetery_visitation_policies',
        'vendor_order_evidences', 'vendor_orders', 'marketplace_order_items', 'marketplace_orders',
        'cart_items', 'carts', 'vendor_listings', 'vendor_availability', 'vendor_users', 'vendors',
        'orders', 'booking_drafts', 'renewals',
        'scope_assignments', 'actor_role_assignments',
        'users',
    ];

    public function handle(): int
    {
        $batchId = $this->argument('batchId') ?? DemoDataBatch::query()->orderByDesc('created_at')->value('batch_id');

        if ($batchId === null) {
            $this->error('No demo data batch found to purge.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->error('Refusing to purge without --force.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($batchId): void {
            foreach (self::DELETE_ORDER as $table) {
                $count = DB::table($table)->where('demo_batch_id', $batchId)->delete();

                if ($count > 0) {
                    $this->line(sprintf('%-28s %d', $table, $count));
                }
            }

            DemoDataBatch::query()->where('batch_id', $batchId)->delete();
        });

        $this->info("Purged demo data batch: {$batchId}");

        return self::SUCCESS;
    }
}
```

- [ ] **Step 4: Run to verify the tests pass**

Expected: PASS. The partial-failure test's exact assertion needs finalizing against the real FK chain (Step 3's own caveat) — resolve during implementation, not by weakening the assertion to something that doesn't actually prove isolation.

- [ ] **Step 5: Run pint and phpstan**

Run: `... vendor/bin/pint --test app/Console/Commands/DemoDataPurgeCommand.php tests/Feature/Console/DemoDataPurgeCommandTest.php`
Run: `... php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G`

Expected: both clean.

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/DemoDataPurgeCommand.php tests/Feature/Console/DemoDataPurgeCommandTest.php
git commit -m "feat(demo-data): add demo-data:purge command with full-cycle and partial-failure tests"
```

---

## Task 12: Documentation — credentials, run procedure, and the two real findings this plan surfaced

**Files:**
- Create: `docs/operations/demo-data.md`

**Interfaces:**
- Consumes: nothing.
- Produces: the one place this repo records the demo account password (spec decision 4) and the exact, safe run procedure (spec decision 5).

- [ ] **Step 1: Write the documentation**

```markdown
# Demo Seed Data

Generates realistic, safely-tagged demo data across every major makam-app
journey for a live demo — safe to run directly on the makam.co.id beta
host, and safe to fully remove afterward.

## Running it

```bash
php artisan demo-data:seed
```

Prints a batch id and a per-domain summary. **Never run this against
anything but a database you have personally confirmed is either your own
local/disposable Postgres, or the live beta host at a moment you have
explicitly decided to seed demo data** — this command has no way to
verify the database it's pointed at is safe; that is the operator's
responsibility every time, not a one-time authorization. Running the code
that adds this command (i.e. merging its PR) does NOT itself authorize
running it — treat every actual invocation as its own decision.

## Removing it

```bash
php artisan demo-data:purge --force
```

Defaults to the most recently seeded batch. Pass an explicit batch id
(printed by `demo-data:seed`, also recorded in the `demo_data_batches`
table) to purge an older batch instead.

## Demo account credentials

Every demo vendor and cemetery-operator account uses the same fixed,
intentionally weak password: `DemoContoh2026!`. This is deliberate —
these are single-purpose demo accounts, not real user accounts, and they
are purged along with everything else after the demo. **Never reuse this
password for a real account.** Demo account emails follow the pattern
`demo.contoh<N>@example.com` — find the exact seeded addresses via the
`demo-data:seed` command's own summary output, or by querying `users`
`WHERE demo_batch_id = '<batch id>'`.

## Known, deliberate scope limits

- **Care-subscription evidence upload is NOT seeded.** `UploadEvidence`
  requires a real, already-scanned `documents` row, and
  `config('document-vault.malware_scanner')` resolves to `null` outside
  the `development` environment — there is currently no way to produce a
  real Accepted document on beta at all, seed data or otherwise. This
  subsystem does not fabricate one. **This is a real, separate platform
  gap worth its own investigation** (does certificate/evidence upload
  work AT ALL on beta today?), independent of this demo-data effort.
- **`WorkOrder.status` never reaches `Completed` through any real Action**
  anywhere in this codebase today — confirmed by reading every Action in
  the vendor-fulfillment domain and grepping for every write of
  `WorkOrderStatus::Completed`. The demo seed data sets this status
  directly as a narrow, documented exception (see
  `CareSubscriptionExampleData`'s own doc block) so a demo work order can
  visibly read as finished; this is a real, separate gap in the
  application's own domain modeling, not something this subsystem
  invented or fixed.
```

- [ ] **Step 2: Run `ci/verify-docs.sh` to confirm the new doc doesn't break any gate**

Run: `bash ci/verify-docs.sh`

Expected: all 13 gates PASS.

- [ ] **Step 3: Commit**

```bash
git add docs/operations/demo-data.md
git commit -m "docs(demo-data): record demo credentials, run procedure, and two real platform findings"
```

---

## Final Whole-Branch Review

After all 12 tasks are complete, per `superpowers:subagent-driven-development`: dispatch the final whole-branch code reviewer on the most capable available model. Give it this plan and the spec, and ask it to specifically check, beyond the normal review rubric:

1. Every generator's every write is tagged with `demo_batch_id` — no untagged, unpurgeable row anywhere.
2. Every contact field anywhere in the new code goes through `DemoContactData` — grep for any hardcoded email/phone string that isn't.
3. `DemoDataSuppression`'s guard is genuinely present in BOTH `DispatchOrderNotifications` and `DispatchNotificationConsumerOnOutboxEventPublished` — not just one.
4. The `paid()`/`completed()` split in Task 5 (`MarkOrderPaid` → `DIBAYAR`, then `ProcessOrder` → `DIPROSES`, then `CompleteOrder` → `SELESAI`) was implemented exactly as this plan's own research resolved it — not collapsed back into a two-hop guess. Task 9's certificate generator genuinely receives the `DIBAYAR`-status order (index `[2]`), not the `SELESAI` one.
5. `demo-data:purge`'s `DELETE_ORDER` genuinely respects every real FK in the schema — this plan's own list is a best-effort draft, not a verified-correct one.
6. Nothing in this branch runs `demo-data:seed` against anything but a disposable test database — grep for the command's own name across any script/CI config this branch might have touched.

Once the final review is clean, hand off to `superpowers:finishing-a-development-branch`: push and open a PR (never merge without the human sign-off this branch's production-affecting nature requires per `AGENTS.md`), and explicitly remind the user in the PR description that merging this PR does NOT authorize running `demo-data:seed` against beta — that is spec decision 5's separate, later checkpoint.
