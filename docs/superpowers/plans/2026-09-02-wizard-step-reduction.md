# Wizard Step Reduction Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce booking's true step count from 9 to 4 and renewal's from 6 to 3, keeping every field, validation rule, and `SaveBookingDraftStep`'s server-enforced sequencing intact for the steps that remain, with the stepper decoupled from the internal step count.

**Architecture:** Renumber `BookingWizardStep`/`RenewalJourneyStep` to the new smaller vocabularies; merge the Livewire save methods that governed the steps being combined into single validate/save calls; add two new small label-vocabulary classes (`BookingWizardScreen`, `RenewalWizardScreen`) so the stepper's `labels` prop — already a generic, count-derived extension point, no component change needed — reflects screens, not raw steps. Booking's largest merge (Lokasi+TPU/TPS+Jenis Layanan+Pilih Layanan into one `DISCOVERY` step) requires restructuring when the plot hold is created relative to when the draft is actually persisted, because holding a contended plot must stay an immediate, real action even though persisting the merged step is now deferred until the whole discovery flow is submitted.

**Tech Stack:** Laravel 13, Livewire 4, PHP 8.5 (pinned CI image `ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3` — host PHP is 8.3.6, too old), Postgres 18, Redis 8.2-alpine, PHPUnit 12.

**Spec:** `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- No change to `SaveBookingDraftStep`'s validation RULES themselves (only which step number/merged step they attach to), `GuardRenewalPaymentOpening`'s four conditions, `OpenRenewal`'s trigger, `PaymentMode`/`ModeResolver` gating, or any domain Action's business logic beyond step-numbering.
- Every existing field collected, in the order collected, stays identical — nothing dropped from either wizard.
- Real Postgres 18 (never SQLite) via the pinned CI image for every task's tests: `docker run --rm --network host --user 1000:1000 -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<port> -v /home/ubuntu/makam-app/.worktrees/wizard-step-reduction:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php -d memory_limit=1G vendor/bin/phpunit <path>`. Spin up disposable `postgres:18`/`redis:8.2-alpine` containers with unique names/ports per task, tear down when done. Never touch `makam-nonprod-*` containers.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` clean throughout — use `php -d memory_limit=1G` when invoking phpunit directly (`artisan test`'s subprocess wrapper does not inherit a parent `-d` flag, a lesson from the prior wizard-screen-consolidation branch).
- **Real-code finding that supersedes the spec's own framing:** the spec's Decision 6 ("in-flight drafts under the old step numbering are treated as unresumable") was written assuming BOTH wizards persist `current_step` to a database row. Booking does (`booking_drafts.current_step`, via `SaveBookingDraftStep`). **Renewal does not** — `RenewalStart::currentStep()`/`RenewalPayment::render()`'s `currentStep` key are both PURELY DERIVED from in-memory Livewire component state (`$this->city === '' ? CITY : ...`), never persisted. There is no `renewal_drafts` table and no `current_step` column anywhere in the renewal domain. Decision 6 therefore applies to booking only — Task 9 below is booking-only; no renewal equivalent exists to build.
- **Real-code finding:** the spec's Decision 5 claim that "Booking's stepper shows 4 dots (unchanged from the prior redesign — it was already screen-count-based in spirit)" is INCORRECT. The live `wizard.blade.php:72` reads `<x-mk.stepper :step="$currentStep" class="mb-8" />` with no `labels` override — this renders the stepper's full 9-item default TODAY, tracking the raw step number, not a screen. PR #218's screen consolidation changed which Blade block renders (`currentScreen()`-gated `@if` blocks) but never touched the stepper invocation itself. Task 6 fixes this for real, it is not "making something implicit literal."

---

### Task 1: Renumber `BookingWizardStep` to 4 steps, add `BookingWizardScreen`

**Files:**
- Modify: `app/Domain/Booking/BookingWizardStep.php` (currently `LOCATION=1` .. `CONFIRMATION=9`, `SUMMARY=5`)
- Create: `app/Domain/Booking/BookingWizardScreen.php`
- Test: `tests/Unit/Domain/Booking/BookingWizardStepTest.php` (existing — update)
- Test: `tests/Unit/Domain/Booking/BookingWizardScreenTest.php` (new)

**Interfaces:**
- Produces: `BookingWizardStep::DISCOVERY = 1`, `CUSTOMER_AND_DECEASED_DATA = 2`, `PAYMENT = 3`, `CONFIRMATION = 4`; `BookingWizardStep::LAST_IMPLEMENTED = self::CONFIRMATION`; `BookingWizardStep::count(): int`, `labels(): array`, `label(int): string`, `isKnown(int): bool`, `assertKnown(int): void` (all unchanged signatures). `LOCATION`, `CEMETERY`, `SERVICE_TYPE`, `SERVICES` (old value 4), `SUMMARY`, `CUSTOMER_DATA`, `DECEASED_DATA` constants are REMOVED.
- Produces: `BookingWizardScreen::labels(): array` returning `[1 => 'Cari & Pilih', 2 => 'Detail Pemesanan', 3 => 'Pembayaran', 4 => 'Konfirmasi']`.
- Consumes: nothing (pure domain layer, no dependencies on other tasks).

- [ ] **Step 1: Write the failing test for the renumbered `BookingWizardStep`**

Replace `tests/Unit/Domain/Booking/BookingWizardStepTest.php` with:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BookingWizardStepTest extends TestCase
{
    public function test_it_has_exactly_four_steps(): void
    {
        $this->assertSame(4, BookingWizardStep::count());
    }

    public function test_the_constants_are_1_through_4_in_order(): void
    {
        $this->assertSame(1, BookingWizardStep::DISCOVERY);
        $this->assertSame(2, BookingWizardStep::CUSTOMER_AND_DECEASED_DATA);
        $this->assertSame(3, BookingWizardStep::PAYMENT);
        $this->assertSame(4, BookingWizardStep::CONFIRMATION);
    }

    public function test_labels_match_the_four_screen_headings(): void
    {
        $this->assertSame([
            1 => 'Cari & Pilih',
            2 => 'Data Pemesan & Data Almarhum',
            3 => 'Pembayaran',
            4 => 'Konfirmasi',
        ], BookingWizardStep::labels());
    }

    public function test_last_implemented_is_confirmation(): void
    {
        $this->assertSame(BookingWizardStep::CONFIRMATION, BookingWizardStep::LAST_IMPLEMENTED);
    }

    public function test_is_known_rejects_the_old_nine_step_range(): void
    {
        $this->assertTrue(BookingWizardStep::isKnown(4));
        $this->assertFalse(BookingWizardStep::isKnown(5));
        $this->assertFalse(BookingWizardStep::isKnown(9));
        $this->assertFalse(BookingWizardStep::isKnown(0));
    }

    public function test_assert_known_throws_for_an_out_of_range_step(): void
    {
        $this->expectException(InvalidArgumentException::class);
        BookingWizardStep::assertKnown(5);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker run --rm --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-step-reduction:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpunit tests/Unit/Domain/Booking/BookingWizardStepTest.php`
Expected: FAIL — `DISCOVERY`/`CUSTOMER_AND_DECEASED_DATA` undefined, `count()` returns 9.

- [ ] **Step 3: Renumber `BookingWizardStep`**

Replace the class body (keep the file's existing namespace/imports/doc-block shape, rewrite the constants/LABELS):

```php
final class BookingWizardStep
{
    public const int DISCOVERY = 1;

    public const int CUSTOMER_AND_DECEASED_DATA = 2;

    public const int PAYMENT = 3;

    public const int CONFIRMATION = 4;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::DISCOVERY => 'Cari & Pilih',
        self::CUSTOMER_AND_DECEASED_DATA => 'Data Pemesan & Data Almarhum',
        self::PAYMENT => 'Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    public const int LAST_IMPLEMENTED = self::CONFIRMATION;

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

Update the class doc block to explain the 9→4 renumbering and point at the spec, matching the doc-block discipline already used elsewhere in this repo (see `RealisticMarketplacePricingExampleData`'s "Unit bug found in UAT" section for the expected style — a short "what changed and why, with a spec pointer" block, not a restatement of the whole spec).

- [ ] **Step 4: Run test to verify it passes**

Same command as Step 2. Expected: PASS (6 tests).

- [ ] **Step 5: Write the failing test for `BookingWizardScreen`**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Booking;

use App\Domain\Booking\BookingWizardScreen;
use PHPUnit\Framework\TestCase;

final class BookingWizardScreenTest extends TestCase
{
    public function test_it_has_exactly_four_screens(): void
    {
        $this->assertCount(4, BookingWizardScreen::labels());
    }

    public function test_labels_match_the_four_screen_names_from_pr_218(): void
    {
        $this->assertSame([
            1 => 'Cari & Pilih',
            2 => 'Detail Pemesanan',
            3 => 'Pembayaran',
            4 => 'Konfirmasi',
        ], BookingWizardScreen::labels());
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Run: same pattern as Step 2, targeting `BookingWizardScreenTest.php`. Expected: FAIL — class does not exist.

- [ ] **Step 7: Create `BookingWizardScreen`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Booking;

/**
 * The four screen names the booking wizard groups its steps into
 * (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`).
 * A SEPARATE class from `BookingWizardStep` even though both now count 4
 * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`
 * Decision 9) — kept distinct so a future step split does not have to be
 * un-collapsed from a merged class, matching how `RenewalWizardScreen`/
 * `RenewalJourneyStep` are also two classes despite the same coincidence
 * for renewal. Feeds `<x-mk.stepper>`'s `labels` prop directly — that
 * component already derives its dot count and text from whatever array is
 * passed in, no component-contract change needed.
 */
final class BookingWizardScreen
{
    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        1 => 'Cari & Pilih',
        2 => 'Detail Pemesanan',
        3 => 'Pembayaran',
        4 => 'Konfirmasi',
    ];

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Booking/BookingWizardStep.php app/Domain/Booking/BookingWizardScreen.php \
  tests/Unit/Domain/Booking/BookingWizardStepTest.php tests/Unit/Domain/Booking/BookingWizardScreenTest.php
git commit -m "feat(booking): renumber BookingWizardStep to 4 steps, add BookingWizardScreen"
```

---

### Task 2: Renumber `RenewalJourneyStep` to 3 steps, add `RenewalWizardScreen`

**Files:**
- Modify: `app/Domain/Renewal/RenewalJourneyStep.php` (currently `CITY=1` .. `CONFIRMATION=6`)
- Create: `app/Domain/Renewal/RenewalWizardScreen.php`
- Test: `tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php` (existing — update)
- Test: `tests/Unit/Domain/Renewal/RenewalWizardScreenTest.php` (new)

**Interfaces:**
- Produces: `RenewalJourneyStep::SEARCH = 1`, `FEE_AND_PAYMENT = 2`, `CONFIRMATION = 3`; `LAST_IMPLEMENTED = self::CONFIRMATION`. `CITY`, `CEMETERY`, `GRAVE_SEARCH`, `FEE`, `PAYMENT` (old renewal constant) are REMOVED.
- Produces: `RenewalWizardScreen::labels(): array` returning `[1 => 'Cari Makam', 2 => 'Biaya & Bayar', 3 => 'Konfirmasi']`.
- Consumes: nothing (independent of Task 1, can run in parallel).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalJourneyStep;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RenewalJourneyStepTest extends TestCase
{
    public function test_it_has_exactly_three_steps(): void
    {
        $this->assertSame(3, RenewalJourneyStep::count());
    }

    public function test_the_constants_are_1_through_3_in_order(): void
    {
        $this->assertSame(1, RenewalJourneyStep::SEARCH);
        $this->assertSame(2, RenewalJourneyStep::FEE_AND_PAYMENT);
        $this->assertSame(3, RenewalJourneyStep::CONFIRMATION);
    }

    public function test_labels_match_the_three_merged_headings(): void
    {
        $this->assertSame([
            1 => 'Cari Makam',
            2 => 'Biaya & Pembayaran',
            3 => 'Konfirmasi',
        ], RenewalJourneyStep::labels());
    }

    public function test_is_known_rejects_the_old_six_step_range(): void
    {
        $this->assertTrue(RenewalJourneyStep::isKnown(3));
        $this->assertFalse(RenewalJourneyStep::isKnown(4));
        $this->assertFalse(RenewalJourneyStep::isKnown(6));
    }

    public function test_assert_known_throws_for_an_out_of_range_step(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RenewalJourneyStep::assertKnown(4);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: same docker/phpunit pattern, `tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php`. Expected: FAIL.

- [ ] **Step 3: Renumber `RenewalJourneyStep`**

```php
final class RenewalJourneyStep
{
    public const int SEARCH = 1;

    public const int FEE_AND_PAYMENT = 2;

    public const int CONFIRMATION = 3;

    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        self::SEARCH => 'Cari Makam',
        self::FEE_AND_PAYMENT => 'Biaya & Pembayaran',
        self::CONFIRMATION => 'Konfirmasi',
    ];

    public const int LAST_IMPLEMENTED = self::CONFIRMATION;

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

    public static function assertKnown(int $step): void
    {
        if (! self::isKnown($step)) {
            throw new InvalidArgumentException(
                "Unknown renewal journey step [{$step}]. Known steps: 1-".self::count().'.'
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

Keep the file's existing `use InvalidArgumentException;` import and doc-block shape; update the doc block for the 6→3 renumbering, same style as Task 1.

- [ ] **Step 4: Run test to verify it passes**

Expected: PASS (5 tests).

- [ ] **Step 5: Write the failing test for `RenewalWizardScreen`**

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalWizardScreen;
use PHPUnit\Framework\TestCase;

final class RenewalWizardScreenTest extends TestCase
{
    public function test_it_has_exactly_three_screens(): void
    {
        $this->assertCount(3, RenewalWizardScreen::labels());
    }

    public function test_labels_match_the_three_screen_names_from_pr_218(): void
    {
        $this->assertSame([
            1 => 'Cari Makam',
            2 => 'Biaya & Bayar',
            3 => 'Konfirmasi',
        ], RenewalWizardScreen::labels());
    }
}
```

- [ ] **Step 6: Run test to verify it fails**

Expected: FAIL — class does not exist.

- [ ] **Step 7: Create `RenewalWizardScreen`**

```php
<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

/**
 * The three screen names the renewal wizard groups its steps into
 * (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`).
 * A SEPARATE class from `RenewalJourneyStep` even though both now count 3 —
 * see `App\Domain\Booking\BookingWizardScreen`'s doc block for the identical
 * reasoning. Feeds `<x-mk.stepper>`'s `labels` prop directly.
 */
final class RenewalWizardScreen
{
    /**
     * @var array<int, string>
     */
    public const array LABELS = [
        1 => 'Cari Makam',
        2 => 'Biaya & Bayar',
        3 => 'Konfirmasi',
    ];

    /**
     * @return array<int, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }
}
```

- [ ] **Step 8: Run test to verify it passes**

Expected: PASS (2 tests).

- [ ] **Step 9: Commit**

```bash
git add app/Domain/Renewal/RenewalJourneyStep.php app/Domain/Renewal/RenewalWizardScreen.php \
  tests/Unit/Domain/Renewal/RenewalJourneyStepTest.php tests/Unit/Domain/Renewal/RenewalWizardScreenTest.php
git commit -m "feat(renewal): renumber RenewalJourneyStep to 3 steps, add RenewalWizardScreen"
```

---

### Task 3: Merge `SaveBookingDraftStep`'s validators and persistence for the two merged booking steps

**Files:**
- Modify: `app/Domain/Booking/Actions/SaveBookingDraftStep.php`
- Test: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`, `SaveBookingDraftStepServicesTest.php`, `SaveBookingDraftStepSteps678Test.php`, `SaveBookingDraftStepIdempotencyTest.php`, `BookingDraftClosedListValidationTest.php` (existing — update to the new step numbers/merged payload shapes)

**Interfaces:**
- Consumes: `BookingWizardStep::DISCOVERY`/`CUSTOMER_AND_DECEASED_DATA`/`PAYMENT`/`CONFIRMATION` from Task 1.
- Produces: `SaveBookingDraftStep::__invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey, ?int $expectedVersion = null): BookingDraft` — SAME signature as today. `DISCOVERY`'s payload shape: `['city_code' => string, 'cemetery_id' => string, 'cemetery_package_id' => ?int, 'service_type' => string, 'selected_services' => list<array{code: string, quantity: int}>]`. `CUSTOMER_AND_DECEASED_DATA`'s payload shape: the union of the old `CUSTOMER_DATA` and `DECEASED_DATA` payload keys (`customer_full_name`, `customer_mobile`, `customer_email`, `customer_address`, `customer_relationship`, `customer_contact_channel`, `privacy_notice_accepted`, `deceased_full_name`, `deceased_date_of_birth`, `deceased_date_of_death`, `deceased_relationship`, `deceased_gender`).

**Real-code finding driving this task's design:** `validateCemetery()` (existing, ~line 300) reads `$draft->city_code` to cross-check the chosen cemetery is in the chosen city — this only works because in the OLD flow, `city_code` was already PERSISTED by a prior, separate `LOCATION` save before `CEMETERY` validated. In the merged `DISCOVERY` payload, `city_code` and `cemetery_id` arrive in the SAME call, before anything is persisted — `validateCemetery()`'s cross-check must read the payload's own `city_code`, not `$draft->city_code`. This is a real signature change to that private method, not just a call-site merge.

- [ ] **Step 1: Write the failing tests for the merged `DISCOVERY` validation**

Add to `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php` (read the existing file first for its exact fixture-building helpers — likely a `makeDraft()` or similar factory helper already exists; reuse it rather than duplicating draft-creation logic):

```php
public function test_discovery_step_accepts_a_full_valid_payload_in_one_call(): void
{
    $draft = $this->makeDraft(); // reuse existing test's draft-creation helper

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => 'JAKARTA', // use a real LaunchCityQuery::isKnown() code from the fixture data this test file already relies on
            'cemetery_id' => $this->knownCemeteryId, // reuse existing fixture cemetery
            'cemetery_package_id' => null,
            'service_type' => 'AT_NEED', // use a real BookingServiceType code already used elsewhere in this file
            'selected_services' => [
                ['code' => 'BASIC_CODE_FROM_FIXTURE', 'quantity' => 1], // reuse ServiceCode::BASIC_CODES fixture value already used in SaveBookingDraftStepServicesTest.php
            ],
        ],
        'idem-discovery-1',
    );

    $this->assertSame('JAKARTA', $saved->city_code);
    $this->assertSame($this->knownCemeteryId, $saved->cemetery_id);
    $this->assertSame('AT_NEED', $saved->service_type);
    $this->assertNotEmpty($saved->selected_services);
    $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->current_step);
    $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
}

public function test_discovery_step_rejects_a_cemetery_outside_the_chosen_city_from_the_same_payload(): void
{
    $draft = $this->makeDraft();

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => 'JAKARTA',
            'cemetery_id' => $this->knownCemeteryIdInADifferentCity, // a fixture cemetery whose ->city !== 'JAKARTA'
            'cemetery_package_id' => null,
            'service_type' => 'AT_NEED',
            'selected_services' => [['code' => 'BASIC_CODE_FROM_FIXTURE', 'quantity' => 1]],
        ],
        'idem-discovery-2',
    );
}

public function test_discovery_step_has_no_upstream_sequencing_requirement(): void
{
    // DISCOVERY is now the FIRST real step (like old LOCATION) — no
    // completed_steps precondition, unlike CUSTOMER_AND_DECEASED_DATA/PAYMENT.
    $draft = $this->makeDraft();
    $this->assertSame([], $draft->completed_steps);

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => 'JAKARTA',
            'cemetery_id' => $this->knownCemeteryId,
            'cemetery_package_id' => null,
            'service_type' => 'AT_NEED',
            'selected_services' => [['code' => 'BASIC_CODE_FROM_FIXTURE', 'quantity' => 1]],
        ],
        'idem-discovery-3',
    );

    $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
}
```

Add to `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php` (read the existing file first — it already has customer/deceased fixture data to reuse):

```php
public function test_customer_and_deceased_data_step_accepts_a_full_valid_combined_payload(): void
{
    $draft = $this->makeDraftAtDiscoveryComplete(); // build/reuse a helper that gets a draft to the point DISCOVERY is done

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        [
            'customer_full_name' => 'Budi Santoso',
            'customer_mobile' => '081234567890',
            'customer_email' => 'budi@example.test',
            'customer_address' => 'Jl. Contoh No. 1, Jakarta',
            'customer_relationship' => 'ANAK', // reuse a real BookingRelationshipCode from existing fixtures
            'customer_contact_channel' => 'WHATSAPP', // reuse a real BookingContactChannel from existing fixtures
            'privacy_notice_accepted' => true,
            'deceased_full_name' => 'Almarhum Contoh',
            'deceased_date_of_birth' => '1950-01-01',
            'deceased_date_of_death' => '2026-01-01',
            'deceased_relationship' => 'ORANG_TUA', // reuse a real BookingRelationshipCode
            'deceased_gender' => null,
        ],
        'idem-cadd-1',
    );

    $this->assertSame('Budi Santoso', $saved->customer_full_name);
    $this->assertSame('Almarhum Contoh', $saved->deceased_full_name);
    $this->assertSame(BookingWizardStep::PAYMENT, $saved->current_step);
}

public function test_customer_and_deceased_data_step_rejects_when_either_half_is_invalid(): void
{
    $draft = $this->makeDraftAtDiscoveryComplete();

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        [
            'customer_full_name' => '', // invalid — triggers customer-half validation
            'customer_mobile' => '081234567890',
            'customer_email' => 'budi@example.test',
            'customer_address' => 'Jl. Contoh No. 1, Jakarta',
            'customer_relationship' => 'ANAK',
            'customer_contact_channel' => 'WHATSAPP',
            'privacy_notice_accepted' => true,
            'deceased_full_name' => 'Almarhum Contoh',
            'deceased_date_of_birth' => '1950-01-01',
            'deceased_date_of_death' => '2026-01-01',
            'deceased_relationship' => 'ORANG_TUA',
            'deceased_gender' => null,
        ],
        'idem-cadd-2',
    );
}

public function test_customer_and_deceased_data_step_requires_discovery_completed_first(): void
{
    $draft = $this->makeDraft(); // fresh draft, DISCOVERY not yet done

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        ['customer_full_name' => 'Budi Santoso' /* ...rest of a valid payload... */],
        'idem-cadd-3',
    );
}
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL — `BookingWizardStep::DISCOVERY`/`CUSTOMER_AND_DECEASED_DATA` don't yet route through `SaveBookingDraftStep`'s `match` blocks correctly (still expects old `LOCATION`/`CEMETERY`/etc.).

- [ ] **Step 3: Rewrite `SaveBookingDraftStep::__invoke()`'s validation and persistence `match` blocks**

Replace the `$errors = match ($step) { ... }` block:

```php
$errors = match ($step) {
    BookingWizardStep::DISCOVERY => self::validateDiscovery($payload),
    BookingWizardStep::CUSTOMER_AND_DECEASED_DATA => [
        ...self::validateCustomerData($payload),
        ...self::validateDeceasedData($payload),
    ],
    BookingWizardStep::PAYMENT => self::validatePayment($payload, app(ModeResolver::class)->paymentMode()),
    default => [],
};
```

Replace the `$attributes = match ($step) { ... }` block:

```php
$attributes = match ($step) {
    BookingWizardStep::DISCOVERY => [
        'city_code' => $payload['city_code'],
        'cemetery_id' => $payload['cemetery_id'],
        'cemetery_package_id' => $payload['cemetery_package_id'] ?? null,
        'service_type' => $payload['service_type'],
        'selected_services' => $payload['selected_services'],
    ],
    BookingWizardStep::CUSTOMER_AND_DECEASED_DATA => [
        'customer_full_name' => self::trimmed($payload['customer_full_name']),
        'customer_mobile' => self::trimmed($payload['customer_mobile']),
        'customer_email' => self::trimmed($payload['customer_email']),
        'customer_address' => self::trimmed($payload['customer_address']),
        'customer_relationship' => $payload['customer_relationship'],
        'customer_contact_channel' => $payload['customer_contact_channel'],
        'privacy_notice_accepted_at' => Carbon::now()->toDateTimeString(),
        'deceased_full_name' => self::trimmed($payload['deceased_full_name']),
        'deceased_date_of_birth' => $payload['deceased_date_of_birth'],
        'deceased_date_of_death' => $payload['deceased_date_of_death'],
        'deceased_relationship' => $payload['deceased_relationship'],
        'deceased_gender' => self::nullIfBlank($payload['deceased_gender'] ?? null),
    ],
    BookingWizardStep::PAYMENT => [
        'payment_method' => $payload['payment_method'],
        'payment_reference' => self::nullIfBlank($payload['payment_reference'] ?? null),
    ],
    default => [],
};
```

Remove the old `SUMMARY`-specific `InvalidArgumentException` guard — `SUMMARY` no longer exists as a `BookingWizardStep` constant at all, so `BookingWizardStep::assertKnown($step)` at the top of `__invoke()` already rejects it (and every other removed constant) without a dedicated branch. Keep the `CONFIRMATION`-is-read-only guard as-is, just confirm it still reads `BookingWizardStep::CONFIRMATION` (now value 4, was 9 — no code change needed, only the constant's underlying value changed).

- [ ] **Step 4: Add `validateDiscovery()`, combining the four old validators with the corrected cross-check**

```php
/**
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
private static function validateDiscovery(array $payload): array
{
    $errors = [
        ...self::validateLocation($payload),
        ...self::validateCemeteryAgainstPayloadCity($payload),
        ...self::validateServiceType($payload),
        ...self::validateServices($payload),
    ];

    return $errors;
}

/**
 * Same rules as the old `validateCemetery(array $payload, BookingDraft
 * $draft)`, except the city cross-check reads `$payload['city_code']`
 * directly instead of `$draft->city_code` — in the merged DISCOVERY step
 * city and cemetery arrive in the SAME payload, before either is
 * persisted, so there is no draft column to read yet.
 *
 * @param  array<string, mixed>  $payload
 * @return array<string, list<string>>
 */
private static function validateCemeteryAgainstPayloadCity(array $payload): array
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

    $cityCode = $payload['city_code'] ?? null;

    if ($cityCode !== null && $cityCode !== '' && $cemetery->city !== $cityCode) {
        return ['cemetery_id' => ['TPU/TPS yang dipilih berada di luar kota yang dipilih.']];
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
```

Delete the old `validateCemetery(array $payload, BookingDraft $draft)` method entirely — its only caller was the removed `CEMETERY` match arm, and its city-source (`$draft->city_code`) is no longer valid for how this payload arrives. Note the copy change: the old message read "...berada di luar kota yang dipilih pada langkah 1" (referencing "step 1" by number) — the new message drops the step-number reference since DISCOVERY is a single step, not "step 1 vs step 2" — this is a deliberate, small copy fix, not an oversight.

- [ ] **Step 5: Simplify `validateStepSequencing()`**

The old method's complexity existed because `SUMMARY` (read-only) sat between `SERVICES` (old step 4) and `CUSTOMER_DATA` (old step 6), so steps ≥ `CUSTOMER_DATA` needed an "all of 1-4 done" check instead of a simple "step−1 done" check. `SUMMARY` no longer exists as a step at all, and `DISCOVERY` is the sole step below `CUSTOMER_AND_DECEASED_DATA` — the gap closes, and a uniform sequential check now covers every step:

```php
private static function validateStepSequencing(int $step, BookingDraft $draft): array
{
    if ($step === BookingWizardStep::DISCOVERY) {
        return [];
    }

    if (in_array($step - 1, $draft->completed_steps, true)) {
        return [];
    }

    return ['step' => ['Selesaikan langkah sebelumnya terlebih dahulu.']];
}
```

Update this method's doc block — the old one explains the now-obsolete "Step 5 is read-only, so steps 6-8 require all of 1-4" reasoning; replace with a short note that the simplification is possible specifically because the read-only `SUMMARY` step was cut, not merged (per the spec's Decision 1), so there is no longer a read-only step interrupting the sequence.

- [ ] **Step 6: Run tests to verify they pass**

Run the full `tests/Feature/Domain/Booking/Actions/` directory (all 5 files listed in this task's Files section) plus `tests/Unit/Domain/Booking/BookingWizardStepTest.php`. Expected: every test passes — this includes updating the PRE-EXISTING tests in these files that reference old constants (`LOCATION`, `CEMETERY`, `SERVICE_TYPE`, `SUMMARY`, `CUSTOMER_DATA`, `DECEASED_DATA`) or call `validateCemetery` semantics no longer present; read each file, find every such reference, and update it to the new step numbers/merged payload shape rather than deleting coverage. `BookingDraftClosedListValidationTest.php` specifically needs its per-field closed-list assertions (e.g. an unknown `service_type` value) re-pointed at `DISCOVERY` instead of `SERVICE_TYPE`/`SERVICES` separately.

- [ ] **Step 7: Commit**

```bash
git add app/Domain/Booking/Actions/SaveBookingDraftStep.php tests/Feature/Domain/Booking/Actions/
git commit -m "feat(booking): merge SaveBookingDraftStep validators for DISCOVERY and CUSTOMER_AND_DECEASED_DATA"
```

---

### Task 4: `BookingWizard.php` — merge the DISCOVERY save flow, including the plot-hold timing restructure

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php`
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`, `BookingWizardStepTwoPackagesTest.php`, `BookingWizardStepTwoCardContentTest.php`, `BookingWizardRouteTest.php`, `BookingWizardDraftBindingTest.php` (existing — update)

**Interfaces:**
- Consumes: `BookingWizardStep::DISCOVERY` (Task 1), `SaveBookingDraftStep`'s new `DISCOVERY` payload shape (Task 3).
- Produces: `BookingWizard::saveStep1(string $cityCode, string $cemeteryId, ?int $cemeteryPackageId, string $serviceType, array $selectedServices): void` (the new merged signature — REPLACES the old zero-argument-shape `saveStep1(string $cityCode)`, `saveStep2(string $cemeteryId, ?int $cemeteryPackageId = null)`, `saveStep3(string $serviceType)`, `saveStep4(array $selectedServices)`, all four DELETED). `continueFromDiscovery(): void` — the Blade "Lanjutkan" trigger, replaces `continueFromStep4()`.

**Real-code finding driving this task's design:** `saveStep1()` today creates the draft (`currentOrNewDraft()`) and redirects to `pemesanan-makam.draft`, because there is no draft yet at step 1 — this is structurally different from `saveStep2()`/`saveStep3()`/`saveStep4()`, which all use the simpler `saveStepOrShowErrors()` wrapper assuming a draft already exists. The merged `saveStep1()` must keep the draft-creation/redirect behavior, but now validates city+cemetery+serviceType+services together.

**Second real-code finding, more significant:** `holdPlotForStep2()` (the plot-picker flow) TODAY holds a contended plot via `HoldPlotForDraft` and THEN immediately calls `saveStep2()` to persist `cemetery_id`/`cemetery_package_id` onto the draft — releasing the hold if that save fails. Under the merge, the actual `SaveBookingDraftStep` call for `DISCOVERY` cannot happen until service type AND services are ALSO chosen (they're validated together, in one payload). But the PLOT HOLD itself must stay an immediate, real action at cemetery-selection time — deferring it would let two visitors both "browse" the same plot while one fills out the rest of the form, defeating the entire purpose of holding scarce inventory. Resolution: keep `HoldPlotForDraft`'s call immediate and unchanged; stop calling any `SaveBookingDraftStep`-backed save right after it — instead track the chosen `cemeteryId`/`cemeteryPackageId`/held plot in Livewire component properties, and let the ONE final `continueFromDiscovery()` → `saveStep1()` call (fired once service type + services are also chosen) be the only place `SaveBookingDraftStep` is invoked for this step. Hold-release-on-failure logic moves from `holdPlotForStep2()`'s tail into `saveStep1()`'s failure branches.

- [ ] **Step 1: Write the failing tests for the merged save method's happy path and hold-release-on-failure behavior**

Read `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php` in full first — it already has the fixtures (a granular-tier cemetery, a `GravePlot`, `Livewire::test(BookingWizard::class)`) this task's new tests reuse. Add:

```php
public function test_save_step1_persists_all_four_discovery_fields_in_one_call(): void
{
    $component = Livewire::test(BookingWizard::class)
        ->call('saveStep1', 'JAKARTA', $this->cemeteryId, null, 'AT_NEED', [
            ['code' => $this->basicServiceCode, 'quantity' => 1],
        ]);

    $component->assertHasNoErrors();

    $draft = BookingDraft::query()->latest()->first();
    $this->assertSame('JAKARTA', $draft->city_code);
    $this->assertSame($this->cemeteryId, $draft->cemetery_id);
    $this->assertSame('AT_NEED', $draft->service_type);
    $this->assertSame(2, $draft->current_step); // CUSTOMER_AND_DECEASED_DATA
}

public function test_holding_a_plot_does_not_immediately_persist_the_draft(): void
{
    $component = Livewire::test(BookingWizard::class)
        ->call('openPickerFor', $this->cemeteryId, null)
        ->call('holdPlotForStep2', $this->cemeteryId, null, $this->plotId); // rename target per Step 4 below

    // The hold exists...
    $this->assertDatabaseHas('plot_reservations', ['plot_id' => $this->plotId]);
    // ...but nothing was persisted onto a booking_drafts row yet — DISCOVERY
    // is not complete until service type + services are also chosen.
    $this->assertDatabaseMissing('booking_drafts', ['cemetery_id' => $this->cemeteryId]);
}

public function test_a_failed_discovery_save_releases_a_hold_this_session_created(): void
{
    Livewire::test(BookingWizard::class)
        ->call('openPickerFor', $this->cemeteryId, null)
        ->call('holdPlotForStep2', $this->cemeteryId, null, $this->plotId)
        ->call('saveStep1', '', $this->cemeteryId, null, 'AT_NEED', [
            ['code' => $this->basicServiceCode, 'quantity' => 1],
        ]) // empty city_code — fails validateLocation()
        ->assertHasErrors(['city_code']);

    $this->assertDatabaseMissing('plot_reservations', ['plot_id' => $this->plotId, 'state' => 'HELD']);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL — `saveStep1` doesn't accept 5 arguments yet.

- [ ] **Step 3: Read `BookingWizard.php` in full before editing** — 1511 lines, multiple properties (`$cityCode`, `$cemeteryId`, `$cemeteryPackageId`, `$serviceType`, `$stagedServiceCodes` or similar — confirm exact property names by reading the class's `#[Locked]`/public property declarations near the top of the file) feed the four old save methods. Confirm which of these properties already exist vs. need adding, and whether `pickerCemeteryId`/`pickerCemeteryPackageId` (used by `openPickerFor()`) are distinct from the "confirmed selection" properties or the same ones — this determines whether `holdPlotForStep2()`'s restructure needs new properties or can reuse existing ones.

- [ ] **Step 4: Delete `saveStep2()`, `saveStep3()`, `saveStep4()`, `continueFromStep4()`; rewrite `saveStep1()`**

```php
public function saveStep1(
    string $cityCode,
    string $cemeteryId,
    ?int $cemeteryPackageId,
    string $serviceType,
    array $selectedServices,
): void {
    $payload = [
        'city_code' => $cityCode,
        'cemetery_id' => $cemeteryId,
        'cemetery_package_id' => $cemeteryPackageId,
        'service_type' => $serviceType,
        'selected_services' => $selectedServices,
    ];
    $idempotencyKey = $this->idempotencyKeyFor(BookingWizardStep::DISCOVERY, $payload);

    try {
        $saved = DB::transaction(function () use ($payload, $idempotencyKey): BookingDraft {
            $draft = $this->currentOrNewDraft();
            $expectedVersion = $draft->wasRecentlyCreated ? null : $this->version;

            return (new SaveBookingDraftStep)(
                $draft,
                BookingWizardStep::DISCOVERY,
                $payload,
                $idempotencyKey,
                expectedVersion: $expectedVersion,
            );
        });

        $this->hydrateFrom($saved);
        $this->autosaveState = 'saved';

        $this->redirect(route('pemesanan-makam.draft', ['draftId' => $saved->id]), navigate: false);
    } catch (BookingStepValidationException $e) {
        $this->autosaveState = 'failed';
        foreach ($e->getErrors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }
        $this->releaseHeldPlotIfAny();
    } catch (BookingDraftVersionConflictException) {
        $this->handleVersionConflict();
        $this->releaseHeldPlotIfAny();
    }
}

public function continueFromDiscovery(): void
{
    $this->saveStep1(
        $this->cityCode,
        $this->cemeteryId,
        $this->cemeteryPackageId,
        $this->serviceType,
        array_map(
            static fn (string $code): array => ['code' => $code, 'quantity' => 1],
            $this->stagedServiceCodes,
        ),
    );
}
```

Confirm the exact existing property names (`$this->cityCode` etc.) against what Step 3's read found — the names above are illustrative of the shape, not a guess to leave unverified.

- [ ] **Step 5: Restructure `holdPlotForStep2()` to stop calling a `SaveBookingDraftStep`-backed save**

Rename to `holdPlotForDiscovery()` (the method no longer corresponds to a numbered "step 2"). Remove the trailing `$this->saveStep2($cemeteryId, $cemeteryPackageId);` call and its `wasRecentlyCreated`/`autosaveState === 'failed'` release check — the hold now stays open until `continueFromDiscovery()` → `saveStep1()` either succeeds (hold is later converted by `SubmitBookingDraft`'s existing chain, unchanged) or fails (released via the new `releaseHeldPlotIfAny()` helper called from `saveStep1()`'s catch blocks in Step 4 above). Set `$this->cemeteryId`/`$this->cemeteryPackageId` (the properties `continueFromDiscovery()` reads) from this method instead of relying on a save's own persisted state.

Add a new private helper:

```php
/**
 * Releases a plot hold THIS session created, if the just-attempted
 * DISCOVERY save failed. Moved here from the old `holdPlotForStep2()`'s
 * tail (see this class's doc block on the DISCOVERY merge) — the hold
 * now outlives the moment it was taken, since persistence is deferred
 * until service type + services are also chosen.
 */
private function releaseHeldPlotIfAny(): void
{
    if ($this->draftId === null || $this->pickerCemeteryId === null) {
        return;
    }

    $draft = BookingDraftQuery::findBound($this->draftId);
    $hold = $draft !== null ? PlotReservation::activeForDraft($draft) : null;

    if ($hold !== null && $hold->wasRecentlyCreated) {
        (new ReleasePlotReservation)(
            $hold,
            "booking_draft:{$draft->getKey()}",
            'customer',
            reason: 'discovery step was not saved after the hold was taken',
            auditSource: AuditSource::Api,
        );
    }
}
```

Verify `PlotReservation::activeForDraft()`'s `wasRecentlyCreated` semantics against the ORIGINAL `holdPlotForStep2()` code before this edit — the original checked `$hold->wasRecentlyCreated` on the hold object returned directly from `HoldPlotForDraft`'s own call in the SAME request; this rewritten version re-fetches via `activeForDraft()` in what may be a LATER request (since the save is now deferred), so `wasRecentlyCreated` (an Eloquent flag scoped to the object instance that performed the insert, not a persisted column) will ALWAYS be false on a re-fetched model. Read `HoldPlotForDraft` and `PlotReservation` to confirm whether a persisted "who/when created this hold" signal exists to substitute for the flag (e.g. a `created_at` recency check, or an audit trail keyed by session) — if not, this is a real design gap this task must resolve honestly (e.g. by keeping the hold's own return value in a Livewire property across the two calls within the same request lifecycle, since Livewire component state persists across method calls within one page session) rather than by guessing `wasRecentlyCreated` still works.

- [ ] **Step 6: Update `wire:click` targets in `wizard.blade.php` for the renamed/merged methods** (the Blade edits themselves are Task 6 — this step is a cross-reference note: search the blade file for `wire:click="saveStep1"`, `wire:click="saveStep2"`, `wire:click="saveStep3"`, `continueFromStep4`, `holdPlotForStep2` and list every match so Task 6 has the exact call sites; do not edit the Blade file in this task).

- [ ] **Step 7: Run tests to verify they pass**

Run all 5 files in this task's Files section. Expected: PASS, including every pre-existing test in these files updated for the new merged signature.

- [ ] **Step 8: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoPackagesTest.php tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoCardContentTest.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php tests/Feature/Livewire/Public/Booking/BookingWizardDraftBindingTest.php
git commit -m "feat(booking): merge saveStep1-4 into one DISCOVERY save, restructure plot-hold timing"
```

---

### Task 5: `BookingWizard.php` — merge customer/deceased data, renumber payment, simplify screen tracking

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php` (continues Task 4's edits)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardStepFiveToSixHandoffTest.php`, `BookingWizardStepsSixToNineEndToEndTest.php`, `BookingWizardScreenBoundaryTest.php`, `BookingWizardProgressiveRevealTest.php`, `BookingWizardOnlinePaymentTest.php`, `BookingWizardManualPaymentBankDetailsTest.php`

**Interfaces:**
- Consumes: `BookingWizardStep::CUSTOMER_AND_DECEASED_DATA`/`PAYMENT` (Task 1), `SaveBookingDraftStep`'s merged `CUSTOMER_AND_DECEASED_DATA` payload (Task 3).
- Produces: `BookingWizard::saveStep2(): void` (the merged customer+deceased save, REPLACES old `saveStep6()`+`saveStep7()`), `saveStep3(string $paymentMethod): void` (renamed from `saveStep8()`, body otherwise identical), `currentScreen(): int` (simplified body).

- [ ] **Step 1: Write the failing test for the merged customer/deceased save**

Add to `BookingWizardStepFiveToSixHandoffTest.php` (read it first — it already builds a draft up through DISCOVERY-equivalent state):

```php
public function test_save_step2_persists_customer_and_deceased_fields_together(): void
{
    $component = Livewire::test(BookingWizard::class, ['draftId' => $this->draftAtDiscoveryComplete->id])
        ->set('customerFullName', 'Budi Santoso')
        ->set('customerMobile', '081234567890')
        ->set('customerEmail', 'budi@example.test')
        ->set('customerAddress', 'Jl. Contoh No. 1, Jakarta')
        ->set('customerRelationship', $this->knownRelationshipCode)
        ->set('customerContactChannel', $this->knownContactChannel)
        ->set('privacyNoticeAccepted', true)
        ->set('deceasedFullName', 'Almarhum Contoh')
        ->set('deceasedDateOfBirth', '1950-01-01')
        ->set('deceasedDateOfDeath', '2026-01-01')
        ->set('deceasedRelationship', $this->knownRelationshipCode)
        ->call('saveStep2');

    $component->assertHasNoErrors();

    $draft = $this->draftAtDiscoveryComplete->fresh();
    $this->assertSame('Budi Santoso', $draft->customer_full_name);
    $this->assertSame('Almarhum Contoh', $draft->deceased_full_name);
    $this->assertSame(BookingWizardStep::PAYMENT, $draft->current_step);
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — `saveStep2()` still means the old CEMETERY save (or doesn't exist yet, depending on task-execution order — this task runs after Task 4, so `saveStep2` is free).

- [ ] **Step 3: Delete `saveStep6()`/`saveStep7()`; add merged `saveStep2()`**

```php
public function saveStep2(): void
{
    $this->saveStepOrShowErrors(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, [
        'customer_full_name' => $this->customerFullName,
        'customer_mobile' => $this->customerMobile,
        'customer_email' => $this->customerEmail,
        'customer_address' => $this->customerAddress,
        'customer_relationship' => $this->customerRelationship,
        'customer_contact_channel' => $this->customerContactChannel,
        'privacy_notice_accepted' => $this->privacyNoticeAccepted,
        'deceased_full_name' => $this->deceasedFullName,
        'deceased_date_of_birth' => $this->deceasedDateOfBirth,
        'deceased_date_of_death' => $this->deceasedDateOfDeath,
        'deceased_relationship' => $this->deceasedRelationship,
        'deceased_gender' => $this->deceasedGender !== '' ? $this->deceasedGender : null,
    ]);
}
```

- [ ] **Step 4: Rename `saveStep8()` to `saveStep3()`; update its two internal `BookingWizardStep::PAYMENT` references** (already correct by value once Task 1 lands — this is a pure rename, the method body is byte-identical). Do the same for `openOnlinePayment()`'s internal `SaveBookingDraftStep` call, which also references `BookingWizardStep::PAYMENT` — no rename needed there since it's not a `saveStepN`-named method, just confirm it still compiles against the new constant value.

- [ ] **Step 5: Simplify `currentScreen()`**

Booking's steps and screens now converge 1:1 (4 steps, 4 screens — same convergence Decisions 3/4 already gave renewal). Replace:

```php
public function currentScreen(): int
{
    return match (true) {
        $this->currentStep <= BookingWizardStep::SERVICES => 1,
        $this->currentStep <= BookingWizardStep::DECEASED_DATA => 2,
        $this->currentStep === BookingWizardStep::PAYMENT => 3,
        default => 4,
    };
}
```

with:

```php
/**
 * Screens and steps converge 1:1 post-step-reduction
 * (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`
 * Decision 9) — kept as its own method (rather than inlining
 * `$this->currentStep` at all 4 Blade call sites) so the Blade template's
 * `@if ($this->currentScreen() === N)` guards read the same as before this
 * change, and so a future re-divergence of screens from steps has one
 * place to change.
 */
public function currentScreen(): int
{
    return $this->currentStep;
}
```

- [ ] **Step 6: Simplify `canReachStep()`**

The `SUMMARY` and `CUSTOMER_DATA` special cases existed only because those were read-only-adjacent bridge steps in the old 9-step model. `SUMMARY` is gone; `CUSTOMER_DATA` no longer exists as a distinct constant (merged into `CUSTOMER_AND_DECEASED_DATA`, which is a normal writable step reached via the standard `in_array($step, $this->completedSteps, true)` check already at the top of the method). Only `CONFIRMATION` (still read-only, still needs a "reachable once its predecessor is done" rule) remains a special case:

```php
private function canReachStep(int $step): bool
{
    if ($step === $this->currentStep || in_array($step, $this->completedSteps, true)) {
        return true;
    }

    return match ($step) {
        BookingWizardStep::CONFIRMATION => in_array(BookingWizardStep::PAYMENT, $this->completedSteps, true),
        default => false,
    };
}
```

Update this method's doc block to remove the now-obsolete SUMMARY/CUSTOMER_DATA reasoning.

- [ ] **Step 7: Update the Ringkasan sidebar's reveal-gating**

Read the current reveal logic around the old `$currentStep <= SUMMARY` comment (previously ~line 1306/1341, confirm actual current line after Tasks 1-4's edits shift line numbers). Per the spec's Decision 1, Ringkasan is no longer gated by any step comparison at all — it renders unconditionally for the whole of Screen 2 (there is no longer a `SUMMARY` step value to compare against). Remove the conditional; the Ringkasan partial becomes part of Screen 2's constant markup, reading the draft's already-saved totals the same way it does today.

- [ ] **Step 8: Run tests to verify they pass**

Run all 6 files in this task's Files section, plus a full re-run of Task 4's 5 files (this task edits the same class file — confirm no regression). Expected: PASS.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php tests/Feature/Livewire/Public/Booking/BookingWizardStepFiveToSixHandoffTest.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardStepsSixToNineEndToEndTest.php tests/Feature/Livewire/Public/Booking/BookingWizardScreenBoundaryTest.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php tests/Feature/Livewire/Public/Booking/BookingWizardOnlinePaymentTest.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardManualPaymentBankDetailsTest.php
git commit -m "feat(booking): merge customer+deceased data save, renumber payment step, simplify screen tracking"
```

---

### Task 6: `wizard.blade.php` — stepper labels fix and screen/step markup update

**Files:**
- Modify: `resources/views/livewire/public/booking/wizard.blade.php` (1667 lines)
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php`, `BookingWizardEndToEndTest.php`

**Interfaces:**
- Consumes: `BookingWizardScreen::labels()` (Task 1), `BookingWizard::saveStep1()`/`saveStep2()`/`saveStep3()`/`continueFromDiscovery()`/`holdPlotForDiscovery()` (Tasks 4-5).

- [ ] **Step 1: Write the failing test proving the stepper passes the new 4-item labels array**

Add to `BookingWizardAccessibilityTest.php` (read it first for its existing `Livewire::test(BookingWizard::class)` fixture pattern):

```php
public function test_the_stepper_renders_the_four_screen_labels_not_the_old_nine_step_labels(): void
{
    $component = Livewire::test(BookingWizard::class);

    $component->assertSee('Cari & Pilih');
    $component->assertSee('Detail Pemesanan');
    $component->assertDontSee('Jenis Layanan'); // an old, now-removed individual step label
    $component->assertDontSee('Ringkasan Pesanan'); // the old step 5 label — Ringkasan is a sidebar now, not a stepper dot
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — today's `<x-mk.stepper :step="$currentStep" class="mb-8" />` (line 72) has no `labels` override, so it renders the OLD 9-item default (`docs/design/design-system.md` §3.9's normative default) — this is the real regression trap the spec flags, now proven with a failing test rather than assumed.

- [ ] **Step 3: Fix the stepper invocation**

Change line 72 from:

```blade
<x-mk.stepper :step="$currentStep" class="mb-8" />
```

to:

```blade
<x-mk.stepper :step="$currentStep" :labels="\App\Domain\Booking\BookingWizardScreen::labels()" class="mb-8" />
```

`$currentStep` needs NO other change here — because booking's steps and screens now converge 1:1 (Task 5, Step 5), the raw `$currentStep` value (1-4 post-renumbering) already matches `BookingWizardScreen::labels()`'s 1-4 keys directly. No `currentScreen()`-based prop is needed for the stepper specifically, even though `currentScreen()` still exists for the Blade `@if` screen-gating blocks elsewhere in this file.

- [ ] **Step 4: Update the `@if ($this->currentScreen() === N)` blocks and their internal step-label markup**

Read the full file (1667 lines) and update every reference to the removed constants/methods found: the `wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SUMMARY }})"` call (previously line 876 — `SUMMARY` no longer exists; since Ringkasan is now an unconditional sidebar per Task 5 Step 7, this specific "go to Ringkasan" link/button should be removed entirely, not repointed — there is no step to navigate to), the "Langkah 5 — Ringkasan Pesanan" heading text (previously line 627, part of the sidebar markup — keep the Ringkasan CONTENT, drop the "Langkah 5" step-number framing since it's not a numbered step anymore), and every `wire:click="saveStep1"`/`"saveStep2"`/`"saveStep3"`/`"saveStep4"`/`"saveStep6"`/`"saveStep7"`/`"saveStep8"`/`"continueFromStep4"`/`"holdPlotForStep2"` call site found via Task 4 Step 6's cross-reference list — repoint each to its new method name (`saveStep1` with the new 5-arg signature, `continueFromDiscovery`, `holdPlotForDiscovery`, `saveStep2` for customer/deceased, `saveStep3` for payment).

- [ ] **Step 5: Run tests to verify they pass**

Run both files in this task's Files section, plus `BookingWizardEndToEndTest.php` (this is the most likely file to catch any missed Blade call-site rename via a real end-to-end journey failing partway through).

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/public/booking/wizard.blade.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php
git commit -m "fix(booking): pass BookingWizardScreen labels to the stepper, update Blade call sites for the merged steps"
```

---

### Task 7: Renewal components — renumber to 3 steps, swap to `RenewalWizardScreen` labels

**Files:**
- Modify: `app/Livewire/Public/Renewal/RenewalStart.php`, `app/Livewire/Public/Renewal/RenewalPayment.php`, `app/Livewire/Public/Renewal/RenewalConfirmation.php`
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (existing — update), plus new coverage for `RenewalPayment`/`RenewalConfirmation` if no existing feature test file covers their step-label rendering (confirm during implementation — `grep -rl RenewalPayment tests/Feature/Livewire/Public/Renewal/` first; if a `RenewalPaymentTest.php` exists it was not caught by this plan's earlier grep because it may not reference the OLD constants by name — check regardless and add coverage there if missing)

**Interfaces:**
- Consumes: `RenewalJourneyStep::SEARCH`/`FEE_AND_PAYMENT`/`CONFIRMATION` (Task 2), `RenewalWizardScreen::labels()` (Task 2).
- Produces: `RenewalStart::currentStep(): int` (simplified — always returns `RenewalJourneyStep::SEARCH` now, since there is only one step left on this screen; consider whether the method should be removed entirely in favor of a literal, per Step 3 below), `RenewalStart::goToStep(int $step): void` (simplified body).

**Real-code finding (already established in this plan's Global Constraints):** neither `RenewalStart` nor `RenewalPayment` persists `current_step` anywhere — both compute it as a pure derived value from in-memory Livewire properties on every render. This task is a pure renumbering/relabeling exercise with NO database migration concern, unlike booking.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (read it first for its existing fixture pattern):

```php
public function test_the_stepper_renders_the_three_screen_labels(): void
{
    $component = Livewire::test(RenewalStart::class);

    $component->assertSee('Cari Makam');
    $component->assertSee('Biaya & Bayar');
    $component->assertDontSee('TPU/TPS'); // an old, now-removed individual step label
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — `RenewalStart` currently passes `RenewalJourneyStep::labels()` (the OLD 6-item map, including "TPU/TPS") as `stepLabels`.

- [ ] **Step 3: Update `RenewalStart.php`**

Read the full 423-line file first to confirm every reference (the earlier grep found `current_step`/`RenewalJourneyStep::` matches at lines ~170, 186, 226, 317, 323, 331-333, 410 — re-verify these line numbers against the file as it stands after Task 2's renumbering, since `RenewalJourneyStep`'s constant VALUES changing does not shift THIS file's line numbers, only the values referenced).

`goToStep()` (was lines ~314-324): since `RenewalJourneyStep::CITY`/`CEMETERY` no longer exist as separate steps (both folded into `SEARCH`), and this component's `goToStep()` only ever handled dot-1/dot-2 clicks (line 317/323's `if` branches), simplify to:

```php
public function goToStep(int $step): void
{
    if ($step === RenewalJourneyStep::SEARCH) {
        $this->resetCity();
    }
}
```

Confirm against the actual current bodies of `resetCity()`/`resetCemetery()` (read them — not yet read in this plan's research) whether `resetCity()` alone still correctly cascades to reset the cemetery/search state the way the old two-branch version did across its two former dots, per this file's own doc-block comment ("dot 1 → resetCity() (which cascades through resetCemetery() to the search)"); if `resetCity()`'s cascade already covers cemetery+search, a single branch reproduces the original two-branch behavior for the now-single dot. If it does NOT already cascade fully, extend `resetCity()` itself (not `goToStep()`) so the invariant holds — the DOT COUNT changed, not the reset semantics.

`currentStep()` (was lines ~326-334):

```php
private function currentStep(): int
{
    return RenewalJourneyStep::SEARCH;
}
```

(Screen 1 is now a single step regardless of how much of the city/cemetery/search sub-flow is filled in — there is nothing left to derive a multi-value match from.) Consider whether to keep this as a method at all vs. inlining `RenewalJourneyStep::SEARCH` at its one call site (line ~410's `'currentStep' => ...`) — read that call site's context; if `currentStep()` has no other callers, inlining is simpler and the method can be deleted. If it has other callers, keep it.

Line ~410's `'stepLabels' => RenewalJourneyStep::labels(),` becomes `'stepLabels' => RenewalWizardScreen::labels(),` — add the `use App\Domain\Renewal\RenewalWizardScreen;` import.

- [ ] **Step 4: Update `RenewalPayment.php`**

Read the file in full (363 lines). The one match found (`'currentStep' => $state['mode'] === 'fee' ? RenewalJourneyStep::FEE : RenewalJourneyStep::PAYMENT,` and the adjacent `'stepLabels' => RenewalJourneyStep::labels(),`) becomes:

```php
'currentStep' => RenewalJourneyStep::FEE_AND_PAYMENT,
'stepLabels' => RenewalWizardScreen::labels(),
```

(Both the fee sub-view and the payment sub-view are now the SAME step — `FEE_AND_PAYMENT` — so the `$state['mode'] === 'fee' ? ... : ...` ternary collapses to the single constant.) Add the `use App\Domain\Renewal\RenewalWizardScreen;` import. Search the rest of the file for any other `RenewalJourneyStep::FEE`/`RenewalJourneyStep::PAYMENT` reference beyond this one match (the earlier grep found exactly one line, but re-grep after opening the file in full in case the single-line match combined two references on one line, as shown above).

- [ ] **Step 5: Update `RenewalConfirmation.php`**

Read the file (50 lines) — no `RenewalJourneyStep::` matches were found by this plan's grep for this file specifically, but the file was listed among the spec's files-to-check; confirm it reads `RenewalJourneyStep::CONFIRMATION` (now value 3, was 6) correctly with no other change needed, since only the constant's underlying value changes, not its name.

- [ ] **Step 6: Run tests to verify they pass**

Run `RenewalStartTest.php` and any `RenewalPayment`/`RenewalConfirmation` test files found in Step 4/5's re-grep.

- [ ] **Step 7: Commit**

```bash
git add app/Livewire/Public/Renewal/RenewalStart.php app/Livewire/Public/Renewal/RenewalPayment.php \
  app/Livewire/Public/Renewal/RenewalConfirmation.php tests/Feature/Livewire/Public/Renewal/
git commit -m "feat(renewal): renumber to 3 steps, swap stepper labels to RenewalWizardScreen"
```

---

### Task 8: Sweep remaining booking test files for old step-number/method-name references

**Files:**
- Modify (as needed, based on what each file actually contains): `tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php`, `tests/Feature/Domain/PlotReservation/HoldPlotForDraftTwoConnectionTest.php`, `HoldPlotForDraftTest.php`, `PlotReservationBookingDraftHoldTest.php`, `ConvertDraftHoldToOrderReservationTest.php`, `tests/Feature/Domain/Quotation/ComposeQuoteLinesFromBookingDraftTest.php`, `tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php`, `tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php`, `tests/Feature/Livewire/Public/Booking/BookingWizardDegradedReadsTest.php`, `BookingWizardSaveIntegrityTest.php`, `BookingWizardExpiredHoldOnSubmitTest.php`, `BookingWizardCheckboxStylingTest.php`, `tests/Feature/Livewire/Public/Akun/DraftListTest.php`
- Also review (not from the grep, but structurally certain to reference old numbering given their names/purpose): `tests/browser/e2e-booking-loading-states.spec.ts` (Playwright — a different test runner, confirm whether this plan's Postgres/CI verification covers it or whether it needs a separate note for whoever runs the Playwright suite)

**This task exists because Task 1-7's own test updates only cover the files each task's Files section names directly — this task is the sweep for everything else the grep in this plan's research phase found.**

- [ ] **Step 1: Re-run the enumeration grep against the state of the branch after Tasks 1-7 land**, to confirm the list above is still accurate and nothing new appeared:

```bash
grep -rl "BookingWizardStep::LOCATION\|BookingWizardStep::CEMETERY\|BookingWizardStep::SERVICE_TYPE\|BookingWizardStep::SUMMARY\|BookingWizardStep::CUSTOMER_DATA\|BookingWizardStep::DECEASED_DATA\|BookingWizardStep::SERVICES\b" tests/
```

(Note `SERVICES` needs a word-boundary check post-merge since it's ALSO not a valid current constant anymore under the new 4-step model — the old step-4 `SERVICES` is gone, replaced by `DISCOVERY`.)

- [ ] **Step 2: For each file the grep still finds, read it and fix its assertions/fixture-building to the new step numbers/constants** — these are pre-existing tests for OTHER concerns (plot reservation, draft binding, quote composition, order submission) that happen to construct a `BookingDraft` at a specific `current_step` value or reference an old constant as part of their OWN fixture setup, not tests of the wizard steps themselves. Do not weaken their actual assertions — only update the numbering they depend on to reach their real test target.

- [ ] **Step 3: Run the full booking-related test suite**

```bash
docker run --rm --network host --user 1000:1000 -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<port> -v /home/ubuntu/makam-app/.worktrees/wizard-step-reduction:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Booking tests/Feature/Domain/Booking tests/Feature/Domain/PlotReservation tests/Feature/OrderWorkflow tests/Feature/Domain/Quotation tests/Feature/Livewire/Public/Akun tests/Unit/Domain/Booking
```

Expected: PASS across the board (allow for the ONE pre-existing flaky test, `BookingWizardPlotPickerTest`, this plan's own baseline already identified — if it fails, re-run it in isolation per the baseline's own established pattern before treating it as a real regression).

- [ ] **Step 4: Commit**

```bash
git add tests/
git commit -m "test(booking): update remaining fixture references to the new 4-step numbering"
```

---

### Task 9: In-flight booking draft under old numbering is treated as unresumable

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php` (the resume/hydrate path — locate via `mount()` or `resolveDraftById()`, read the surrounding code before editing)
- Test: new test in `tests/Feature/Livewire/Public/Booking/BookingWizardDraftBindingTest.php` (reuse — this file already covers draft-resolution edge cases per Task 4's Files list)

**Interfaces:**
- Consumes: `BookingWizardStep::isKnown()`/`assertKnown()` (Task 1), the existing "session expired" error message pattern already used throughout `BookingWizard.php` (`'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.'`).

**Booking-only** — per this plan's Global Constraints finding, renewal has no persisted `current_step`, so there is nothing to be stale for renewal.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_draft_at_an_old_out_of_range_current_step_is_treated_as_unresumable(): void
{
    // Simulate a draft that was mid-flow under the OLD 9-step numbering
    // when this change shipped — current_step = 8 (old PAYMENT) has no
    // meaning under the new 4-step BookingWizardStep::isKnown() range.
    $draft = BookingDraft::factory()->create(['current_step' => 8, 'completed_steps' => [1, 2, 3, 4, 6, 7]]);

    $component = Livewire::test(BookingWizard::class, ['draftId' => $draft->id]);

    $component->assertSee('Sesi pemesanan Anda telah berakhir');
}
```

Confirm `BookingDraft::factory()` exists and accepts `current_step`/`completed_steps` directly (read the factory file if uncertain) before relying on this exact call shape.

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — today the component likely tries to hydrate `$currentStep = 8` directly and either 500s or renders a blank/incorrect screen, since nothing currently checks the resumed value against `BookingWizardStep::isKnown()`.

- [ ] **Step 3: Add the guard**

Locate where a resumed draft's `current_step` is read into the component (likely in `mount()`, near where `$this->draftId`/`$this->currentStep` get hydrated from a found draft — read the surrounding code first). Add:

```php
if (! BookingWizardStep::isKnown($draft->current_step)) {
    $this->draftId = null;
    $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

    return;
}
```

placed after the draft is resolved but before its `current_step`/other fields are hydrated into component state — mirroring the existing "session expired" pattern already used in `saveStepOrShowErrors()`/`saveStep1()`'s own null-draft branches, so this reads as the same family of error rather than a new one.

- [ ] **Step 4: Run test to verify it passes**

Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Livewire/Public/Booking/BookingWizard.php tests/Feature/Livewire/Public/Booking/BookingWizardDraftBindingTest.php
git commit -m "feat(booking): treat an in-flight draft under the old step numbering as unresumable"
```

---

### Task 10: Documentation amendments

**Files:**
- Modify: `AGENTS.md`, `docs/design/design-system.md`, `docs/product/booking-wizard-fields.md`
- Modify: `.kiro/specs/renewal-and-grave-registry/requirements.md` (or wherever AC1 actually lives — confirm exact filename via `ls .kiro/specs/renewal-and-grave-registry/` first, the spec cites "AC1" without naming the file)

**This task has no code dependency on Tasks 1-9 and can run any time** (though sequencing it last matches this plan's own narrative order).

- [ ] **Step 1: `AGENTS.md` §Mandatory MVP UX**

Change line 79 from:

```
- Booking exposes Steps 1–9 exactly as documented.
```

to:

```
- Booking exposes Steps 1–4 exactly as documented.
- (2 Sep 2026) This is a deliberate departure from the original 9-step
  count, which traced to RKS K23–K35 (the RKS source document itself is
  not in this repository — see `docs/planning/kiro-specs-analysis.md`'s
  "Conformance to RKS K23–K35 content: BLOCKED" note). Explicitly
  authorized by the project owner; see
  `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`
  Context section for the full record.
```

- [ ] **Step 2: `docs/design/design-system.md` §3.9**

Read the full section (lines 789-848 per this plan's research grep) before editing — it has multiple sub-parts (the stepper's prop table, the "nine-step default is normative" paragraph, the "labels is for a different journey" paragraph). Update:
- The section heading `### 3.9 Stepper — <x-mk.stepper> (booking Steps 1–9)` → `(booking Steps 1–4)`.
- The default `labels` array in the prose (wherever it's restated outside the component file itself) to the new 4-item map.
- "The nine-step default is normative" paragraph → rewritten for 4, keeping the same "omitting `labels` renders exactly these N steps" contract language.
- The renewal paragraph ("six visible steps") → rewritten for 3.
- The `labels` carve-out paragraph (line 845) currently reads "Passing `labels` from a booking surface to rename, reorder, hide, or renumber a booking step is forbidden..." — add an explicit exception for `BookingWizardScreen`/`RenewalWizardScreen`'s SCREEN-vocabulary labels (which this whole plan requires booking to now pass), distinguishing "a screen-grouping label array, sanctioned by this section" from "an ad hoc rename of a step," so a future reader does not read Task 6's own `:labels="BookingWizardScreen::labels()"` call as the exact violation this paragraph warns against.

- [ ] **Step 3: `docs/design/design-system.md` §9.2 MUST NOT 9**

Locate the exact MUST NOT 9 bullet (search `§9.2` region, line ~1527+ per this plan's grep of `## 9. Governance`). Rewrite from "hide/reorder/rename a booking step" (implicitly the old 9-step vocabulary) to the equivalent rule stated against the new 4-step vocabulary, plus the same `labels`-for-screens carve-out from Step 2.

- [ ] **Step 4: `docs/product/booking-wizard-fields.md`**

Read the full file (currently has 9 numbered `## Step N — <Name>` headings per this plan's research grep, lines 15-169). Restructure to 4 headings matching the new `BookingWizardStep::LABELS`: `## Step 1 — Cari & Pilih` (merging the content currently under the old Steps 1-4), `## Step 2 — Data Pemesan & Data Almarhum` (merging old Steps 6-7's content), `## Step 3 — Pembayaran` (old Step 8's content, unchanged), `## Step 4 — Konfirmasi` (old Step 9's content, unchanged). The OLD "## Step 5 — Ringkasan Pesanan" section's content becomes a non-numbered subsection (e.g. "### Ringkasan sidebar") describing it as a persistent display element, not a step — do not delete its field-level documentation, only its step-number framing.

- [ ] **Step 5: `.kiro/specs/renewal-and-grave-registry`'s AC1**

Run `ls .kiro/specs/renewal-and-grave-registry/` to find the exact file AC1 lives in (likely `requirements.md`). Per this plan's research: no established convention was found in `docs/planning/kiro-specs-analysis.md` for editing a `.kiro` spec in place — but that same document (and others like it) uses a `> **Superseded, <date>.**` marker pattern (see `docs/planning/kiro-specs-analysis.md`'s own line 3) to record a document's content as historically-accurate-but-no-longer-current, WITHOUT rewriting the original text. Apply the same pattern: add a note directly above/near AC1 reading:

```
> **Step count superseded, 2 Sep 2026.** AC1's "six visible steps" is the
> ORIGINAL count. The renewal journey now has 3 real steps —
> see `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`.
> This requirement is retained verbatim below for historical record.
```

Do not edit AC1's own text.

- [ ] **Step 6: Run the docs verification gate**

```bash
bash ci/verify-docs.sh
```

Expected: all gates pass — this script scans for hardcoded design values and (per this repo's CLAUDE.md) content-survival rules; confirm none of Steps 1-5's edits trip a gate (e.g. accidentally introducing a hardcoded Tailwind value while editing Markdown near a code fence is an easy, unrelated mistake to avoid here).

- [ ] **Step 7: Commit**

```bash
git add AGENTS.md docs/design/design-system.md docs/product/booking-wizard-fields.md .kiro/specs/renewal-and-grave-registry/
git commit -m "docs: amend step-count contracts for the wizard step reduction"
```

---

## Self-Review

**Spec coverage:** Decision 1 (Ringkasan cut) → Task 5 Step 7. Decision 2/7/9 (booking DISCOVERY merge) → Tasks 1, 3, 4, 6. Decision 3/4 (renewal merges) → Tasks 2, 7. Decision 5 (stepper tracks screens) → Tasks 1, 2, 6 — corrected from the spec's inaccurate "already screen-based" framing per this plan's Global Constraints. Decision 6 (in-flight draft unresumable) → Task 9, corrected to booking-only per the renewal persistence finding. Decision 8 (customer+deceased merge, accepted trade-off) → Tasks 1, 3, 5. `AGENTS.md`/`design-system.md`/`booking-wizard-fields.md`/`.kiro` amendments → Task 10, with the `.kiro` convention question resolved via the `> **Superseded**` precedent rather than left open. Test-file sweep → Task 8, with the real grep-discovered file list, not a placeholder instruction.

**Placeholder scan:** No "TBD"/"handle appropriately"/unshown code steps found on review — every code step above has real PHP, every test has real assertions against named fixtures (with explicit instructions to confirm exact fixture helper names against each file's current content, since this plan was written from partial-but-substantial reads, not the full 1500+/400+/360+-line files verbatim).

**Type consistency:** `saveStep1`'s new 5-arg signature is consistent between Task 4 (definition) and Task 6 (Blade call site update). `currentScreen(): int` return type consistent across Task 5 (definition) and Task 6 (consumption, unchanged signature). `BookingWizardScreen::labels()`/`RenewalWizardScreen::labels()` both return `array<int, string>`, consistent between Tasks 1/2 (definition) and Tasks 6/7 (consumption).

**Two complications found during research that changed this plan's shape from the spec's own framing, flagged for the coordinator's attention before dispatching Task 4 specifically:**
1. The plot-hold timing restructure (Task 4) is more invasive than "merge 4 calls into 1" — it moves WHEN a booking_drafts row gets its cemetery/service data written relative to when a real-world plot inventory hold is taken, and Task 4 Step 5 flags a genuine unresolved question about whether `PlotReservation::activeForDraft()`'s re-fetched model can still answer "did THIS request's hold succeed" the way the original `wasRecentlyCreated` flag could on the same-request object — this needs `HoldPlotForDraft`/`PlotReservation` read in full by Task 4's implementer before finalizing, not assumed from this plan's description alone.
2. The spec's Decision 5 and Decision 6 both contained real inaccuracies about the current code (stepper already screen-based; renewal has a persisted current_step to worry about) that this plan's research corrected. The spec document itself was NOT re-edited to fix these — this plan's Global Constraints section documents the corrections so the discrepancy is visible to anyone comparing spec against plan, rather than silently diverging.
