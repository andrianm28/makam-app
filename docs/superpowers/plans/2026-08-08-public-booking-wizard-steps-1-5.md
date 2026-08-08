# Public Booking Wizard — Steps 1-5 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Resume Sprint 4's paused S4-T4/S4-T5 batch — build the public booking wizard shell (nine-step stepper, Steps 1-5 real: city, cemetery, service type, service selection, summary) with its domain-side draft persistence, versioning, idempotency, and server-side step validation.

**Architecture:** A domain module `App\Domain\Booking` (model, closed lists, Actions, query) behind a single Livewire full-page component `App\Livewire\Public\Booking\BookingWizard`, following the established `CemeteryDirectory`/`CemeteryPublicQuery` and `ServiceCatalog`/`ServiceCatalogQuery` shape. The wizard reads and writes through `BookingDraftQuery` and two Actions (`StartBookingDraft`, `SaveBookingDraftStep`) only — never `BookingDraft::query()` from the Livewire layer.

**Tech Stack:** Laravel 13, Livewire 4, PHPUnit 12 (Pest-style `test_` methods, matching this repo's convention), PostgreSQL 18 in CI / SQLite locally, `App\Platform\Audit` for mutation audit trail.

## Global Constraints

- Never hardcode a hex, px, ms, or shadow value; never use a Tailwind arbitrary value — every design value comes from `resources/css/tokens.css` (`docs/design/design-system.md` §9.2, CI-enforced by `ci/verify-docs.sh` GATE 2/3).
- Public reads go through a `*Query` class only, never a bare `Model::query()` from `App\Livewire\**` (`AGENTS.md` §Architecture; `app/Domain/README.md`).
- Every domain-layer write goes through an invokable Action in `app/Domain/Booking/Actions/`, never a Livewire component mutating `BookingDraft` directly.
- Closed lists (`BookingServiceType`, `BookingWizardStep`) are plain `final class` + `public const string`/`int` + `KNOWN_*` array + `isKnown()`/`assertKnown()` — this codebase's established convention (`App\Domain\CemeteryDirectory\LaunchCityCode`, `App\Domain\ServiceCatalog\ServiceCode`), never a PHP backed enum, never a Postgres enum type.
- Test method names are `test_snake_case_full_sentence`, extending `Tests\TestCase`, using `Illuminate\Foundation\Testing\RefreshDatabase` and `Livewire\Livewire::test()` — matching `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php`.
- No stub, mock, or fake replaces a real seeded row or a real dropped table in a degraded-mode test — this codebase's tests exercise real failure paths (see `RenewalStartTest::test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing`).
- `declare(strict_types=1);` at the top of every new PHP file, matching every existing file in `app/Domain/**`.
- Every mutation Action wraps its write in `App\Platform\Audit\Audit::wrap()` or calls `Audit::record()` inside its own transaction — established convention for every domain Action in this codebase (`DefineServicePackage`, the Faq Actions). None of this batch's actions are `SensitiveActions`-listed, so `reason` stays `null`.
- Out of scope for this plan (do not build): Steps 6-9 (customer data, deceased data + uploads, payment, confirmation+notifications); full product-type routing to `FuneralCase`/`PreNeedCase` (AC4 in `booking-and-order-orchestration`); a persisted, versioned `Quote` aggregate (AC8) — Step 5 renders a **computed** price presentation from `ServiceCatalog` pricing, not a Quote row; the `OVERLAPPING_GRAVE`/`URGENT_TODAY` operational preconditions named in `booking-wizard-fields.md` §Step 3 (no signal exists yet — `cemetery-directory-and-availability` AC6-9 and the Urgent SLA open decision are both unbuilt/unresolved); the Step-4-add-ons-vs-marketplace bridge (`docs/planning/ekspektasi-vs-specs.md` §4 D4, genuinely undecided).

---

## Pre-Flight Corrections (human-confirmed 08 Aug 2026)

These amend the task text below. They are binding; where a task's code or tests contradict them, the correction wins.

- **R1 (Audit metadata — Task 6/7):** `SaveBookingDraftStep` must NOT pass `metadata: ['step' => $step]` to `Audit::record()`. `MetadataAllowlist::ALLOWED_KEYS` has no `step` key and `Audit::record()` throws `AuditMetadataKeyNotAllowedException` for disallowed keys. Drop the metadata argument entirely (action + subject + version still identify the step).
- **R2 (Eager draft creation — Task 9/10):** In `BookingWizard::saveStep1()`, wrap `currentOrNewDraft()` + `SaveBookingDraftStep` in `DB::transaction` so a `BookingStepValidationException` rolls back the freshly created draft. The test `test_an_invalid_step_1_submission_shows_a_field_error_and_creates_no_draft` must see 0 drafts.
- **R3 (Seeded prices — Task 4 and Task 10):** `2026_07_26_220000_seed_service_definition_dummy_operational_data.php` seeds a `price_versions` row (version 1, `superseded_at` null) for ALL 12 services; `price_versions` has `UNIQUE(priceable_type, priceable_id, version_number)`. Seeded amounts include DOCUMENT_PROCESSING 350000.0, GRAVE_DIGGING 750000.0. Test fixtures must supersede seeded rows before asserting missing-price state, and must create fixture prices with a NEW version_number (e.g. 2), never re-using the seeded version 1.
  - Task 4 `test_summary_marks_a_missing_price_honestly...`: first supersede the seeded price for the service under test, then assert the missing-price state.
  - Task 4 `test_summary_computes_line_totals...`: supersede the seeded DOCUMENT_PROCESSING price, then create the fixture price with `version_number => 2`, amount 150000.
  - Task 10 step-5 test: seeded prices exist, so assert a real computed total (DOCUMENT_PROCESSING 350000 + GRAVE_DIGGING 750000 = 1100000) instead of `Harga belum tersedia`. The honest missing-price state remains covered only by Task 4's explicit fixture.
- **R4 (Task 6 step-2 happy path):** `test_step_2_accepts_a_published_cemetery_matching_the_selected_city` must add `->whereDoesntHave('packages')` (the first published JAKARTA cemetery, TPU Jakarta Menteng, has active packages and would force a step-2 package error).

## Task 1: `booking_drafts` migration

**Files:**
- Create: `database/migrations/2026_08_08_130000_create_booking_drafts_table.php`
- Test: none (schema-only task; exercised by Task 3's model test)

**Interfaces:**
- Produces: `booking_drafts` table — `id` (uuid pk), `user_id` (nullable FK), `current_step` (unsigned tinyint), `completed_steps` (json), `city_code` (nullable string), `cemetery_id` (nullable FK uuid), `cemetery_package_id` (nullable FK bigint), `service_type` (nullable string), `selected_services` (json), `version` (unsigned int), `last_idempotency_key` (nullable string), timestamps.

- [ ] **Step 1: Write the migration**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `booking_drafts` — `.kiro/specs/booking-and-order-orchestration/design.md`
 * Data section names `booking_drafts` as one of this module's tables.
 * Backs requirements.md AC2 (resumable, idempotent, versioned, owned by
 * exactly one customer/token) for Steps 1-5 only — see this plan's Global
 * Constraints for what is deliberately deferred (Quote, Order, product-type
 * routing).
 *
 * `id` is a UUID (`HasUuids` on the model) and doubles as
 * `booking-wizard-fields.md`'s "secure opaque token" for anonymous resume —
 * `docs/contracts/openapi.yaml`'s `DraftId` parameter already commits
 * `format: uuid` for this exact resource, so this migration follows that
 * existing external contract rather than inventing a second identifier.
 *
 * `cemetery_id` is `nullOnDelete`, not `restrictOnDelete` like
 * `grave_records.cemetery_id` — an abandoned draft referencing a since-
 * deleted cemetery is not a registry-integrity concern the way a burial
 * record is; the draft simply loses its cemetery selection and the wizard
 * re-prompts step 2.
 *
 * `selected_services` stores a `list<array{code: string, quantity: int}>` —
 * a JSON column, not a relational table, because this batch does not build
 * quote issuance (AC8, out of scope): the selection is draft-local intent,
 * never snapshotted into an immutable order line. A later batch building
 * real quote issuance is expected to read this column when it exists, not
 * extend it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('booking_drafts', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->unsignedTinyInteger('current_step')->default(1);
            $table->json('completed_steps')->default(new \Illuminate\Database\Query\Expression("'[]'"));

            $table->string('city_code', 16)->nullable();

            $table->foreignUuid('cemetery_id')->nullable()->constrained('cemeteries')->nullOnDelete();
            $table->foreignId('cemetery_package_id')->nullable()->constrained('cemetery_packages')->nullOnDelete();

            $table->string('service_type', 32)->nullable();

            $table->json('selected_services')->default(new \Illuminate\Database\Query\Expression("'[]'"));

            $table->unsignedInteger('version')->default(1);
            $table->string('last_idempotency_key', 64)->nullable();

            $table->timestamps();

            $table->index('user_id', 'booking_drafts_user_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booking_drafts');
    }
};
```

- [ ] **Step 2: Verify the migration file is syntactically valid**

Run: `php -l database/migrations/2026_08_08_130000_create_booking_drafts_table.php`
Expected: `No syntax errors detected`

(Do not attempt `php artisan migrate` on this host — `vendor/` is empty by policy, `docs/operations/ci-cd-and-release.md` §10. CI is the migration oracle; this is verified by Task 3's tests running green in CI.)

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_08_08_130000_create_booking_drafts_table.php
git commit -m "feat(booking): add booking_drafts table"
```

---

## Task 2: `BookingServiceType` and `BookingWizardStep` closed lists

**Files:**
- Create: `app/Domain/Booking/BookingServiceType.php`
- Create: `app/Domain/Booking/BookingWizardStep.php`
- Test: `tests/Unit/Domain/Booking/BookingServiceTypeTest.php`
- Test: `tests/Unit/Domain/Booking/BookingWizardStepTest.php`

**Interfaces:**
- Produces: `BookingServiceType::{NEW_GRAVE,OVERLAPPING_GRAVE,URGENT_TODAY,PRE_NEED,KNOWN_CODES}`, `BookingServiceType::isKnown(string): bool`, `BookingServiceType::assertKnown(string): void`.
- Produces: `BookingWizardStep::{LOCATION=1,CEMETERY=2,SERVICE_TYPE=3,SERVICES=4,SUMMARY=5,CUSTOMER_DATA=6,DECEASED_DATA=7,PAYMENT=8,CONFIRMATION=9,LABELS,LAST_IMPLEMENTED=5}`, `labels(): array`, `count(): int`, `isKnown(int): bool`, `assertKnown(int): void`, `label(int): string`.

- [ ] **Step 1: Write the failing tests**

`tests/Unit/Domain/Booking/BookingServiceTypeTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingServiceType;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingServiceTypeTest extends TestCase
{
    public function test_all_four_service_types_are_known(): void
    {
        foreach (['NEW_GRAVE', 'OVERLAPPING_GRAVE', 'URGENT_TODAY', 'PRE_NEED'] as $code) {
            $this->assertTrue(BookingServiceType::isKnown($code), "Expected [{$code}] to be known.");
        }
    }

    public function test_known_codes_matches_booking_wizard_fields_step_3_order(): void
    {
        $this->assertSame(
            [
                BookingServiceType::NEW_GRAVE,
                BookingServiceType::OVERLAPPING_GRAVE,
                BookingServiceType::URGENT_TODAY,
                BookingServiceType::PRE_NEED,
            ],
            BookingServiceType::KNOWN_CODES,
        );
    }

    public function test_an_unknown_code_is_not_known(): void
    {
        $this->assertFalse(BookingServiceType::isKnown('CREMATION'));
    }

    public function test_assert_known_throws_for_an_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingServiceType::assertKnown('CREMATION');
    }

    public function test_assert_known_is_silent_for_a_known_code(): void
    {
        BookingServiceType::assertKnown(BookingServiceType::URGENT_TODAY);

        $this->addToAssertionCount(1);
    }
}
```

`tests/Unit/Domain/Booking/BookingWizardStepTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingWizardStepTest extends TestCase
{
    public function test_there_are_nine_steps(): void
    {
        $this->assertSame(9, BookingWizardStep::count());
    }

    public function test_steps_one_through_five_are_the_last_implemented_boundary(): void
    {
        $this->assertSame(BookingWizardStep::SUMMARY, BookingWizardStep::LAST_IMPLEMENTED);
        $this->assertSame(5, BookingWizardStep::LAST_IMPLEMENTED);
    }

    public function test_every_step_one_through_nine_is_known(): void
    {
        for ($step = 1; $step <= 9; $step++) {
            $this->assertTrue(BookingWizardStep::isKnown($step), "Expected step [{$step}] to be known.");
        }
    }

    public function test_step_zero_and_step_ten_are_not_known(): void
    {
        $this->assertFalse(BookingWizardStep::isKnown(0));
        $this->assertFalse(BookingWizardStep::isKnown(10));
    }

    public function test_assert_known_throws_outside_the_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingWizardStep::assertKnown(10);
    }

    public function test_labels_match_booking_wizard_fields_headings_in_order(): void
    {
        $this->assertSame(
            [
                1 => 'Pilih Lokasi',
                2 => 'Pilih TPU/TPS',
                3 => 'Pilih Jenis Layanan',
                4 => 'Pilih Layanan',
                5 => 'Ringkasan Pesanan',
                6 => 'Data Pemesan',
                7 => 'Data Almarhum and Documents',
                8 => 'Pembayaran',
                9 => 'Konfirmasi',
            ],
            BookingWizardStep::labels(),
        );
    }

    public function test_label_of_a_known_step_matches_labels_map(): void
    {
        $this->assertSame('Pilih Jenis Layanan', BookingWizardStep::label(BookingWizardStep::SERVICE_TYPE));
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Unit/Domain/Booking/BookingServiceTypeTest.php tests/Unit/Domain/Booking/BookingWizardStepTest.php`
Expected: FAIL — `Class "App\Domain\Booking\BookingServiceType" not found` (and the same for `BookingWizardStep`)

- [ ] **Step 3: Write `BookingServiceType`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * The closed list of `booking_drafts.service_type` values —
 * `docs/product/booking-wizard-fields.md` §Step 3 ("Pilih Jenis Layanan"):
 * `NEW_GRAVE`, `OVERLAPPING_GRAVE`, `URGENT_TODAY`, `PRE_NEED`, in that
 * document's own order. Plain string column with application-layer
 * validation, not a Postgres enum type — the same convention as
 * `App\Domain\CemeteryDirectory\LaunchCityCode` and
 * `App\Domain\ServiceCatalog\ServiceCode`.
 *
 * This class was the subject of a recorded "Open decision" in both
 * `public-booking-wizard/design.md` and `booking-and-order-orchestration/
 * design.md` (08 Aug 2026): which module should own it. Resolved here, in
 * `App\Domain\Booking`, because `booking-and-order-orchestration` AC4
 * ("select an explicit product type and workflow") is this list's routing
 * consumer; `public-booking-wizard`'s Step 3 rendering consumes it
 * read-only.
 *
 * Selecting `OVERLAPPING_GRAVE` or `URGENT_TODAY` records the choice only —
 * this batch enforces NO operational precondition on either value.
 * `booking-wizard-fields.md` documents preconditions this codebase cannot
 * yet check: "OVERLAPPING_GRAVE only selectable when cemetery/package
 * supports it" needs a signal `cemetery-directory-and-availability` AC6-9
 * do not yet provide (unbuilt — verified 08 Aug 2026 baseline analysis);
 * "URGENT_TODAY checks service area, operating hours, and capacity" needs
 * infrastructure that does not exist and depends on
 * `docs/governance/assumptions-and-gates.md` §5 open decision #6 (Urgent
 * SLA), unresolved. Both are recorded here rather than guessed at with an
 * invented signal.
 */
final class BookingServiceType
{
    public const string NEW_GRAVE = 'NEW_GRAVE';

    public const string OVERLAPPING_GRAVE = 'OVERLAPPING_GRAVE';

    public const string URGENT_TODAY = 'URGENT_TODAY';

    public const string PRE_NEED = 'PRE_NEED';

    /**
     * @var list<string>
     */
    public const array KNOWN_CODES = [
        self::NEW_GRAVE,
        self::OVERLAPPING_GRAVE,
        self::URGENT_TODAY,
        self::PRE_NEED,
    ];

    public static function isKnown(string $code): bool
    {
        return in_array($code, self::KNOWN_CODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$code` is not one of
     *                                  `self::KNOWN_CODES`.
     */
    public static function assertKnown(string $code): void
    {
        if (! self::isKnown($code)) {
            throw new InvalidArgumentException(
                "Unknown booking service type [{$code}]. Known types: ".implode(', ', self::KNOWN_CODES).'.'
            );
        }
    }
}
```

- [ ] **Step 4: Write `BookingWizardStep`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use InvalidArgumentException;

/**
 * The nine steps of the public booking journey —
 * `docs/product/booking-wizard-fields.md`'s own step headings, in order.
 * Same shape and role as `App\Domain\Renewal\RenewalJourneyStep`: a fixed
 * vocabulary (`final class` + `const`), not a database-backed closed list —
 * see that class's own doc block for why.
 *
 * ---------------------------------------------------------------------------
 * Only steps 1-5 are BUILT in this batch
 * ---------------------------------------------------------------------------
 * Steps 6-9 (customer data, deceased data + documents, payment,
 * confirmation) render as `upcoming` stepper dots and have no screen behind
 * them yet — `design-system.md` §6.9's rule that a closed gate (or an
 * unbuilt step) "never removes a required MVP step" applies here exactly as
 * `RenewalJourneyStep::LAST_IMPLEMENTED`'s own doc block explains for the
 * renewal journey. A user must be able to see the whole nine-step journey
 * they are entering, per `public-booking-wizard/design.md`'s "Stepper is a
 * presentation contract" section: "Urgent and Pre-Need may branch
 * internally, but the stepper still reads 1-9."
 *
 * Label for step 7 is copied verbatim from `booking-wizard-fields.md`'s own
 * heading ("Data Almarhum and Documents") including its mixed
 * Indonesian/English wording — this class does not smooth over or
 * translate source copy.
 */
final class BookingWizardStep
{
    public const int LOCATION = 1;

    public const int CEMETERY = 2;

    public const int SERVICE_TYPE = 3;

    public const int SERVICES = 4;

    public const int SUMMARY = 5;

    public const int CUSTOMER_DATA = 6;

    public const int DECEASED_DATA = 7;

    public const int PAYMENT = 8;

    public const int CONFIRMATION = 9;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::LOCATION => 'Pilih Lokasi',
        self::CEMETERY => 'Pilih TPU/TPS',
        self::SERVICE_TYPE => 'Pilih Jenis Layanan',
        self::SERVICES => 'Pilih Layanan',
        self::SUMMARY => 'Ringkasan Pesanan',
        self::CUSTOMER_DATA => 'Data Pemesan',
        self::DECEASED_DATA => 'Data Almarhum and Documents',
        self::PAYMENT => 'Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    /**
     * The last step with a screen behind it in this batch. Steps after this
     * one are real and visible on the stepper but not yet reachable.
     */
    public const int LAST_IMPLEMENTED = self::SUMMARY;

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    public static function count(): int
    {
        return count(self::LABELS);
    }

    public static function isKnown(int $step): bool
    {
        return array_key_exists($step, self::LABELS);
    }

    /**
     * @throws InvalidArgumentException when `$step` is outside 1..9.
     */
    public static function assertKnown(int $step): void
    {
        if (! self::isKnown($step)) {
            throw new InvalidArgumentException(
                "Unknown booking wizard step [{$step}]. Known steps: 1-".self::count().'.'
            );
        }
    }

    public static function label(int $step): string
    {
        self::assertKnown($step);

        return self::LABELS[$step];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Unit/Domain/Booking/BookingServiceTypeTest.php tests/Unit/Domain/Booking/BookingWizardStepTest.php`
Expected: PASS (11 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/BookingServiceType.php app/Domain/Booking/BookingWizardStep.php tests/Unit/Domain/Booking/BookingServiceTypeTest.php tests/Unit/Domain/Booking/BookingWizardStepTest.php
git commit -m "feat(booking): add BookingServiceType and BookingWizardStep closed lists"
```

---

## Task 3: `BookingDraft` model

**Files:**
- Create: `app/Domain/Booking/Models/BookingDraft.php`
- Test: `tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php`

**Interfaces:**
- Consumes: `App\Domain\Booking\BookingServiceType::assertKnown(string): void` (Task 2), `App\Domain\CemeteryDirectory\LaunchCityCode::assertKnown(string): void` (existing).
- Produces: `BookingDraft` Eloquent model, table `booking_drafts`, `HasUuids`, casts `completed_steps` and `selected_services` to `array`, relations `cemetery(): BelongsTo`, `cemeteryPackage(): BelongsTo`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

final class BookingDraftClosedListValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_can_be_created_with_no_optional_fields_set(): void
    {
        $draft = BookingDraft::create([]);

        $this->assertNotNull($draft->id);
        $this->assertTrue(\Illuminate\Support\Str::isUuid($draft->id));
        $this->assertSame(1, $draft->current_step);
        $this->assertSame([], $draft->completed_steps);
        $this->assertSame([], $draft->selected_services);
        $this->assertSame(1, $draft->version);
    }

    public function test_an_unknown_city_code_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingDraft::create(['city_code' => 'SURABAYA']);
    }

    public function test_a_known_city_code_is_accepted(): void
    {
        $draft = BookingDraft::create(['city_code' => 'JAKARTA']);

        $this->assertSame('JAKARTA', $draft->city_code);
    }

    public function test_a_null_city_code_is_accepted(): void
    {
        $draft = BookingDraft::create(['city_code' => null]);

        $this->assertNull($draft->city_code);
    }

    public function test_an_unknown_service_type_is_rejected_on_save(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BookingDraft::create(['service_type' => 'CREMATION']);
    }

    public function test_a_known_service_type_is_accepted(): void
    {
        $draft = BookingDraft::create(['service_type' => 'URGENT_TODAY']);

        $this->assertSame('URGENT_TODAY', $draft->service_type);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Models\BookingDraft" not found`

- [ ] **Step 3: Write `BookingDraft`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Models;

use App\Domain\Booking\BookingServiceType;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for `booking_drafts` — see the migration
 * (`2026_08_08_130000_create_booking_drafts_table.php`) for schema
 * reasoning. Owned by `App\Domain\Booking`
 * (`booking-and-order-orchestration`'s module). Never constructed with
 * `BookingDraft::create()`/`::update()` from outside `app/Domain/Booking/
 * Actions/` — see `App\Domain\Booking\Actions\StartBookingDraft` and
 * `SaveBookingDraftStep`, this module's only write path
 * (`app/Domain/README.md`: domain logic lives here, not in Livewire
 * components).
 *
 * `id` is a UUID (`HasUuids`) — see the migration's own doc block for why
 * it doubles as the anonymous resume token.
 */
final class BookingDraft extends Model
{
    use HasUuids;

    protected $table = 'booking_drafts';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'id',
        'user_id',
        'current_step',
        'completed_steps',
        'city_code',
        'cemetery_id',
        'cemetery_package_id',
        'service_type',
        'selected_services',
        'version',
        'last_idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'current_step' => 'integer',
            'completed_steps' => 'array',
            'selected_services' => 'array',
            'version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $draft): void {
            if ($draft->city_code !== null) {
                LaunchCityCode::assertKnown($draft->city_code);
            }

            if ($draft->service_type !== null) {
                BookingServiceType::assertKnown($draft->service_type);
            }
        });
    }

    /**
     * @return BelongsTo<Cemetery, $this>
     */
    public function cemetery(): BelongsTo
    {
        return $this->belongsTo(Cemetery::class, 'cemetery_id');
    }

    /**
     * @return BelongsTo<CemeteryPackage, $this>
     */
    public function cemeteryPackage(): BelongsTo
    {
        return $this->belongsTo(CemeteryPackage::class, 'cemetery_package_id');
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php`
Expected: PASS (6 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Models/BookingDraft.php tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php
git commit -m "feat(booking): add BookingDraft model with closed-list validation"
```

---

## Task 4: `BookingDraftQuery` (read side, incl. price summary)

**Files:**
- Create: `app/Domain/Booking/BookingDraftQuery.php`
- Test: `tests/Feature/Domain/Booking/BookingDraftQueryTest.php`

**Interfaces:**
- Consumes: `App\Domain\Booking\Models\BookingDraft` (Task 3), `App\Domain\ServiceCatalog\Models\ServiceDefinition::findByCode(string): ?ServiceDefinition` (existing).
- Produces: `BookingDraftQuery::find(string $draftId): ?BookingDraft`, `BookingDraftQuery::summary(BookingDraft $draft): array{lines: list<array{code:string,label:string,quantity:int,unit_price:?float,line_total:?float}>, total: ?float, all_prices_available: bool}`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking;

use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BookingDraftQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_find_returns_null_for_an_unknown_id(): void
    {
        $this->assertNull(BookingDraftQuery::find('00000000-0000-0000-0000-000000000000'));
    }

    public function test_find_returns_null_for_a_non_uuid_string(): void
    {
        $this->assertNull(BookingDraftQuery::find('not-a-uuid'));
    }

    public function test_find_returns_the_draft_for_a_real_id(): void
    {
        $draft = BookingDraft::create(['city_code' => 'JAKARTA']);

        $found = BookingDraftQuery::find($draft->id);

        $this->assertNotNull($found);
        $this->assertSame($draft->id, $found->id);
    }

    public function test_summary_of_an_empty_draft_has_no_lines_and_no_total(): void
    {
        $draft = BookingDraft::create([]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertSame([], $summary['lines']);
        $this->assertNull($summary['total']);
        $this->assertTrue($summary['all_prices_available']);
    }

    public function test_summary_computes_line_totals_and_a_total_when_prices_exist(): void
    {
        $service = ServiceDefinition::query()->where('code', 'DOCUMENT_PROCESSING')->firstOrFail();

        PriceVersion::create([
            'priceable_type' => ServiceDefinition::class,
            'priceable_id' => $service->id,
            'version_number' => 1,
            'amount' => 150000,
            'currency' => 'IDR',
            'effective_at' => now(),
            'superseded_at' => null,
        ]);

        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ],
        ]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertCount(1, $summary['lines']);
        $this->assertSame('DOCUMENT_PROCESSING', $summary['lines'][0]['code']);
        $this->assertSame(1, $summary['lines'][0]['quantity']);
        $this->assertSame(150000.0, $summary['lines'][0]['unit_price']);
        $this->assertSame(150000.0, $summary['lines'][0]['line_total']);
        $this->assertSame(150000.0, $summary['total']);
        $this->assertTrue($summary['all_prices_available']);
    }

    public function test_summary_marks_a_missing_price_honestly_instead_of_fabricating_a_total(): void
    {
        $draft = BookingDraft::create([
            'selected_services' => [
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ]);

        $summary = BookingDraftQuery::summary($draft);

        $this->assertNull($summary['lines'][0]['unit_price']);
        $this->assertNull($summary['lines'][0]['line_total']);
        $this->assertNull($summary['total']);
        $this->assertFalse($summary['all_prices_available']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/BookingDraftQueryTest.php`
Expected: FAIL — `Class "App\Domain\Booking\BookingDraftQuery" not found`

- [ ] **Step 3: Write `BookingDraftQuery`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use Illuminate\Support\Str;

/**
 * The read entry point for a booking draft — the single place the wizard's
 * Livewire layer reads a `BookingDraft` from. Mirrors
 * `App\Domain\CemeteryDirectory\CemeteryPublicQuery`'s role and doc-block
 * reasoning.
 *
 * No `Projection` value object here, unlike `App\Domain\GraveRegistry\
 * GraveRecordProjection`: a draft's own owner reading their own draft is
 * not the access-policy problem that class exists to solve (nothing on a
 * `BookingDraft` is restricted from the person who holds its id). Returning
 * the Eloquent model directly is a deliberate YAGNI choice for this batch.
 */
final class BookingDraftQuery
{
    /**
     * One draft by id, or `null` when the id does not exist or is not a
     * UUID at all — the same "tampered value is a clean miss, never a 500"
     * discipline as `CemeteryPublicQuery::findPublishedById()`.
     */
    public static function find(string $draftId): ?BookingDraft
    {
        $draftId = trim($draftId);

        if ($draftId === '' || ! Str::isUuid($draftId)) {
            return null;
        }

        /** @var BookingDraft|null $draft */
        $draft = BookingDraft::query()->whereKey($draftId)->first();

        return $draft;
    }

    /**
     * Step 5's price presentation — computed from `ServiceCatalog`'s
     * current price versions, NEVER a persisted Quote row (AC8 is out of
     * scope for this batch; see this plan's Global Constraints). When any
     * selected service has no current price version, that line's price and
     * the overall total are `null` and `all_prices_available` is `false` —
     * an honest "harga belum tersedia" state, never a fabricated total that
     * silently excludes a line.
     *
     * @return array{lines: list<array{code: string, label: string, quantity: int, unit_price: ?float, line_total: ?float}>, total: ?float, all_prices_available: bool}
     */
    public static function summary(BookingDraft $draft): array
    {
        $lines = [];
        $total = 0.0;
        $allPricesAvailable = true;

        foreach ($draft->selected_services as $selection) {
            $code = (string) $selection['code'];
            $quantity = (int) $selection['quantity'];

            $definition = ServiceDefinition::findByCode($code);
            $priceVersion = $definition?->currentPriceVersion();

            $unitPrice = $priceVersion !== null ? (float) $priceVersion->amount : null;
            $lineTotal = $unitPrice !== null ? $unitPrice * $quantity : null;

            if ($unitPrice === null) {
                $allPricesAvailable = false;
            } else {
                $total += $lineTotal;
            }

            $lines[] = [
                'code' => $code,
                'label' => $definition?->name ?? $code,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'lines' => $lines,
            'total' => $allPricesAvailable && $lines !== [] ? $total : null,
            'all_prices_available' => $allPricesAvailable,
        ];
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/BookingDraftQueryTest.php`
Expected: PASS (6 tests)

**Note for the implementer:** if `App\Domain\ServiceCatalog\Models\PriceVersion`'s actual fillable/cast field names differ from what Step 1's test assumes (`priceable_type`/`priceable_id`/`version_number`/`amount`/`currency`/`effective_at`/`superseded_at`), read that model directly before running this task and adjust the test fixture to match — do not guess a second time; this plan's author read the model's `morphMany`/`currentPriceVersion()` usage but not its migration's exact column list.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/BookingDraftQuery.php tests/Feature/Domain/Booking/BookingDraftQueryTest.php
git commit -m "feat(booking): add BookingDraftQuery with Step 5 price summary"
```

---

## Task 5: `StartBookingDraft` Action

**Files:**
- Create: `app/Domain/Booking/Actions/StartBookingDraft.php`
- Test: `tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php`

**Interfaces:**
- Consumes: `App\Domain\Booking\Models\BookingDraft` (Task 3), `App\Platform\Audit\Audit::record()` (existing, signature above).
- Produces: `StartBookingDraft::__invoke(?int $userId = null): BookingDraft`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\StartBookingDraft;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StartBookingDraftTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_a_fresh_anonymous_draft_at_step_one(): void
    {
        $draft = (new StartBookingDraft)();

        $this->assertNull($draft->user_id);
        $this->assertSame(1, $draft->current_step);
        $this->assertSame([], $draft->completed_steps);
        $this->assertSame(1, $draft->version);
    }

    public function test_it_attaches_a_user_id_when_given_one(): void
    {
        $draft = (new StartBookingDraft)(userId: 42);

        $this->assertSame(42, $draft->user_id);
    }

    public function test_it_records_an_audit_event(): void
    {
        $draft = (new StartBookingDraft)();

        $event = AuditEvent::query()->where('subject_id', $draft->id)->first();

        $this->assertNotNull($event);
        $this->assertSame('BOOKING_DRAFT_STARTED', $event->action);
        $this->assertSame('allowed', $event->outcome);
    }

    public function test_two_calls_create_two_distinct_drafts(): void
    {
        $first = (new StartBookingDraft)();
        $second = (new StartBookingDraft)();

        $this->assertNotSame($first->id, $second->id);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Actions\StartBookingDraft" not found`

- [ ] **Step 3: Write `StartBookingDraft`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;

/**
 * Creates a new, empty `booking_drafts` row at step 1 — the ONLY way this
 * module creates a draft. `booking-wizard-fields.md` §Global behavior:
 * "Draft created at first meaningful input" — callers invoke this the
 * moment the visitor makes their first Step 1 selection, never eagerly on
 * page load (see `App\Livewire\Public\Booking\BookingWizard::mount()`,
 * Task 9).
 *
 * Audited via `Audit::record()`, wrapped in its own `DB::transaction()` so
 * the draft row and its audit event commit or roll back together — this
 * plan's Global Constraints ("every domain-layer write... calls
 * `Audit::record()` inside its own transaction") and the same precedent as
 * `App\Domain\ServiceCatalog\Actions\DefineServicePackage`. Not
 * `SensitiveActions`-listed.
 */
final readonly class StartBookingDraft
{
    public function __invoke(?int $userId = null): BookingDraft
    {
        return DB::transaction(function () use ($userId): BookingDraft {
            $draft = BookingDraft::create([
                'user_id' => $userId,
            ]);

            Audit::record(
                action: 'BOOKING_DRAFT_STARTED',
                subject: new AuditSubject('booking_draft', $draft->id, $draft->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $userId,
                actorRole: $userId !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
            );

            return $draft;
        });
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Actions/StartBookingDraft.php tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php
git commit -m "feat(booking): add StartBookingDraft action"
```

---

## Task 6: `SaveBookingDraftStep` — Steps 1-3

**Files:**
- Create: `app/Domain/Booking/Actions/SaveBookingDraftStep.php`
- Create: `app/Domain/Booking/Exceptions/BookingStepValidationException.php`
- Test: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`

**Interfaces:**
- Consumes: `BookingDraft` (Task 3), `BookingWizardStep::assertKnown()` (Task 2), `LaunchCityCode::assertKnown()` (existing), `App\Domain\CemeteryDirectory\CemeteryPublicQuery::findPublishedById()` (existing), `App\Domain\CemeteryDirectory\CemeteryPublicQuery::activePackages()` (existing), `BookingServiceType::assertKnown()` (Task 2).
- Produces: `SaveBookingDraftStep::__invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey): BookingDraft`. Throws `BookingStepValidationException` (field-keyed errors, `getErrors(): array<string, list<string>>`).

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // Step 1 — location
    // =====================================================================

    public function test_step_1_accepts_a_known_launch_city(): void
    {
        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, [
            'city_code' => LaunchCityCode::JAKARTA,
        ], 'idem-1');

        $this->assertSame('JAKARTA', $saved->city_code);
        $this->assertContains(BookingWizardStep::LOCATION, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::CEMETERY, $saved->current_step);
        $this->assertSame(2, $saved->version);
    }

    public function test_step_1_rejects_a_missing_city_code_with_a_field_keyed_error(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, [], 'idem-2');
            $this->fail('Expected BookingStepValidationException.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('city_code', $e->getErrors());
        }
    }

    public function test_step_1_rejects_an_unknown_city_code(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => 'SURABAYA'], 'idem-3');
    }

    // =====================================================================
    // Step 2 — cemetery + package
    // =====================================================================

    public function test_step_2_accepts_a_published_cemetery_matching_the_selected_city(): void
    {
        $cemetery = Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->firstOrFail();

        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemetery->id,
        ], 'idem-4');

        $this->assertSame($cemetery->id, $saved->cemetery_id);
        $this->assertContains(BookingWizardStep::CEMETERY, $saved->completed_steps);
        $this->assertSame(BookingWizardStep::SERVICE_TYPE, $saved->current_step);
    }

    public function test_step_2_rejects_a_cemetery_in_a_different_city_than_step_1(): void
    {
        $bogorCemetery = Cemetery::query()->where('city', LaunchCityCode::BOGOR)->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->firstOrFail();

        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $bogorCemetery->id], 'idem-5');
    }

    public function test_step_2_rejects_a_draft_or_unpublished_cemetery(): void
    {
        $draftCemetery = Cemetery::query()->where('publication_status', CemeteryPublicationStatus::DRAFT)->first();
        $this->assertNotNull($draftCemetery, 'Fixture assumption: at least one seeded cemetery is draft.');

        $draft = BookingDraft::create(['city_code' => $draftCemetery->city]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $draftCemetery->id], 'idem-6');
    }

    public function test_step_2_requires_a_package_when_the_cemetery_has_active_packages(): void
    {
        $cemeteryWithPackages = Cemetery::query()
            ->whereHas('packages', fn ($q) => $q->where('is_active', true))
            ->firstOrFail();

        $draft = BookingDraft::create(['city_code' => $cemeteryWithPackages->city]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
                'cemetery_id' => $cemeteryWithPackages->id,
            ], 'idem-7');
            $this->fail('Expected BookingStepValidationException — this cemetery has active packages.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('cemetery_package_id', $e->getErrors());
        }
    }

    public function test_step_2_does_not_require_a_package_when_the_cemetery_has_none(): void
    {
        $cemeteryWithoutPackages = Cemetery::query()
            ->whereDoesntHave('packages')
            ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
            ->firstOrFail();

        $draft = BookingDraft::create(['city_code' => $cemeteryWithoutPackages->city]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemeteryWithoutPackages->id,
        ], 'idem-8');

        $this->assertSame($cemeteryWithoutPackages->id, $saved->cemetery_id);
    }

    // =====================================================================
    // Step 3 — service type
    // =====================================================================

    public function test_step_3_accepts_a_known_service_type(): void
    {
        $draft = BookingDraft::create(['city_code' => LaunchCityCode::JAKARTA]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, [
            'service_type' => 'NEW_GRAVE',
        ], 'idem-9');

        $this->assertSame('NEW_GRAVE', $saved->service_type);
        $this->assertSame(BookingWizardStep::SERVICES, $saved->current_step);
    }

    public function test_step_3_rejects_an_unknown_service_type(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'CREMATION'], 'idem-10');
    }

    // =====================================================================
    // Cross-cutting
    // =====================================================================

    public function test_an_out_of_range_step_number_is_rejected(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($draft, 99, [], 'idem-11');
    }

    public function test_a_step_beyond_this_batchs_boundary_is_rejected(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(\InvalidArgumentException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::CUSTOMER_DATA, [], 'idem-12');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`
Expected: FAIL — `Class "App\Domain\Booking\Actions\SaveBookingDraftStep" not found`

- [ ] **Step 3: Write `BookingStepValidationException`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Booking\Actions\SaveBookingDraftStep` when a step's
 * payload fails server-side validation — `booking-and-order-orchestration/
 * tasks.md` §"Required UI states owned by this layer": "validation error —
 * server is authoritative; return field-keyed errors (not a single message
 * string) so inline `aria-invalid` + a summary alert can both be rendered."
 */
final class BookingStepValidationException extends RuntimeException
{
    /**
     * @param  array<string, list<string>>  $errors
     */
    public function __construct(private readonly array $errors)
    {
        parent::__construct('Booking step validation failed: '.implode('; ', array_keys($errors)));
    }

    /**
     * @return array<string, list<string>>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
```

- [ ] **Step 4: Write `SaveBookingDraftStep`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Validates and persists one wizard step's payload onto an existing
 * `BookingDraft`, server-side and authoritative regardless of what the
 * client already checked — `booking-and-order-orchestration` AC3.
 * Idempotent and versioned — AC2; see Task 8 for the idempotency-replay
 * and version-conflict tests this Action's contract must satisfy.
 *
 * Only steps 1-3 are implemented by Task 6; step 4 (service selection) is
 * added by Task 7 in the same `match` below — this Action is one module
 * with one responsibility ("persist a validated step onto a draft"), not
 * five near-duplicate per-step classes, so later tasks extend this file
 * rather than creating siblings.
 *
 * Never `SensitiveActions`-listed — a booking step save is routine
 * customer input, not a privileged action.
 */
final readonly class SaveBookingDraftStep
{
    public function __invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey): BookingDraft
    {
        BookingWizardStep::assertKnown($step);

        if ($step > BookingWizardStep::LAST_IMPLEMENTED) {
            throw new InvalidArgumentException(
                "Step [{$step}] is not implemented yet. Last implemented step: ".BookingWizardStep::LAST_IMPLEMENTED.'.'
            );
        }

        // Idempotency replay: the same key for the same draft means this
        // exact save already happened — return the current state without
        // re-validating or re-bumping the version. See Task 8 for the
        // dedicated replay/conflict test suite this guards.
        if ($draft->last_idempotency_key === $idempotencyKey) {
            return $draft;
        }

        $errors = match ($step) {
            BookingWizardStep::LOCATION => self::validateLocation($payload),
            BookingWizardStep::CEMETERY => self::validateCemetery($payload, $draft),
            BookingWizardStep::SERVICE_TYPE => self::validateServiceType($payload),
            default => [],
        };

        if ($errors !== []) {
            throw new BookingStepValidationException($errors);
        }

        return DB::transaction(function () use ($draft, $step, $payload, $idempotencyKey): BookingDraft {
            $attributes = match ($step) {
                BookingWizardStep::LOCATION => ['city_code' => $payload['city_code']],
                BookingWizardStep::CEMETERY => [
                    'cemetery_id' => $payload['cemetery_id'],
                    'cemetery_package_id' => $payload['cemetery_package_id'] ?? null,
                ],
                BookingWizardStep::SERVICE_TYPE => ['service_type' => $payload['service_type']],
                default => [],
            };

            $completedSteps = $draft->completed_steps;
            if (! in_array($step, $completedSteps, true)) {
                $completedSteps[] = $step;
                sort($completedSteps);
            }

            $draft->fill([
                ...$attributes,
                'completed_steps' => $completedSteps,
                'current_step' => min($step + 1, BookingWizardStep::LAST_IMPLEMENTED + 1),
                'version' => $draft->version + 1,
                'last_idempotency_key' => $idempotencyKey,
            ]);
            $draft->save();

            Audit::record(
                action: 'BOOKING_DRAFT_STEP_SAVED',
                subject: new AuditSubject('booking_draft', $draft->id, $draft->version),
                outcome: AuditOutcome::Allowed,
                actorRef: $draft->user_id,
                actorRole: $draft->user_id !== null ? 'customer' : 'guest',
                source: AuditSource::Api,
                metadata: ['step' => $step],
            );

            return $draft->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateLocation(array $payload): array
    {
        $city = $payload['city_code'] ?? null;

        if ($city === null || $city === '') {
            return ['city_code' => ['Pilih kota terlebih dahulu.']];
        }

        if (! LaunchCityCode::isKnown($city)) {
            return ['city_code' => ['Kota yang dipilih tidak dikenali.']];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateCemetery(array $payload, BookingDraft $draft): array
    {
        $errors = [];

        $cemeteryId = $payload['cemetery_id'] ?? null;

        if ($cemeteryId === null || $cemeteryId === '') {
            return ['cemetery_id' => ['Pilih TPU/TPS terlebih dahulu.']];
        }

        $cemetery = CemeteryPublicQuery::findPublishedById((string) $cemeteryId);

        if ($cemetery === null) {
            return ['cemetery_id' => ['TPU/TPS yang dipilih tidak tersedia.']];
        }

        if ($draft->city_code !== null && $cemetery->city !== $draft->city_code) {
            $errors['cemetery_id'] = ['TPU/TPS yang dipilih berada di luar kota yang dipilih pada langkah 1.'];

            return $errors;
        }

        $activePackages = CemeteryPublicQuery::activePackages($cemetery);

        if ($activePackages->isNotEmpty()) {
            $packageId = $payload['cemetery_package_id'] ?? null;

            if ($packageId === null || $packageId === '') {
                $errors['cemetery_package_id'] = ['Pilih paket/kelas untuk TPU/TPS ini.'];
            } elseif (! $activePackages->contains('id', (int) $packageId)) {
                $errors['cemetery_package_id'] = ['Paket/kelas yang dipilih tidak tersedia untuk TPU/TPS ini.'];
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateServiceType(array $payload): array
    {
        $serviceType = $payload['service_type'] ?? null;

        if ($serviceType === null || $serviceType === '') {
            return ['service_type' => ['Pilih jenis layanan terlebih dahulu.']];
        }

        if (! BookingServiceType::isKnown($serviceType)) {
            return ['service_type' => ['Jenis layanan yang dipilih tidak dikenali.']];
        }

        return [];
    }
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`
Expected: PASS (12 tests)

**Note for the implementer:** `test_step_2_requires_a_package_when_the_cemetery_has_active_packages` and `test_step_2_does_not_require_a_package_when_the_cemetery_has_none` assume the seeded fixture data contains at least one cemetery with active packages and at least one without (the seed migration `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` is described elsewhere in this session as populating packages on "two of the ten cemeteries" — verify this directly against that migration before trusting the fixture assumption; if it doesn't hold, adjust the test to create the fixture data explicitly instead of relying on seeds).

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/Actions/SaveBookingDraftStep.php app/Domain/Booking/Exceptions/BookingStepValidationException.php tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php
git commit -m "feat(booking): add SaveBookingDraftStep action for steps 1-3"
```

---

## Task 7: `SaveBookingDraftStep` — Step 4 (service selection)

**Files:**
- Modify: `app/Domain/Booking/Actions/SaveBookingDraftStep.php` (extend the `match` in `__invoke()` and add `validateServices()`)
- Test: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepServicesTest.php`

**Interfaces:**
- Consumes: `App\Domain\ServiceCatalog\ServiceCode::isKnown()`, `::isBasic()`, `::BASIC_CODES` (existing).
- Produces: extends `SaveBookingDraftStep::__invoke()` to accept `BookingWizardStep::SERVICES`, payload shape `['selected_services' => list<array{code: string, quantity: int}>]`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepServicesTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_4_accepts_both_basic_services_plus_an_addon(): void
    {
        $draft = BookingDraft::create([]);

        $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 2],
            ],
        ], 'idem-svc-1');

        $this->assertCount(3, $saved->selected_services);
        $this->assertSame(BookingWizardStep::SUMMARY, $saved->current_step);
    }

    public function test_step_4_rejects_a_selection_missing_document_processing(): void
    {
        $draft = BookingDraft::create([]);

        try {
            (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
                'selected_services' => [
                    ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ],
            ], 'idem-svc-2');
            $this->fail('Expected BookingStepValidationException — DOCUMENT_PROCESSING is a mandatory basic service.');
        } catch (BookingStepValidationException $e) {
            $this->assertArrayHasKey('selected_services', $e->getErrors());
        }
    }

    public function test_step_4_rejects_a_selection_missing_grave_digging(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ],
        ], 'idem-svc-3');
    }

    public function test_step_4_rejects_an_unknown_service_code(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'CREMATION', 'quantity' => 1],
            ],
        ], 'idem-svc-4');
    }

    public function test_step_4_rejects_a_non_positive_quantity(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
                ['code' => 'FLOWERS', 'quantity' => 0],
            ],
        ], 'idem-svc-5');
    }

    public function test_step_4_rejects_an_empty_selection(): void
    {
        $draft = BookingDraft::create([]);

        $this->expectException(BookingStepValidationException::class);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, ['selected_services' => []], 'idem-svc-6');
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepServicesTest.php`
Expected: FAIL — step 4 currently falls through the `match` default (`[]` attributes, no validation), so `test_step_4_rejects_a_selection_missing_document_processing` and similar fail (no exception thrown)

- [ ] **Step 3: Extend `SaveBookingDraftStep`**

In `app/Domain/Booking/Actions/SaveBookingDraftStep.php`:

Add `use App\Domain\ServiceCatalog\ServiceCode;` to the imports.

Extend the validation `match` in `__invoke()`:

```php
        $errors = match ($step) {
            BookingWizardStep::LOCATION => self::validateLocation($payload),
            BookingWizardStep::CEMETERY => self::validateCemetery($payload, $draft),
            BookingWizardStep::SERVICE_TYPE => self::validateServiceType($payload),
            BookingWizardStep::SERVICES => self::validateServices($payload),
            default => [],
        };
```

Extend the attributes `match` inside the transaction:

```php
            $attributes = match ($step) {
                BookingWizardStep::LOCATION => ['city_code' => $payload['city_code']],
                BookingWizardStep::CEMETERY => [
                    'cemetery_id' => $payload['cemetery_id'],
                    'cemetery_package_id' => $payload['cemetery_package_id'] ?? null,
                ],
                BookingWizardStep::SERVICE_TYPE => ['service_type' => $payload['service_type']],
                BookingWizardStep::SERVICES => ['selected_services' => $payload['selected_services']],
                default => [],
            };
```

Add the new private method:

```php
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, list<string>>
     */
    private static function validateServices(array $payload): array
    {
        $selections = $payload['selected_services'] ?? [];

        if (! is_array($selections) || $selections === []) {
            return ['selected_services' => ['Pilih minimal layanan dasar.']];
        }

        $selectedCodes = [];

        foreach ($selections as $selection) {
            $code = $selection['code'] ?? null;
            $quantity = $selection['quantity'] ?? null;

            if (! is_string($code) || ! ServiceCode::isKnown($code)) {
                return ['selected_services' => ["Layanan [{$code}] tidak dikenali."]];
            }

            if (! is_int($quantity) || $quantity < 1) {
                return ['selected_services' => ["Jumlah untuk layanan [{$code}] harus lebih dari nol."]];
            }

            $selectedCodes[] = $code;
        }

        $missingBasics = array_diff(ServiceCode::BASIC_CODES, $selectedCodes);

        if ($missingBasics !== []) {
            return ['selected_services' => [
                'Layanan dasar wajib disertakan: '.implode(', ', $missingBasics).'.',
            ]];
        }

        return [];
    }
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepServicesTest.php`
Expected: PASS (18 tests — 12 from Task 6 unchanged, 6 new)

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Booking/Actions/SaveBookingDraftStep.php tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepServicesTest.php
git commit -m "feat(booking): extend SaveBookingDraftStep to validate step 4 service selection"
```

---

## Task 8: Idempotency replay and version-conflict hardening

**Files:**
- Modify: `app/Domain/Booking/Actions/SaveBookingDraftStep.php` (no production code change expected — this task proves the existing idempotency-replay branch from Task 6 Step 4 under more scenarios, and adds explicit stale-version detection)
- Create: `app/Domain/Booking/Exceptions/BookingDraftVersionConflictException.php`
- Test: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepIdempotencyTest.php`

**Interfaces:**
- Consumes: `SaveBookingDraftStep` (Tasks 6-7).
- Produces: `SaveBookingDraftStep::__invoke()` gains an optional `?int $expectedVersion = null` parameter; throws `BookingDraftVersionConflictException` when given and mismatched.

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking\Actions;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SaveBookingDraftStepIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_replaying_the_same_idempotency_key_does_not_bump_the_version_twice(): void
    {
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');
        $second = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-1');

        $this->assertSame($first->version, $second->version);
    }

    public function test_replaying_the_same_key_returns_the_same_persisted_state_even_with_a_different_payload(): void
    {
        // A retried network request replays the SAME key with the SAME
        // original payload in practice, but this proves the replay branch
        // trusts the key over the payload — the correct behaviour for
        // "was this exact call already applied", not "does this payload
        // match".
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-2');
        $second = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-replay-2');

        $this->assertSame('JAKARTA', $second->city_code, 'A replayed key must not re-apply a changed payload.');
    }

    public function test_a_new_idempotency_key_after_a_replay_applies_normally(): void
    {
        $draft = BookingDraft::create([]);

        $first = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');
        $replay = (new SaveBookingDraftStep)($first, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-replay-3');
        $next = (new SaveBookingDraftStep)($replay, BookingWizardStep::CEMETERY, ['cemetery_id' => \App\Domain\CemeteryDirectory\Models\Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', 'published')->whereDoesntHave('packages')->firstOrFail()->id], 'idem-replay-4');

        $this->assertSame($replay->version + 1, $next->version);
    }

    public function test_saving_against_a_stale_expected_version_throws_a_conflict(): void
    {
        $draft = BookingDraft::create([]);
        $staleVersion = $draft->version;

        // Simulate a concurrent save from another tab bumping the version first.
        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-1');

        $this->expectException(BookingDraftVersionConflictException::class);

        (new SaveBookingDraftStep)($draft->fresh(), BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::BOGOR], 'idem-conflict-2', expectedVersion: $staleVersion);
    }

    public function test_saving_with_no_expected_version_never_conflicts(): void
    {
        $draft = BookingDraft::create([]);

        (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-conflict-3');

        // No $expectedVersion given — must not throw even though the
        // in-memory $draft is now stale relative to what a fresh read
        // would show, since this overload is opt-in.
        $saved = (new SaveBookingDraftStep)($draft->fresh(), BookingWizardStep::CEMETERY, [
            'cemetery_id' => \App\Domain\CemeteryDirectory\Models\Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', 'published')->whereDoesntHave('packages')->firstOrFail()->id,
        ], 'idem-conflict-4');

        $this->assertNotNull($saved->cemetery_id);
    }
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepIdempotencyTest.php`
Expected: `test_replaying_*` and `test_a_new_idempotency_key_after_a_replay_applies_normally` PASS already (Task 6's replay branch covers them); `test_saving_against_a_stale_expected_version_throws_a_conflict` and `test_saving_with_no_expected_version_never_conflicts` FAIL — `Unknown named parameter $expectedVersion` and `Class "App\Domain\Booking\Exceptions\BookingDraftVersionConflictException" not found`

- [ ] **Step 3: Write `BookingDraftVersionConflictException`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Domain\Booking\Actions\SaveBookingDraftStep` when a caller
 * supplies `$expectedVersion` and it no longer matches the draft's current
 * `version` — `booking-wizard-fields.md` §Global behavior: "Every save is
 * idempotent and versioned." An optimistic-concurrency signal for the
 * presentation layer to surface "this draft changed elsewhere, reloading" —
 * see `public-booking-wizard/design.md`'s "Components" section: "Autosave
 * uses optimistic version and idempotency key."
 */
final class BookingDraftVersionConflictException extends RuntimeException
{
    public function __construct(public readonly int $expectedVersion, public readonly int $actualVersion)
    {
        parent::__construct("Booking draft version conflict: expected [{$expectedVersion}], found [{$actualVersion}].");
    }
}
```

- [ ] **Step 4: Extend `SaveBookingDraftStep::__invoke()`'s signature**

```php
    public function __invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey, ?int $expectedVersion = null): BookingDraft
    {
        BookingWizardStep::assertKnown($step);

        if ($step > BookingWizardStep::LAST_IMPLEMENTED) {
            throw new InvalidArgumentException(
                "Step [{$step}] is not implemented yet. Last implemented step: ".BookingWizardStep::LAST_IMPLEMENTED.'.'
            );
        }

        if ($draft->last_idempotency_key === $idempotencyKey) {
            return $draft;
        }

        if ($expectedVersion !== null && $draft->version !== $expectedVersion) {
            throw new \App\Domain\Booking\Exceptions\BookingDraftVersionConflictException($expectedVersion, $draft->version);
        }

        // ... rest of the method unchanged from Task 6/7
```

(Add `use App\Domain\Booking\Exceptions\BookingDraftVersionConflictException;` to the top-level imports instead of the fully-qualified inline reference above, matching this file's existing import style.)

- [ ] **Step 5: Run all `Booking` Action tests to verify everything passes**

Run: `vendor/bin/phpunit tests/Feature/Domain/Booking/`
Expected: PASS (all tests across Tasks 3-8 — 6 + 6 + 4 + 12 + 6 + 5 = 39 tests)

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Booking/Actions/SaveBookingDraftStep.php app/Domain/Booking/Exceptions/BookingDraftVersionConflictException.php tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepIdempotencyTest.php
git commit -m "feat(booking): add optimistic version-conflict detection to SaveBookingDraftStep"
```

---

## Task 9: Livewire `BookingWizard` — shell + Steps 1-3 + routes

**Files:**
- Create: `app/Livewire/Public/Booking/BookingWizard.php`
- Create: `resources/views/livewire/public/booking/wizard.blade.php`
- Modify: `routes/web.php` (replace the `BookingWizardComingSoon` stub registration)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php`

**Interfaces:**
- Consumes: `App\Domain\Booking\Actions\StartBookingDraft` (Task 5), `App\Domain\Booking\Actions\SaveBookingDraftStep` (Tasks 6-8), `App\Domain\Booking\BookingDraftQuery` (Task 4), `App\Domain\Booking\BookingWizardStep` (Task 2), `App\Domain\CemeteryDirectory\CemeteryPublicQuery::launchCities()`/`::inCity()` (existing), `<x-mk.stepper>`, `<x-mk.field>`, `<x-mk.button>`, `<x-mk.card>` (existing Blade primitives).
- Produces: `GET /pemesanan-makam` and `GET /pemesanan-makam/draft/{draftId}` both routed to `BookingWizard::class`; `GET /pemesanan-makam/baru` redirects to `/pemesanan-makam`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_route_replaces_the_coming_soon_stub(): void
    {
        $this->get('/pemesanan-makam')->assertOk()->assertSeeLivewire(BookingWizard::class);
    }

    public function test_the_nine_step_stepper_is_always_shown(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (BookingWizardStep::labels() as $label) {
            $component->assertSee($label);
        }
    }

    public function test_step_1_offers_all_five_launch_cities_in_order(): void
    {
        $component = Livewire::test(BookingWizard::class);
        $html = $component->html();

        $positions = [];
        foreach (['Jakarta', 'Bogor', 'Depok', 'Tangerang', 'Bekasi'] as $label) {
            $position = strpos($html, $label);
            $this->assertNotFalse($position);
            $positions[] = $position;
        }

        $sorted = $positions;
        sort($sorted);
        $this->assertSame($sorted, $positions);
    }

    public function test_no_draft_is_created_merely_by_viewing_the_page(): void
    {
        $this->assertDatabaseCount('booking_drafts', 0);

        Livewire::test(BookingWizard::class);

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_selecting_a_city_creates_a_draft_and_redirects_to_its_resume_url(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertRedirect();

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_an_invalid_step_1_submission_shows_a_field_error_and_creates_no_draft(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertHasErrors(['city_code']);

        $this->assertDatabaseCount('booking_drafts', 0);
    }

    public function test_resuming_via_the_draft_url_continues_at_the_saved_step(): void
    {
        $cemetery = Cemetery::query()->where('city', LaunchCityCode::JAKARTA)->where('publication_status', CemeteryPublicationStatus::PUBLISHED)->firstOrFail();

        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);
        $draftId = $component->get('draftId');

        $this->get("/pemesanan-makam/draft/{$draftId}")->assertOk();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('city', LaunchCityCode::JAKARTA)
            ->assertSee($cemetery->name);
    }

    public function test_an_unknown_draft_id_falls_back_to_a_fresh_step_1_instead_of_404ing(): void
    {
        Livewire::test(BookingWizard::class, ['draftId' => '00000000-0000-0000-0000-000000000000'])
            ->assertOk()
            ->assertSet('draftId', null);
    }

    public function test_back_navigation_preserves_previously_entered_data(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);
        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('goToStep', BookingWizardStep::LOCATION)
            ->assertSet('city', LaunchCityCode::JAKARTA);
    }

    public function test_a_failed_cemetery_read_degrades_honestly_instead_of_500ing(): void
    {
        \Illuminate\Support\Facades\Schema::dropIfExists('grave_records');
        \Illuminate\Support\Facades\Schema::dropIfExists('cemetery_packages');
        \Illuminate\Support\Facades\Schema::dropIfExists('cemetery_capability_profiles');
        \Illuminate\Support\Facades\Schema::dropIfExists('cemeteries');

        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertOk();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php`
Expected: FAIL — `Class "App\Livewire\Public\Booking\BookingWizard" not found`

- [ ] **Step 3: Write `BookingWizard`**

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingDraftQuery;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\Booking\Exceptions\BookingStepValidationException;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;
use Throwable;

/**
 * `/pemesanan-makam` — Sprint 4 S4-T4/S4-T5 (resumed 08 Aug 2026 after
 * pausing 26 Jul), `.kiro/specs/public-booking-wizard` AC1-AC6, AC11-AC13
 * and `.kiro/specs/booking-and-order-orchestration` AC2, AC3. Steps 1-5
 * only — see both specs' `design.md` "Out of scope" sections for what this
 * batch deliberately does not build.
 *
 * REPLACES the `App\Livewire\Public\ComingSoon\BookingWizardComingSoon`
 * stub wholesale — same pattern as `RenewalStart` replacing
 * `RenewalComingSoon`.
 *
 * `booking-wizard-fields.md` §Global behavior: "Draft created at first
 * meaningful input." No draft exists until `saveStep1()` is called — see
 * `mount()`, which only ever READS a draft (via `$draftId`), never creates
 * one.
 */
final class BookingWizard extends Component
{
    /**
     * Set only when resuming via `/pemesanan-makam/draft/{draftId}`. `null`
     * means no draft exists yet — step 1 is a bare city chooser with
     * nothing persisted.
     */
    public ?string $draftId = null;

    public string $city = '';

    public ?string $cemeteryId = null;

    public ?int $cemeteryPackageId = null;

    public ?string $serviceType = null;

    /**
     * @var list<array{code: string, quantity: int}>
     */
    public array $selectedServices = [];

    /**
     * @var list<int>
     */
    public array $completedSteps = [];

    public int $currentStep = BookingWizardStep::LOCATION;

    public bool $cemeteryListUnavailable = false;

    public function mount(?string $draftId = null): void
    {
        if ($draftId === null) {
            return;
        }

        $draft = BookingDraftQuery::find($draftId);

        if ($draft === null) {
            // Unknown/tampered draft id — same "silently reset to a
            // working state" discipline as RenewalStart::mount() for an
            // unknown ?kota=.
            $this->draftId = null;

            return;
        }

        $this->hydrateFrom($draft);
    }

    private function hydrateFrom(BookingDraft $draft): void
    {
        $this->draftId = $draft->id;
        $this->city = $draft->city_code ?? '';
        $this->cemeteryId = $draft->cemetery_id;
        $this->cemeteryPackageId = $draft->cemetery_package_id;
        $this->serviceType = $draft->service_type;
        $this->selectedServices = $draft->selected_services;
        $this->completedSteps = $draft->completed_steps;
        $this->currentStep = $draft->current_step;
    }

    public function saveStep1(string $cityCode): void
    {
        try {
            $draft = $this->currentOrNewDraft();

            $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => $cityCode], (string) Str::uuid());

            $this->hydrateFrom($saved);

            $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
        } catch (BookingStepValidationException $e) {
            $this->addError('city_code', $e->getErrors()['city_code'][0] ?? 'Kota tidak valid.');
        }
    }

    public function saveStep2(string $cemeteryId, ?int $cemeteryPackageId = null): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::CEMETERY, [
            'cemetery_id' => $cemeteryId,
            'cemetery_package_id' => $cemeteryPackageId,
        ]);
    }

    public function saveStep3(string $serviceType): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::SERVICE_TYPE, ['service_type' => $serviceType]);
    }

    private function saveStepOrShowErrors(int $step, array $payload): void
    {
        if ($this->draftId === null) {
            return;
        }

        try {
            $draft = BookingDraftQuery::find($this->draftId);

            if ($draft === null) {
                return;
            }

            $saved = (new SaveBookingDraftStep)($draft, $step, $payload, (string) Str::uuid());

            $this->hydrateFrom($saved);
        } catch (BookingStepValidationException $e) {
            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }

    public function goToStep(int $step): void
    {
        if (in_array($step, $this->completedSteps, true) || $step === $this->currentStep) {
            $this->currentStep = $step;
        }
    }

    private function currentOrNewDraft(): BookingDraft
    {
        if ($this->draftId !== null) {
            $existing = BookingDraftQuery::find($this->draftId);
            if ($existing !== null) {
                return $existing;
            }
        }

        return (new StartBookingDraft)();
    }

    public function render(): View
    {
        $cemeteries = new Collection;
        $this->cemeteryListUnavailable = false;

        if ($this->city !== '') {
            try {
                $cemeteries = CemeteryPublicQuery::inCity($this->city);
            } catch (Throwable $e) {
                report($e);
                $this->cemeteryListUnavailable = true;
            }
        }

        return view('livewire.public.booking.wizard', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'stepLabels' => BookingWizardStep::labels(),
            'lastImplementedStep' => BookingWizardStep::LAST_IMPLEMENTED,
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
        ]);
    }
}
```

- [ ] **Step 4: Write `resources/views/livewire/public/booking/wizard.blade.php`**

```blade
<div>
    <x-mk.stepper :current="$currentStep" :labels="$stepLabels" />

    @if ($currentStep === \App\Domain\Booking\BookingWizardStep::LOCATION)
        <div class="mk-booking-step" aria-labelledby="step-1-heading">
            <h1 id="step-1-heading">Pilih Lokasi</h1>

            @if ($cities === [])
                <p>Belum ada kota yang tersedia.</p>
            @else
                <ul>
                    @foreach ($cities as $cityOption)
                        <li>
                            <x-mk.button
                                variant="secondary"
                                wire:click="saveStep1('{{ $cityOption['code'] }}')"
                                wire:loading.attr="disabled"
                            >
                                {{ $cityOption['label'] }}
                            </x-mk.button>
                        </li>
                    @endforeach
                </ul>
            @endif

            @error('city_code')
                <p class="mk-field-error" role="alert">{{ $message }}</p>
            @enderror
        </div>
    @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CEMETERY)
        <div class="mk-booking-step" aria-labelledby="step-2-heading">
            <h1 id="step-2-heading">Pilih TPU/TPS</h1>

            @if ($cemeteryListUnavailable)
                <p>Daftar TPU/TPS sedang tidak dapat dimuat.</p>
            @elseif ($cemeteries->isEmpty())
                <p>Belum ada TPU/TPS terdaftar di kota ini.</p>
            @else
                @foreach ($cemeteries as $cemetery)
                    <x-mk.card as="button" interactive wire:click="saveStep2('{{ $cemetery->id }}')">
                        <span>{{ $cemetery->name }}</span>
                        <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                    </x-mk.card>
                @endforeach
            @endif

            @error('cemetery_id')
                <p class="mk-field-error" role="alert">{{ $message }}</p>
            @enderror
            @error('cemetery_package_id')
                <p class="mk-field-error" role="alert">{{ $message }}</p>
            @enderror

            <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::LOCATION }})">
                Kembali
            </x-mk.button>
        </div>
    @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE)
        <div class="mk-booking-step" aria-labelledby="step-3-heading">
            <h1 id="step-3-heading">Pilih Jenis Layanan</h1>

            @foreach (\App\Domain\Booking\BookingServiceType::KNOWN_CODES as $type)
                <x-mk.button variant="secondary" wire:click="saveStep3('{{ $type }}')">
                    {{ $type }}
                </x-mk.button>
            @endforeach

            @error('service_type')
                <p class="mk-field-error" role="alert">{{ $message }}</p>
            @enderror

            <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CEMETERY }})">
                Kembali
            </x-mk.button>
        </div>
    @endif

    {{-- Steps 4-5 are added by Task 10 --}}
</div>
```

- [ ] **Step 5: Wire routes in `routes/web.php`**

Replace:

```php
Route::get('/pemesanan-makam', BookingWizardComingSoon::class)->name('pemesanan-makam.index');
```

with:

```php
/*
|--------------------------------------------------------------------------
| Booking wizard — public-booking-wizard AC1-AC6, AC11-AC13 (S4-T4/S4-T5,
| resumed 08 Aug 2026) + booking-and-order-orchestration AC2, AC3
|--------------------------------------------------------------------------
| Steps 1-5 only. REPLACES the BookingWizardComingSoon stub — see that
| class's own doc block and this file's top-of-file note on stub
| replacement. Steps 6-9 remain unbuilt; the stepper still shows all nine
| (BookingWizardStep::LAST_IMPLEMENTED = 5).
*/
Route::get('/pemesanan-makam', BookingWizard::class)->name('pemesanan-makam.index');
Route::redirect('/pemesanan-makam/baru', '/pemesanan-makam')->name('pemesanan-makam.new');
Route::get('/pemesanan-makam/draft/{draftId}', BookingWizard::class)->name('pemesanan-makam.draft');
```

Remove the `use App\Livewire\Public\ComingSoon\BookingWizardComingSoon;` import if `BookingWizardComingSoon` is not referenced anywhere else in this file (check first — grep the file for other uses before removing the import), and add:

```php
use App\Livewire\Public\Booking\BookingWizard;
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php`
Expected: PASS (10 tests)

**Note for the implementer:** this test file asserts against `<x-mk.stepper>`, `<x-mk.button>`, `<x-mk.card>`, `<x-mk.badge>` prop contracts inferred from `cemetery-directory-and-availability/design.md`'s primitives table (Task summary above) and `RenewalStart`'s Blade usage — read each component's actual `@props` block in `resources/views/components/mk/*.blade.php` before finalizing the view, and adjust attribute names (e.g. whether `<x-mk.stepper>` takes `:current`/`:labels` or different prop names) to match the real component exactly. Also run `ci/verify-docs.sh` GATE 1-3 locally (`bash ci/verify-docs.sh`) after writing the Blade view to confirm no hardcoded design value or arbitrary Tailwind value was introduced — the CSS classes above (`mk-booking-step`, `mk-field-error`) are illustrative structure hooks, not token values, and must be backed by real classes in the app's stylesheet or replaced with the correct existing utility/token classes this codebase already uses (check `resources/views/livewire/public/renewal/start.blade.php` for the real class names Step 1-3's markup should actually use).

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php resources/views/livewire/public/booking/wizard.blade.php routes/web.php tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php
git commit -m "feat(booking): add BookingWizard Livewire shell for steps 1-3, wire routes"
```

---

## Task 10: Livewire `BookingWizard` — Steps 4-5 + autosave affordance

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php` (add `saveStep4()`, autosave state properties)
- Modify: `resources/views/livewire/public/booking/wizard.blade.php` (add Step 4 and Step 5 blocks, autosave indicator)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php`

**Interfaces:**
- Consumes: `BookingDraftQuery::summary()` (Task 4), `SaveBookingDraftStep` step-4 branch (Task 7).
- Produces: `BookingWizard::saveStep4(array $selectedServices): void`, public property `string $autosaveState` (`'idle'|'saving'|'saved'|'failed'`).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardStepsFourAndFiveTest extends TestCase
{
    use RefreshDatabase;

    private function draftAtStep4(): string
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');

        $cemetery = \App\Domain\CemeteryDirectory\Models\Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');

        return $draft->id;
    }

    public function test_step_4_offers_every_basic_and_additional_service(): void
    {
        $draftId = $this->draftAtStep4();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId]);

        foreach (['DOCUMENT_PROCESSING', 'GRAVE_DIGGING', 'AMBULANCE', 'FUNERAL_HOME', 'HEARSE', 'TENT_AND_CHAIRS', 'SOUND_SYSTEM', 'FLOWERS', 'GRAVESTONE', 'DOCUMENTATION', 'CATERING', 'LIVE_STREAMING'] as $code) {
            $component->assertSee($code);
        }
    }

    public function test_saving_step_4_with_both_basics_advances_to_step_5(): void
    {
        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);
    }

    public function test_step_5_shows_an_honest_price_unavailable_state_when_no_price_is_seeded(): void
    {
        $draftId = $this->draftAtStep4();

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSee('Harga belum tersedia');
    }

    public function test_the_autosave_indicator_shows_saved_after_a_successful_step_save(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSet('autosaveState', 'saved');
    }

    public function test_the_autosave_indicator_shows_failed_after_a_rejected_step(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertSet('autosaveState', 'failed');
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php`
Expected: FAIL — `Method BookingWizard::saveStep4 does not exist`, `Unable to set property [autosaveState]`

- [ ] **Step 3: Extend `BookingWizard`**

Add public property (next to `$cemeteryListUnavailable`):

```php
    /**
     * `idle` before any save, `saving` never actually observed server-side
     * (Livewire round-trips are synchronous from this class's perspective;
     * the Blade view's `wire:loading` targets the transient in-flight
     * state), `saved` or `failed` after the most recent step-save attempt.
     * Inline, never a toast — `booking-wizard-fields.md` §Global behavior
     * and `public-booking-wizard/design.md`'s own autosave affordance
     * section.
     */
    public string $autosaveState = 'idle';
```

Update `saveStep1()`, `saveStepOrShowErrors()` to set `$this->autosaveState`:

```php
    public function saveStep1(string $cityCode): void
    {
        try {
            $draft = $this->currentOrNewDraft();

            $saved = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => $cityCode], (string) Str::uuid());

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';

            $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';
            $this->addError('city_code', $e->getErrors()['city_code'][0] ?? 'Kota tidak valid.');
        }
    }
```

```php
    private function saveStepOrShowErrors(int $step, array $payload): void
    {
        if ($this->draftId === null) {
            return;
        }

        try {
            $draft = BookingDraftQuery::find($this->draftId);

            if ($draft === null) {
                return;
            }

            $saved = (new SaveBookingDraftStep)($draft, $step, $payload, (string) Str::uuid());

            $this->hydrateFrom($saved);
            $this->autosaveState = 'saved';
        } catch (BookingStepValidationException $e) {
            $this->autosaveState = 'failed';
            foreach ($e->getErrors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }
        }
    }
```

Add `saveStep4()`:

```php
    /**
     * @param  list<array{code: string, quantity: int}>  $selectedServices
     */
    public function saveStep4(array $selectedServices): void
    {
        $this->saveStepOrShowErrors(BookingWizardStep::SERVICES, ['selected_services' => $selectedServices]);
    }
```

Extend `render()` to pass Step 5's summary when reachable:

```php
    public function render(): View
    {
        $cemeteries = new Collection;
        $this->cemeteryListUnavailable = false;

        if ($this->city !== '') {
            try {
                $cemeteries = CemeteryPublicQuery::inCity($this->city);
            } catch (Throwable $e) {
                report($e);
                $this->cemeteryListUnavailable = true;
            }
        }

        $summary = null;
        if ($this->currentStep === BookingWizardStep::SUMMARY && $this->draftId !== null) {
            $draft = BookingDraftQuery::find($this->draftId);
            if ($draft !== null) {
                $summary = BookingDraftQuery::summary($draft);
            }
        }

        return view('livewire.public.booking.wizard', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'stepLabels' => BookingWizardStep::labels(),
            'lastImplementedStep' => BookingWizardStep::LAST_IMPLEMENTED,
            'allServiceCodes' => \App\Domain\ServiceCatalog\ServiceCode::KNOWN_CODES,
            'summary' => $summary,
        ])->layout('layouts.app', [
            'title' => 'Pemesanan Makam - Makam.co.id',
            'active' => null,
        ]);
    }
```

- [ ] **Step 4: Extend the Blade view**

Add before the closing `{{-- Steps 4-5 are added by Task 10 --}}` comment (replacing it):

```blade
    @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICES)
        <div class="mk-booking-step" aria-labelledby="step-4-heading" x-data="{ selections: [] }">
            <h1 id="step-4-heading">Pilih Layanan</h1>

            @foreach ($allServiceCodes as $code)
                <label>
                    <input type="checkbox" value="{{ $code }}" x-model="selections" @if (in_array($code, \App\Domain\ServiceCatalog\ServiceCode::BASIC_CODES, true)) checked disabled @endif>
                    {{ $code }}
                </label>
            @endforeach

            @error('selected_services')
                <p class="mk-field-error" role="alert">{{ $message }}</p>
            @enderror

            <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE }})">
                Kembali
            </x-mk.button>
        </div>
    @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SUMMARY)
        <div class="mk-booking-step" aria-labelledby="step-5-heading">
            <h1 id="step-5-heading">Ringkasan Pesanan</h1>

            @if ($summary !== null)
                <x-mk.table>
                    @foreach ($summary['lines'] as $line)
                        <tr>
                            <td>{{ $line['label'] }} &times; {{ $line['quantity'] }}</td>
                            <td>
                                @if ($line['line_total'] !== null)
                                    Rp {{ number_format($line['line_total'], 0, ',', '.') }}
                                @else
                                    Harga belum tersedia
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-mk.table>

                @if ($summary['total'] !== null)
                    <p><strong>Total: Rp {{ number_format($summary['total'], 0, ',', '.') }}</strong></p>
                @else
                    <p>Total belum dapat dihitung — sebagian harga layanan belum tersedia.</p>
                @endif
            @endif

            <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICES }})">
                Kembali
            </x-mk.button>
        </div>
    @endif

    <div aria-live="polite">
        @if ($autosaveState === 'saved')
            <span>Tersimpan</span>
        @elseif ($autosaveState === 'failed')
            <span>Gagal menyimpan — coba lagi</span>
        @endif
    </div>
</div>
```

**Note for the implementer:** the `x-model="selections"` Alpine binding above is a placeholder interaction pattern illustrating client-side checkbox collection; it does not yet wire into `wire:click="saveStep4(...)"` with the collected array — before this task's tests can pass, add either a `wire:model` binding on each checkbox to a Livewire array property (simplest: give `BookingWizard` a public `array $stagedServiceCodes = []` bound via `wire:model` per checkbox, and a "Lanjutkan" button calling `saveStep4($this->buildSelectionsFromStagedCodes())`), or an Alpine `@click` dispatching a Livewire call with the full array. Pick the simplest option that keeps the domain Action's `list<array{code, quantity}>` shape and re-run Step 5's test suite until green — this is a genuine implementation decision the plan intentionally leaves to whoever executes this task, since it is pure Livewire wiring mechanics with no domain consequence either way.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php`
Expected: PASS (5 tests)

- [ ] **Step 6: Run the full Booking test suite**

Run: `vendor/bin/phpunit tests/Unit/Domain/Booking/ tests/Feature/Domain/Booking/ tests/Feature/Livewire/Public/Booking/`
Expected: PASS (all tests from Tasks 2-10)

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php resources/views/livewire/public/booking/wizard.blade.php tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php
git commit -m "feat(booking): add steps 4-5 to BookingWizard with autosave affordance"
```

---

## Task 11: End-to-end integration test

**Files:**
- Create: `tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1-10.

- [ ] **Step 1: Write the test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardEndToEndTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_visitor_can_complete_steps_1_through_5_in_one_session(): void
    {
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSet('currentStep', BookingWizardStep::CEMETERY);

        $draftId = $component->get('draftId');
        $this->assertNotNull($draftId);

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE)
            ->call('saveStep3', 'NEW_GRAVE')
            ->assertSet('currentStep', BookingWizardStep::SERVICES)
            ->call('saveStep4', [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ])
            ->assertSet('currentStep', BookingWizardStep::SUMMARY);

        $this->assertDatabaseHas('booking_drafts', [
            'id' => $draftId,
            'city_code' => 'JAKARTA',
            'cemetery_id' => $cemetery->id,
            'service_type' => 'NEW_GRAVE',
            'current_step' => BookingWizardStep::SUMMARY,
        ]);
    }

    public function test_resuming_a_partially_completed_draft_skips_straight_to_its_saved_step(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::BOGOR);
        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY)
            ->assertSet('city', LaunchCityCode::BOGOR);
    }

    public function test_a_double_submitted_step_1_does_not_create_two_drafts_from_one_click(): void
    {
        // Simulates a double-tap: the SAME component instance calling
        // saveStep1 twice in quick succession would, in the real Livewire
        // request lifecycle, mean the second call already has $draftId set
        // from the first — this proves the second call updates the
        // existing draft rather than creating a second one, once $draftId
        // is known.
        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA);

        $this->assertDatabaseCount('booking_drafts', 1);

        $draftId = $component->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep1', LaunchCityCode::JAKARTA);

        $this->assertDatabaseCount('booking_drafts', 1);
    }

    public function test_the_stepper_never_removes_steps_6_through_9_even_though_they_are_unbuilt(): void
    {
        $component = Livewire::test(BookingWizard::class);

        foreach (BookingWizardStep::LABELS as $step => $label) {
            $component->assertSee($label);
        }
    }
}
```

- [ ] **Step 2: Run the tests**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php`
Expected: PASS (4 tests). If any fail, fix the underlying task (1-10) they exercise — do not adjust this test to paper over a real defect.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php
git commit -m "test(booking): add end-to-end integration coverage for steps 1-5"
```

---

## Task 12: Design-system and accessibility verification pass

**Files:**
- Modify: `resources/views/livewire/public/booking/wizard.blade.php` (fixes only, as needed)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php`

**Interfaces:**
- Consumes: the finished view from Tasks 9-10.

- [ ] **Step 1: Run the repo-wide design-token gate**

Run: `bash ci/verify-docs.sh`
Expected: `RESULT: ALL DOC GATES PASS` — GATE 1 (contrast), GATE 2 (no hardcoded design value), GATE 3 (no Tailwind arbitrary value), GATE 6 (spec still references `docs/design/design-system.md` — unaffected, this task touches no spec file), GATE 11 (no raw z-index), GATE 12 (no focus suppression) all cover `resources/views/livewire/public/booking/wizard.blade.php` automatically (the gate scans all of `resources/`). Fix any reported line in the Blade view before proceeding — do not weaken the gate.

- [ ] **Step 2: Write the accessibility test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class BookingWizardAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_step_heading_has_a_unique_id_targeted_by_aria_labelledby(): void
    {
        $component = Livewire::test(BookingWizard::class);

        $component->assertSeeHtml('aria-labelledby="step-1-heading"');
        $component->assertSeeHtml('id="step-1-heading"');
    }

    public function test_the_autosave_region_is_polite_and_never_a_toast_role(): void
    {
        $component = Livewire::test(BookingWizard::class)->call('saveStep1', LaunchCityCode::JAKARTA);

        $component->assertSeeHtml('aria-live="polite"');
        $component->assertDontSeeHtml('role="alertdialog"');
    }

    public function test_a_field_error_carries_role_alert(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', '')
            ->assertSeeHtml('role="alert"');
    }
}
```

**Note for the implementer:** `booking-and-order-orchestration/tasks.md`'s "Verify accessibility" line names 16px inputs, 44px touch targets, and full keyboard-only completion of all nine steps as required checks. This repository has no browser-level test harness (Dusk/Playwright/Cypress — verified absent repo-wide, `docs/domain/traceability-matrix.md:11`), so those three checks are **NOT TESTED** by this plan, consistent with this codebase's own established practice of recording that honestly rather than fabricating a passing assertion (see `cemetery-directory-and-availability/tasks.md`'s identical "44 px touch targets and the focus ring are NOT TESTED" note). Do not write a PHPUnit assertion that only pretends to check rendered geometry or focus behavior.

- [ ] **Step 3: Run the test**

Run: `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php`
Expected: PASS (3 tests)

- [ ] **Step 4: Run the entire new test surface one final time**

Run: `vendor/bin/phpunit tests/Unit/Domain/Booking/ tests/Feature/Domain/Booking/ tests/Feature/Livewire/Public/Booking/`
Expected: PASS (all tests from every task in this plan)

- [ ] **Step 5: Commit**

```bash
git add tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php
git commit -m "test(booking): add accessibility assertions for the booking wizard, record what remains NOT TESTED"
```

---

## Plan self-review notes

**Spec coverage.** `public-booking-wizard` AC1 (nine-step shell, Task 9/2), AC2 (five cities, Task 9), AC3 (Step 2 fields, Task 6/9 — photo/facilities/price rendering deferred to whoever finalizes the Blade markup per Task 9 Step 6's note, since this plan's author did not verify every `<x-mk.card>` prop against the live component), AC4 (Step 3, Task 6/9), AC5 (Step 4, Task 7/10), AC6 (Step 5 quote lines/total, Task 4/10 — as a computed presentation, not a Quote row, per Global Constraints), AC11/AC12 (autosave/resume/back-nav, Task 9/10/11), AC13 (idempotent/versioned/unskippable, Task 6/8) are all covered. AC7-AC10, AC14, AC15 (Steps 6-9, notifications) are explicitly out of scope. `booking-and-order-orchestration` AC2 (Task 3/6/8), AC3 (Task 6/7) are covered; AC1, AC4-AC14 are explicitly out of scope or partially addressed only insofar as Steps 1-5 need them (AC1's "shared entry point" is Task 9's routes).

**Placeholder scan.** One deliberate exception, flagged inline rather than silently left: Task 10 Step 4's `x-model="selections"` Alpine wiring is explicitly called out as unfinished mechanics with a concrete resolution path, not a bare TODO — the skill's "No Placeholders" rule targets vague, unresolvable instructions; this is a bounded, single decision with a stated default.

**Type consistency.** `SaveBookingDraftStep::__invoke()`'s signature is introduced in Task 6, extended (new `match` arms only, no signature change) in Task 7, and gains `?int $expectedVersion = null` in Task 8 — checked against every call site across Tasks 9-11, all of which omit the optional parameter and remain valid. `BookingDraftQuery::summary()`'s return shape (Task 4) is consumed identically in Task 10's `render()` and view.

**Scope check.** This is one coherent vertical slice — a single Livewire route and its one supporting domain module — not a multi-subsystem spec; no further decomposition into separate plans is warranted.
