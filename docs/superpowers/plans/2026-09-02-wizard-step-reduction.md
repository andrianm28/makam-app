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

Update the class doc block to explain the 9→4 renumbering and point at the spec (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`) — a short "what changed and why, with a spec pointer" block, not a restatement of the whole spec.

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
            2 => 'Biaya & Bayar',
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
        self::FEE_AND_PAYMENT => 'Biaya & Bayar',
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
- Test: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`, `SaveBookingDraftStepServicesTest.php`, `SaveBookingDraftStepSteps678Test.php`, `SaveBookingDraftStepIdempotencyTest.php` (existing — update to the new step numbers/merged payload shapes). `tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php` is NOT touched by this task — read it and confirm: it tests `BookingDraft::create()`'s own model-level closed-list guards directly (e.g. `service_type`/`city_code` on the model, not through `SaveBookingDraftStep`) and has zero references to `BookingWizardStep` constants, so nothing here needs updating for this task.

**Interfaces:**
- Consumes: `BookingWizardStep::DISCOVERY`/`CUSTOMER_AND_DECEASED_DATA`/`PAYMENT`/`CONFIRMATION` from Task 1.
- Produces: `SaveBookingDraftStep::__invoke(BookingDraft $draft, int $step, array $payload, string $idempotencyKey, ?int $expectedVersion = null): BookingDraft` — SAME signature as today. `DISCOVERY`'s payload shape: `['city_code' => string, 'cemetery_id' => string, 'cemetery_package_id' => ?int, 'service_type' => string, 'selected_services' => list<array{code: string, quantity: int}>]`. `CUSTOMER_AND_DECEASED_DATA`'s payload shape: the union of the old `CUSTOMER_DATA` and `DECEASED_DATA` payload keys (`customer_full_name`, `customer_mobile`, `customer_email`, `customer_address`, `customer_relationship`, `customer_contact_channel`, `privacy_notice_accepted`, `deceased_full_name`, `deceased_date_of_birth`, `deceased_date_of_death`, `deceased_relationship`, `deceased_gender`).

**Real-code finding driving this task's design:** `validateCemetery()` (existing, ~line 300) reads `$draft->city_code` to cross-check the chosen cemetery is in the chosen city — this only works because in the OLD flow, `city_code` was already PERSISTED by a prior, separate `LOCATION` save before `CEMETERY` validated. In the merged `DISCOVERY` payload, `city_code` and `cemetery_id` arrive in the SAME call, before anything is persisted — `validateCemetery()`'s cross-check must read the payload's own `city_code`, not `$draft->city_code`. This is a real signature change to that private method, not just a call-site merge.

- [ ] **Step 1: Write the failing tests for the merged `DISCOVERY` validation**

**Real-code correction (post-audit):** neither `SaveBookingDraftStepTest.php` nor `SaveBookingDraftStepSteps678Test.php` has a shared `makeDraft()`/`makeDraftAtDiscoveryComplete()` helper — every existing test builds its fixture inline via `BookingDraft::create([...])`, seeding `completed_steps` directly with whichever prior steps the test needs (see e.g. `SaveBookingDraftStepSteps678Test.php`'s own `draftReadyForStepSix()`/`draftReadyForStepSeven()` private helpers, which are file-local, not shared). Also: `BookingServiceType` has NO `AT_NEED` constant — its real values are `NEW_GRAVE`, `OVERLAPPING_GRAVE`, `URGENT_TODAY`, `PRE_NEED` (`app/Domain/Booking/BookingServiceType.php`); use `BookingServiceType::NEW_GRAVE`. The two real basic service codes are `ServiceCode::DOCUMENT_PROCESSING` and `ServiceCode::GRAVE_DIGGING` (`app/Domain/ServiceCatalog/ServiceCode.php:85-88`) — `validateServices()` rejects a selection missing either one, so both must be present. The code below reflects these corrections, built from scratch against the real fixture patterns.

Add to `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepTest.php`:

```php
public function test_discovery_step_accepts_a_full_valid_payload_in_one_call(): void
{
    $cemetery = Cemetery::query()
        ->where('city', LaunchCityCode::JAKARTA)
        ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
        ->whereDoesntHave('packages')
        ->firstOrFail();

    $draft = BookingDraft::create([]);

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ],
        'idem-discovery-1',
    );

    $this->assertSame(LaunchCityCode::JAKARTA, $saved->city_code);
    $this->assertSame($cemetery->id, $saved->cemetery_id);
    $this->assertSame(BookingServiceType::NEW_GRAVE, $saved->service_type);
    $this->assertNotEmpty($saved->selected_services);
    $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $saved->current_step);
    $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
}

public function test_discovery_step_rejects_a_cemetery_outside_the_chosen_city_from_the_same_payload(): void
{
    $bogorCemetery = Cemetery::query()
        ->where('city', LaunchCityCode::BOGOR)
        ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
        ->firstOrFail();

    $draft = BookingDraft::create([]);

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $bogorCemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ],
        'idem-discovery-2',
    );
}

public function test_discovery_step_has_no_upstream_sequencing_requirement(): void
{
    // DISCOVERY is now the FIRST real step (like old LOCATION) — no
    // completed_steps precondition, unlike CUSTOMER_AND_DECEASED_DATA/PAYMENT.
    $cemetery = Cemetery::query()
        ->where('city', LaunchCityCode::JAKARTA)
        ->where('publication_status', CemeteryPublicationStatus::PUBLISHED)
        ->whereDoesntHave('packages')
        ->firstOrFail();

    $draft = BookingDraft::create([]);
    $this->assertSame([], $draft->completed_steps);

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::DISCOVERY,
        [
            'city_code' => LaunchCityCode::JAKARTA,
            'cemetery_id' => $cemetery->id,
            'cemetery_package_id' => null,
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ],
        'idem-discovery-3',
    );

    $this->assertContains(BookingWizardStep::DISCOVERY, $saved->completed_steps);
}
```

Add `use App\Domain\Booking\BookingServiceType;` to this file's imports if not already present (confirm during implementation — the file's current import list does not include it, since `service_type` was previously exercised via `BookingDraftClosedListValidationTest.php` at the model layer, not through raw constant references here).

Add to `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php`, reusing its own real `customerPayload()`/`deceasedPayload()` helpers (already present in the file, returning arrays keyed exactly as `SaveBookingDraftStep` expects, with real values: `BookingRelationshipCode::ANAK`/`::ORANG_TUA`, `BookingContactChannel::WHATSAPP`, `BookingGender::PEREMPUAN` — all confirmed real constants) and adding one new local helper for a DISCOVERY-complete draft, matching this file's existing `draftReadyForStepSix()`-style pattern:

```php
/**
 * A draft that has legitimately completed DISCOVERY (the new step 1),
 * which is the server-side precondition for saving
 * CUSTOMER_AND_DECEASED_DATA (the new step 2).
 */
private function draftReadyForCustomerAndDeceasedData(): BookingDraft
{
    return BookingDraft::create([
        'completed_steps' => [BookingWizardStep::DISCOVERY],
    ]);
}

public function test_customer_and_deceased_data_step_accepts_a_full_valid_combined_payload(): void
{
    $draft = $this->draftReadyForCustomerAndDeceasedData();

    $saved = (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        [...$this->customerPayload(), ...$this->deceasedPayload()],
        'idem-cadd-1',
    );

    $this->assertSame('Budi Santoso', $saved->customer_full_name);
    $this->assertSame('Siti Rahayu', $saved->deceased_full_name);
    $this->assertSame(BookingWizardStep::PAYMENT, $saved->current_step);
}

public function test_customer_and_deceased_data_step_rejects_when_either_half_is_invalid(): void
{
    $draft = $this->draftReadyForCustomerAndDeceasedData();

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        [
            ...$this->customerPayload(['customer_full_name' => '']), // invalid — triggers customer-half validation
            ...$this->deceasedPayload(),
        ],
        'idem-cadd-2',
    );
}

public function test_customer_and_deceased_data_step_requires_discovery_completed_first(): void
{
    $draft = BookingDraft::create([]); // fresh draft, DISCOVERY not yet done

    $this->expectException(BookingStepValidationException::class);

    (new SaveBookingDraftStep)(
        $draft,
        BookingWizardStep::CUSTOMER_AND_DECEASED_DATA,
        [...$this->customerPayload(), ...$this->deceasedPayload()],
        'idem-cadd-3',
    );
}
```

Confirm `customerPayload()`/`deceasedPayload()`'s exact return shapes by reading the file during implementation (they use array-spread with `$overrides`, so `[...$this->customerPayload(), ...$this->deceasedPayload()]` produces the full combined payload `SaveBookingDraftStep`'s `CUSTOMER_AND_DECEASED_DATA` branch expects) — this plan's snippets rely on their existing, real signatures rather than inventing new ones.

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

Run the full `tests/Feature/Domain/Booking/Actions/` directory (all 4 files listed in this task's Files section) plus `tests/Unit/Domain/Booking/BookingWizardStepTest.php`. Expected: every test passes — this includes updating the PRE-EXISTING tests in these files that reference old constants (`LOCATION`, `CEMETERY`, `SERVICE_TYPE`, `SUMMARY`, `CUSTOMER_DATA`, `DECEASED_DATA`) or call `validateCemetery` semantics no longer present; read each file, find every such reference, and update it to the new step numbers/merged payload shape rather than deleting coverage.

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

- [ ] **Step 1: Write the failing tests for the merged save method's happy path and the (deliberately) non-releasing failure path**

Read `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php` in full first. **Real-code correction (post-audit):** this file has no shared `$this->cemeteryId`/`$this->plotId`/`$this->basicServiceCode` properties — every existing test builds its own fixtures locally via the file's private `makeCemetery(string $trackingMode, ...)` and `makePlotIn(Cemetery $cemetery)` helpers, using local variables. `BookingServiceType::NEW_GRAVE` (not `'AT_NEED'`, which doesn't exist) and both `ServiceCode::DOCUMENT_PROCESSING`/`ServiceCode::GRAVE_DIGGING` (not a single fabricated code) are required, same correction as Task 3. Also, per the "Dropped: `releaseHeldPlotIfAny()`" decision in Step 4 above, the third test below asserts the OPPOSITE of what an earlier draft of this task described — the hold is deliberately NOT released on an unrelated validation failure. Add:

```php
public function test_save_step1_persists_all_five_discovery_fields_in_one_call(): void
{
    $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);

    $component = Livewire::test(BookingWizard::class)
        ->call('saveStep1', LaunchCityCode::JAKARTA, $cemetery->id, null, BookingServiceType::NEW_GRAVE, [
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
        ]);

    $component->assertHasNoErrors();

    $draft = BookingDraft::query()->latest()->first();
    $this->assertSame(LaunchCityCode::JAKARTA, $draft->city_code);
    $this->assertSame($cemetery->id, $draft->cemetery_id);
    $this->assertSame(BookingServiceType::NEW_GRAVE, $draft->service_type);
    $this->assertSame(BookingWizardStep::CUSTOMER_AND_DECEASED_DATA, $draft->current_step);
}

public function test_holding_a_plot_does_not_immediately_persist_the_draft(): void
{
    $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
    $plot = $this->makePlotIn($cemetery);

    Livewire::test(BookingWizard::class)
        ->call('openPickerFor', $cemetery->id)
        ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey());

    // The hold exists (and, per the second real-code finding in Step 5
    // above, a BookingDraft row now exists too — that row is required for
    // the hold's own FK — but nothing DISCOVERY-shaped was persisted onto
    // it yet)...
    $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey()]);
    // ...DISCOVERY is not complete until service type + services are also
    // chosen, so the draft's cemetery_id is still unset.
    $this->assertDatabaseMissing('booking_drafts', ['cemetery_id' => $cemetery->id]);
}

public function test_a_failed_discovery_save_does_not_release_the_hold(): void
{
    $cemetery = $this->makeCemetery(PlotTrackingMode::GRANULAR);
    $plot = $this->makePlotIn($cemetery);

    Livewire::test(BookingWizard::class)
        ->call('openPickerFor', $cemetery->id)
        ->call('holdPlotForDiscovery', $cemetery->id, null, (string) $plot->getKey())
        ->call('saveStep1', '', $cemetery->id, null, BookingServiceType::NEW_GRAVE, [
            ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
            ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
        ]) // empty city_code — fails validateLocation(), unrelated to the plot pick
        ->assertHasErrors(['city_code']);

    // Deliberately still HELD — see "Dropped: releaseHeldPlotIfAny()" in
    // Step 4 above. A typo in an unrelated field must not cost the
    // customer their already-held plot; the scheduled TTL sweep is the
    // safety net for a truly abandoned attempt, not this failure path.
    // `PlotReservationState::HELD` is the lowercase string `'held'` — use
    // the constant, not a hardcoded literal, to avoid a casing mismatch.
    $this->assertDatabaseHas('plot_reservations', ['plot_id' => $plot->getKey(), 'state' => PlotReservationState::HELD]);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Expected: FAIL — `saveStep1` doesn't accept 5 arguments yet.

- [ ] **Step 3: Confirmed real property names (post-audit — verified directly against `BookingWizard.php`'s declarations, lines ~93-181)**

All properties this task needs already exist — nothing to add: `public string $city = ''` (NOT `$cityCode`), `public ?string $cemeteryId = null`, `public ?int $cemeteryPackageId = null`, `public ?string $serviceType = null`, `public array $selectedServices = []`, `public array $stagedServiceCodes = []` (the picker's staged checkbox state — distinct from `$selectedServices`, which is the already-persisted shape), `public array $completedSteps = []`, `public int $currentStep`, `public int $version = 1`. `$pickerCemeteryId`/`$pickerCemeteryPackageId` (used by `openPickerFor()`) ARE distinct properties from `$cemeteryId`/`$cemeteryPackageId` — the picker properties track which cemetery's picker is currently open, the plain ones track the customer's confirmed selection. `holdPlotForStep2()`'s restructure (Step 5 below) reuses all of these; no new properties are needed.

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
    } catch (BookingDraftVersionConflictException) {
        $this->handleVersionConflict();
    }
}

public function continueFromDiscovery(): void
{
    $this->saveStep1(
        $this->city,
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

**Property name fix (post-audit):** the real property is `$this->city` (`public string $city = ''`), NOT `$this->cityCode` — the snippet above is corrected; an earlier draft of this task used the wrong name, which would have thrown a `TypeError` at runtime (an undeclared-property read passed into `saveStep1(string $cityCode, ...)`'s non-nullable parameter). See Step 3's confirmed property list above.

**Dropped: `releaseHeldPlotIfAny()`.** An earlier draft of this task called a `releaseHeldPlotIfAny()` helper from both catch blocks above, re-fetching the hold via `PlotReservation::activeForDraft()` and checking `$hold->wasRecentlyCreated`. That check is broken by construction: `wasRecentlyCreated` only means anything on the exact Eloquent instance returned by the `->create()` call that inserted a row — any later re-fetch via a query (which is what `activeForDraft()` does) always returns `false` for it, even for a hold created moments earlier in a prior request. Since `DISCOVERY`'s save is now deferred until service type + services are also chosen (not immediate, unlike the old `saveStep2()`), any release-on-failure logic here would be re-fetching in a later request and hitting exactly this false-negative.

Rather than work around the flag (e.g. carrying the hold's own return value across requests in a Livewire property), this task deliberately does NOT auto-release the hold when a `DISCOVERY` save fails for an unrelated reason (e.g. a `city_code` typo) — releasing it would strip away a perfectly valid plot pick over a mistake in a different field entirely, now that four sub-choices share one save/validate unit instead of one. The existing scheduled command `plot-reservation:expire-stale-draft-holds` (`app/Console/Commands/PlotReservationExpireStaleDraftHoldsCommand.php`, registered in `routes/console.php` as `Schedule::command('plot-reservation:expire-stale-draft-holds')->everyMinute()->withoutOverlapping()`) is the safety net for a truly abandoned attempt — confirmed to exist and to actually run on a schedule, not just exist as unused code. A customer who fixes their typo and resubmits keeps their held plot; a customer who genuinely walks away has their hold swept within a minute of its TTL, same as any other abandoned hold. `HoldPlotForDraft`'s own release-and-reacquire logic (unchanged, see its class doc block) still handles the one case that DOES need an explicit release: the customer picking a DIFFERENT plot than one they already hold.

- [ ] **Step 5: Restructure `holdPlotForStep2()` to stop calling a `SaveBookingDraftStep`-backed save, and to create the draft eagerly if none exists yet**

Rename to `holdPlotForDiscovery()` (the method no longer corresponds to a numbered "step 2"). Remove the trailing `$this->saveStep2($cemeteryId, $cemeteryPackageId);` call and its `wasRecentlyCreated`/`autosaveState === 'failed'` release-on-failure block entirely — per the "Dropped: `releaseHeldPlotIfAny()`" note in Step 4 above, no release-on-failure logic is added back here either; the hold simply stays open (converted later by `SubmitBookingDraft`'s existing chain on success, or swept by the scheduled `plot-reservation:expire-stale-draft-holds` command if the attempt is truly abandoned).

**Second real-code gap found while re-verifying this task (not in any of the three audit reports — found independently while checking the method's real precondition):** the CURRENT `holdPlotForStep2()` starts with `if ($this->draftId === null) { ...error...; return; }` — it REQUIRES a draft to already exist. That precondition held under the old flow because `saveStep1()` (old Step 1, Lokasi) always ran first and created the draft before the customer could ever reach the cemetery/plot picker. Under the `DISCOVERY` merge, NOTHING creates the draft until the final combined `saveStep1()` call — which fires only once service type and services are ALSO chosen, i.e. strictly AFTER the plot pick. `HoldPlotForDraft` requires a real `BookingDraft $draft` row to attach the reservation to (its FK), so `holdPlotForDiscovery()` cannot simply wait for one to already exist. Fix: `holdPlotForDiscovery()` must itself lazily create the draft on first use, the same way the OLD `saveStep1()` used to — via `currentOrNewDraft()` — but WITHOUT persisting any `DISCOVERY` fields onto it yet (those still wait for the final combined save) and WITHOUT redirecting to the resumable draft URL yet (that redirect stays exclusively in the final `saveStep1()`, so the customer isn't navigated away mid-selection).

```php
public function holdPlotForDiscovery(string $cemeteryId, ?int $cemeteryPackageId, string $plotId): void
{
    if ($this->draftId === null) {
        // First time this component needs a draft row to exist (a plot
        // hold's FK requires one) — create it silently now, matching what
        // the old saveStep1() used to do, but without persisting any
        // DISCOVERY field yet and without redirecting: the customer is
        // still mid-selection, not done with this step.
        $draft = $this->currentOrNewDraft();
        $this->draftId = $draft->getKey();
        $this->version = $draft->version;
    } else {
        $draft = BookingDraftQuery::findBound($this->draftId);

        if ($draft === null) {
            $this->autosaveState = 'failed';
            $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

            return;
        }
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

    $this->cemeteryId = $cemeteryId;
    $this->cemeteryPackageId = $cemeteryPackageId;
}
```

Confirm `currentOrNewDraft()`'s real body during implementation (already read for this plan — it checks `$this->draftId !== null` and resolves via `resolveDraftById()` first, falling back to `(new StartBookingDraft)(auth()->id())` — a genuinely empty new draft, no arguments about DISCOVERY fields) still behaves correctly when called from this new call site rather than only from `saveStep1()`.

- [ ] **Step 6: Update the call-site targets in `wizard.blade.php` for the renamed/merged methods** (the Blade edits themselves are Task 6 — this step is a cross-reference note only; do not edit the Blade file in this task). Search for every occurrence of the old method names in ALL their Livewire directive forms, not just `wire:click` — the real file uses `wire:submit="saveStep6"`/`wire:submit="saveStep7"` for the customer/deceased-data forms (Task 5's territory) and, specific to this task's methods, both `wire:click` AND `wire:target` (which drives loading-spinner state and does not imply a click) reference `saveStep1`/`saveStep2`/`saveStep3`/`saveStep4`/`continueFromStep4`/`holdPlotForStep2` in multiple places. Grep the real file for all four patterns — `wire:click="saveStep1"` through `saveStep4"`, `continueFromStep4`, `holdPlotForStep2`, and `wire:target="` followed by any of those same names — and list every match (file:line) so Task 6 has the complete, real set of call sites, not just the `wire:click` ones.

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

- [ ] **Step 4: Redesign Screen 1/DISCOVERY's progressive-reveal gates — real design gap found post-audit, not a mechanical rename**

**This is the largest, most important correction in this whole plan.** An earlier draft of this task assumed the sub-choice buttons inside the merged DISCOVERY step could simply be "repointed to their new method name." That doesn't work. Read the real current file (confirmed by direct grep, current line numbers as of this correction pass — re-confirm exact numbers during implementation since earlier tasks' edits shift them):

- Line 114: `@if ($currentStep === BookingWizardStep::LOCATION || in_array(BookingWizardStep::LOCATION, $completedSteps, true))` gates the city section.
- Line 137: `wire:click="saveStep1('{{ $cityOption['code'] }}')"` — the city buttons, which TODAY persist and advance `$currentStep` to `CEMETERY`, which is what reveals the next section.
- Line 153: `@if ($currentStep === BookingWizardStep::CEMETERY || ...)` gates the cemetery section — only reveals because the city button above just advanced `$currentStep`.
- Lines 284/313: `wire:click="saveStep2(...)"` — cemetery/package buttons, same persist-and-advance pattern.
- Line 369: `wire:click="holdPlotForStep2(...)"` — the plot-picker's confirm button (renamed `holdPlotForDiscovery` per Task 4).
- Line 418: `@if ($currentStep === BookingWizardStep::SERVICE_TYPE || ...)` gates the service-type section.
- Line 429: `wire:click="saveStep3('{{ $type }}')"` — service-type buttons, same pattern.
- Line 448: `@if ($currentStep === BookingWizardStep::SERVICES || ...)` gates the services section.
- Line 603: `wire:click="continueFromStep4"` — the final "Lanjutkan" button.

Every one of `LOCATION`/`CEMETERY`/`SERVICE_TYPE`/`SERVICES` is DELETED by Task 1 — these `@if` blocks reference constants that no longer exist, so this is not a soft UX regression to leave for later, it is a guaranteed fatal error (`Undefined constant`) the moment Task 1 lands without this fix. And even setting the compile error aside: under the merge, `$currentStep` never advances until the ONE final `continueFromDiscovery()` → `saveStep1()` call succeeds (Task 4) — so a currentStep-driven gate would stay frozen after the very first sub-choice even if it somehow still compiled.

**The fix: mirror `RenewalStart.php`'s already-proven pattern exactly** — local, non-persisting property-driven reveal, not step-driven reveal. `RenewalStart.php` already solves this identical problem for its own merged search step: `selectCity(string $city): void`/`resetCity(): void`/`selectCemetery(string $cemeteryId): void`/`resetCemetery(): void` (methods) paired with `@if ($city !== '')`/`@if ($selectedCemetery !== null)` (Blade gates) — read that file's real methods and `resources/views/livewire/public/renewal/start.blade.php`'s real gates before writing this task's edits, and copy the shape.

Add three new lightweight, non-persisting setter methods to `BookingWizard.php` (near `openPickerFor()`/`holdPlotForDiscovery()`):

```php
public function selectCity(string $cityCode): void
{
    $this->city = $cityCode;
}

/**
 * Non-picker cemetery selection (a cemetery with no active packages, or a
 * package chosen directly without the plot picker) — sets the confirmed
 * selection properties directly. `holdPlotForDiscovery()` (Task 4) sets
 * these same two properties for the picker path, so both paths converge
 * on the same reveal-gate condition below.
 */
public function selectCemetery(string $cemeteryId, ?int $cemeteryPackageId = null): void
{
    $this->cemeteryId = $cemeteryId;
    $this->cemeteryPackageId = $cemeteryPackageId;
}

public function selectServiceType(string $serviceType): void
{
    $this->serviceType = $serviceType;
}
```

Update the Blade gates and buttons:

```blade
{{-- was: @if ($currentStep === BookingWizardStep::LOCATION || in_array(BookingWizardStep::LOCATION, $completedSteps, true)) --}}
{{-- The city section is the first thing in DISCOVERY — always visible, no gate needed. Remove the @if/@endif wrapper entirely (keep its content). --}}
```

```blade
{{-- city buttons: was wire:click="saveStep1('{{ $cityOption['code'] }}')" --}}
wire:click="selectCity('{{ $cityOption['code'] }}')"
```

```blade
{{-- was: @if ($currentStep === BookingWizardStep::CEMETERY || in_array(BookingWizardStep::CEMETERY, $completedSteps, true)) --}}
@if ($city !== '')
```

```blade
{{-- non-picker cemetery/package buttons: was wire:click="saveStep2('{{ $cemetery->id }}')" / wire:click="saveStep2('{{ $cemetery->id }}', {{ $package->id }})" --}}
wire:click="selectCemetery('{{ $cemetery->id }}')"
{{-- and --}}
wire:click="selectCemetery('{{ $cemetery->id }}', {{ $package->id }})"
```

```blade
{{-- plot-picker confirm button: was wire:click="holdPlotForStep2('{{ $this->pickerCemeteryId }}', {{ $this->pickerCemeteryPackageId ?? 'null' }}, '{{ $plot->id }}')" --}}
wire:click="holdPlotForDiscovery('{{ $this->pickerCemeteryId }}', {{ $this->pickerCemeteryPackageId ?? 'null' }}, '{{ $plot->id }}')"
```

```blade
{{-- was: @if ($currentStep === BookingWizardStep::SERVICE_TYPE || in_array(BookingWizardStep::SERVICE_TYPE, $completedSteps, true)) --}}
@if ($cemeteryId !== null)
```

```blade
{{-- service-type buttons: was wire:click="saveStep3('{{ $type }}')" --}}
wire:click="selectServiceType('{{ $type }}')"
```

```blade
{{-- was: @if ($currentStep === BookingWizardStep::SERVICES || in_array(BookingWizardStep::SERVICES, $completedSteps, true)) --}}
@if ($serviceType !== null)
```

The final "Lanjutkan" button (line 603) keeps its position at the end of the services section, `wire:click="continueFromDiscovery"` (renamed per Task 4) — this is the ONLY control in the whole DISCOVERY screen that actually calls `SaveBookingDraftStep`, validates, and persists; everything above it is now local UI state.

- [ ] **Step 5: Update the remaining `@if ($this->currentScreen() === N)` blocks and their internal step-label markup**

Read the full file and update every remaining reference to removed constants/methods: the `wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SUMMARY }})"` call (previously line 876 — `SUMMARY` no longer exists; since Ringkasan is now an unconditional sidebar per Task 5 Step 7, this specific "go to Ringkasan" link/button should be removed entirely, not repointed — there is no step to navigate to), the "Langkah 5 — Ringkasan Pesanan" heading text (previously line 627, part of the sidebar markup — keep the Ringkasan CONTENT, drop the "Langkah 5" step-number framing since it's not a numbered step anymore), and every remaining `wire:click`/`wire:submit`/`wire:target="saveStep6"`/`"saveStep7"`/`"saveStep8"` call site found via Task 4 Step 6's broadened cross-reference list — repoint each to its new method name (`saveStep2` for customer/deceased, `saveStep3` for payment).

- [ ] **Step 6: Run tests to verify they pass**

Run both files in this task's Files section, plus `BookingWizardEndToEndTest.php` (this is the most likely file to catch any missed Blade call-site rename via a real end-to-end journey failing partway through) and `BookingWizardProgressiveRevealTest.php` (Task 5's file — the new local-property reveal gates this task adds are exactly what that file's own name suggests it should also verify; check whether it needs new assertions for the `selectCity`/`selectCemetery`/`selectServiceType` reveal chain).

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/booking/wizard.blade.php \
  tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php
git commit -m "fix(booking): pass BookingWizardScreen labels to the stepper, update Blade call sites for the merged steps"
```

---

### Task 7: Renewal components — renumber to 3 steps, swap to `RenewalWizardScreen` labels

**Files:**
- Modify: `app/Livewire/Public/Renewal/RenewalStart.php`, `app/Livewire/Public/Renewal/RenewalPayment.php`, `app/Livewire/Public/Renewal/RenewalConfirmation.php`
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (existing — update, including a stale test that asserts the OLD 6-label set, see Step 7), `tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php` (existing, real file, confirmed to assert the OLD 6-label set at two points — see Step 6), and any `RenewalConfirmation` test file found during Step 5's read (this file's own `stepLabels` reference was also missed in an earlier draft of this task — see Step 5).

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

- [ ] **Step 5: Update `RenewalConfirmation.php` — real missed reference (post-audit)**

An earlier draft of this task claimed no `RenewalJourneyStep::` matches existed in this 50-line file. That was wrong — line 44 reads `'stepLabels' => RenewalJourneyStep::labels(),`, the same pattern already fixed in `RenewalStart.php`/`RenewalPayment.php` above, just missed here. `RenewalJourneyStep::CONFIRMATION` (line 43, correctly unaffected by the value change) stays as-is. Fix:

```php
return view('livewire.public.renewal.confirmation', [
    'renewal' => $renewal,
    'errorMessage' => $renewal instanceof Renewal ? '' : 'Data perpanjangan tidak ditemukan.',
    'currentStep' => RenewalJourneyStep::CONFIRMATION,
    'stepLabels' => RenewalWizardScreen::labels(),
])->layout('layouts.app', [
```

Add `use App\Domain\Renewal\RenewalWizardScreen;` to this file's imports.

- [ ] **Step 6: Fix `RenewalPaymentTest.php` — real file, breaks on the old 6-label assertion (post-audit)**

An earlier draft of this task hedged "confirm during implementation" on whether this file exists. It does — `tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php` — and it asserts the OLD 6-item label set at two points: line 162 and line 539, both something like `assertSame(['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'], ...)` or equivalent `assertSee()` calls for each old label. Read both locations in full during implementation and update each to assert the new 3-item `RenewalWizardScreen::labels()` set (`'Cari Makam'`, `'Biaya & Bayar'`, `'Konfirmasi'`) instead — do not delete this coverage, update it to match what actually renders now.

- [ ] **Step 7: Fix the stale conflicting test in `RenewalStartTest.php` — real, currently-passing test that this change breaks (post-audit)**

`RenewalStartTest.php` (line ~222) has a real, currently-passing test — approximately named `test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones` — asserting all 6 OLD labels render (`'Kota'`, `'TPU/TPS'`, `'Cari Makam'`, `'Biaya'`, `'Pembayaran'`, `'Konfirmasi'`). An earlier draft of this task only ADDED a new 3-label test (Step 1 above) and never touched this one — it would fail post-change since none of those 6 labels render anymore. Read the test's real name and full body during implementation; its actual intent (proving the renewal stepper never accidentally shows booking's labels, not the specific number six) survives this change perfectly well — rewrite it to assert the NEW 3-item label set instead of deleting the coverage, e.g. renaming it to something like `test_the_stepper_shows_this_journeys_three_steps_not_the_nine_booking_ones` and asserting `'Cari Makam'`/`'Biaya & Bayar'`/`'Konfirmasi'` render while a real booking-only label (e.g. `'Pilih Layanan'` or `'Data Pemesan'`, whichever this repo's real booking labels are post-Task-1) does not.

- [ ] **Step 8: Run tests to verify they pass**

Run `RenewalStartTest.php`, `RenewalPaymentTest.php`, and any `RenewalConfirmation` test file found during Step 5's re-check.

- [ ] **Step 9: Commit**

```bash
git add app/Livewire/Public/Renewal/RenewalStart.php app/Livewire/Public/Renewal/RenewalPayment.php \
  app/Livewire/Public/Renewal/RenewalConfirmation.php tests/Feature/Livewire/Public/Renewal/
git commit -m "feat(renewal): renumber to 3 steps, swap stepper labels to RenewalWizardScreen"
```

---

### Task 8: Sweep remaining booking test files for old step-number/method-name references

**Files:**
- Modify (as needed, based on what each file actually contains): `tests/Feature/Domain/Booking/Actions/StartBookingDraftTest.php`, `tests/Feature/Domain/PlotReservation/HoldPlotForDraftTwoConnectionTest.php`, `HoldPlotForDraftTest.php`, `PlotReservationBookingDraftHoldTest.php`, `ConvertDraftHoldToOrderReservationTest.php`, `tests/Feature/Domain/Quotation/ComposeQuoteLinesFromBookingDraftTest.php`, `tests/Feature/Domain/PlotReservation/PlotReservationExpiryTest.php`, `tests/Feature/OrderWorkflow/SubmitBookingDraftConvertsPlotHoldTest.php`, `tests/Feature/Livewire/Public/Booking/BookingWizardDegradedReadsTest.php`, `BookingWizardSaveIntegrityTest.php`, `BookingWizardExpiredHoldOnSubmitTest.php`, `BookingWizardCheckboxStylingTest.php`, `tests/Feature/Livewire/Public/Akun/DraftListTest.php`
- **`tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php` — real file, missed by earlier drafts of this plan (post-audit correction), covered by no other task.** Confirmed via direct read: uses `BookingWizardStep::LOCATION`/`CEMETERY`/`SERVICE_TYPE`/`SUMMARY` (all removed) and calls `saveStep4()`/`saveStep1()` with their OLD signatures throughout. This is squarely this task's territory — fix its fixture-building and method calls to the new `DISCOVERY`/merged-signature shape, same as the rest of this task's files.
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

**Real-code correction (post-audit):** `BookingDraft` has no `HasFactory` trait and no factory class exists under `database/factories/` — confirmed by direct search. Every existing test builds a `BookingDraft` via direct `BookingDraft::create([...])`, e.g. `BookingWizardDraftBindingTest.php`'s own fixtures — use that pattern, not a factory.

```php
public function test_a_draft_at_an_old_out_of_range_current_step_is_treated_as_unresumable(): void
{
    // Simulate a draft that was mid-flow under the OLD 9-step numbering
    // when this change shipped — current_step = 8 (old PAYMENT) has no
    // meaning under the new 4-step BookingWizardStep::isKnown() range.
    $draft = BookingDraft::create(['current_step' => 8, 'completed_steps' => [1, 2, 3, 4, 6, 7]]);

    $component = Livewire::test(BookingWizard::class, ['draftId' => $draft->id]);

    $component->assertSee('Sesi pemesanan Anda telah berakhir');
}
```

- [ ] **Step 2: Run test to verify it fails**

Expected: FAIL — today the component likely tries to hydrate `$currentStep = 8` directly and either 500s or renders a blank/incorrect screen, since nothing currently checks the resumed value against `BookingWizardStep::isKnown()`.

- [ ] **Step 3: Add the guard**

Locate where a resumed draft's `current_step` is read into the component — `mount(?string $draftId = null)`, after `$draft = $this->resolveDraftById($draftId);` resolves a non-null draft, before `$this->hydrateFrom($draft);` runs. `mount()`'s own two existing "no usable draft" branches (`$draftId === null`, and `$draft === null`) both set `$this->stagedServiceCodes = ServiceCode::BASIC_CODES;` before returning — this new branch is a third "no usable draft" case in the same family and must do the same, or a customer hitting it lands on an empty-checkbox discovery screen instead of the default pre-checked state (**real gap found post-audit** — an earlier draft of this task's guard omitted this reset). Add:

```php
if (! BookingWizardStep::isKnown($draft->current_step)) {
    BookingDraftBinding::forget($draftId);
    $this->draftId = null;
    $this->stagedServiceCodes = ServiceCode::BASIC_CODES;
    $this->addError('draft', 'Sesi pemesanan Anda telah berakhir. Silakan mulai ulang.');

    return;
}
```

placed immediately after `$draft = $this->resolveDraftById($draftId);` resolves a non-null `$draft`, before `$this->hydrateFrom($draft);` runs — mirroring the existing "session expired" pattern already used in `saveStepOrShowErrors()`/`saveStep1()`'s own null-draft branches (including the `BookingDraftBinding::forget()` call the adjacent `$draft === null` branch already makes, for the same reason: an old-numbered draft is being treated as equally unusable as a missing one, so it gets the same cleanup), so this reads as the same family of error rather than a new one.

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
- Modify: `.kiro/specs/renewal-and-grave-registry/requirements.md` (AC1's real location, confirmed by direct read) and `.kiro/specs/renewal-and-grave-registry/tasks.md` (real, currently-uncovered content that becomes factually wrong by this plan — see Step 6 below; missed by an earlier draft of this task, which only listed `requirements.md`).

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

- [ ] **Step 2: `docs/design/design-system.md` §3.9 — full real text read, every stale reference found (post-audit correction: an earlier draft of this task's edit list covered only 4 of the ~10 real stale references in this section)**

Read the full section (real current lines 789-848) before editing. Every "9" / "1–9" / "six" reference found by direct read, each needing its own fix:

1. Line 789 heading: `### 3.9 Stepper — <x-mk.stepper> (booking Steps 1–9)` → `(booking Steps 1–4)`.
2. Line 791: `` `booking-wizard-fields.md` requires progress shown as **1–9**, ... `` → `**1–4**`.
3. Lines 793-796, the canonical labels block:
   ```
   1 Lokasi · 2 TPU/TPS · 3 Jenis Layanan · 4 Pilih Layanan · 5 Ringkasan
   6 Data Pemesan · 7 Data Almarhum + Dokumen · 8 Pembayaran · 9 Konfirmasi
   ```
   → replace with the new 4 headings (matching `BookingWizardStep::LABELS`, Task 1):
   ```
   1 Cari & Pilih · 2 Data Pemesan & Data Almarhum · 3 Pembayaran · 4 Konfirmasi
   ```
4. Line 799: "A full 9-dot rail does not fit 360 px legibly." — the mobile-compact rationale itself (a 9-dot rail not fitting 360px) no longer applies verbatim once the rail is 4 dots; reword to something like "The compact mobile layout below still applies for any journey with more steps than fit legibly at 360px — not specific to booking's dot count." so the section doesn't imply a specific dot count drove the mobile design.
5. Lines 800-806, the mobile mockup block — currently shows `Langkah 3 dari 9` / `Jenis Layanan` / a progress bar at roughly 1/3 fill. Update the worked example to a real 4-step value, e.g. `Langkah 2 dari 4` / `Data Pemesan & Data Almarhum` (screen 2's real new label) with the fill bar adjusted to roughly 1/2.
6. Line 810: `` `aria-valuenow="3" aria-valuemin="1" aria-valuemax="9"` `` → update to a value consistent with the new mockup, e.g. `aria-valuenow="2" aria-valuemin="1" aria-valuemax="4"`.
7. Line 832 (Urgent/Pre-Need branching paragraph): "the stepper still reads 1–9" → "the stepper still reads 1–4".
8. Props table `labels` default: "**the nine booking labels above**" → "**the four booking labels above**".
9. "The nine-step default is normative." paragraph → rewritten for four, keeping the same "omitting `labels` renders exactly these N steps" contract language — but note this paragraph's claim needs a further correction beyond the number: per this plan's Decision 5/Task 6, booking's Blade view is changing from OMITTING `labels` (relying on the default) to EXPLICITLY passing `BookingWizardScreen::labels()`. So the "normative default" now describes a fallback booking itself no longer actually relies on in practice — word this precisely (the default still exists and still matters as the safety net/contract for any future caller that omits `labels`, but booking's own real invocation no longer omits it after this change) rather than implying booking still depends on the omitted-prop default.
10. The renewal paragraph ("`labels` is for a different journey... is **six** visible steps") → rewritten for **three**, and its citation of `.kiro/specs/renewal-and-grave-registry`'s `tasks.md` "requires this same primitive" stays accurate (the primitive itself — `<x-mk.stepper>`'s `labels` prop — is unchanged, only the count).
11. The `labels` carve-out paragraph (same paragraph as #10, continuing) currently reads "Passing `labels` from a booking surface to rename, reorder, hide, or renumber a booking step is forbidden by `AGENTS.md` (§Mandatory MVP UX, \"Booking exposes Steps 1–9 exactly as documented\")..." — update the quoted `AGENTS.md` text to "Steps 1–4" (matching Step 1's edit above) and add an explicit exception distinguishing `BookingWizardScreen`/`RenewalWizardScreen`'s SCREEN-vocabulary labels (which this whole plan requires booking to now pass) from an ad hoc rename of a STEP — so a future reader does not read Task 6's own `:labels="BookingWizardScreen::labels()"` call as the exact violation this paragraph warns against. Also fix "Urgent / Pre-Need branches keep reading 1–9" (same paragraph, final sentence) → "1–4".

- [ ] **Step 3: `docs/design/design-system.md` §9.2 MUST NOT list, item 9 — real text is NOT step-count-specific, needs a different fix than "rewrite the number"**

**Real-code correction (post-audit):** an earlier draft of this task assumed MUST NOT item 9 contains an embedded step count to rewrite (framed as "hide/reorder/rename a booking step, implicitly the old 9-step vocabulary"). Read directly (§9.2, MUST NOT list, item 9): `` ❌ Rename, reorder, or hide a product label, route, menu item, or booking step (§0.1). `` — this is a general prohibition with no number in it at all; there is nothing to numerically update. What it actually needs is the same kind of dated, explicit exception the SAME governance list already uses elsewhere for a deliberate, approved departure — the "Rules for developers" numbered list's own item 9 (a *different* item 9, in the adjacent list) already carries exactly this pattern: `` ~~Keep Filament's PHP colour array in sync...~~ **Superseded 26 Aug 2026:** admin/vendor Filament panels no longer consume this array at all... `` Add the equivalent note to the MUST NOT list's item 9, without striking through the rule itself (the rule still holds in general — this plan is the one, explicitly-authorized exception to it, not a repeal):

```
9. ❌ Rename, reorder, or hide a product label, route, menu item, or booking step (§0.1). **Exception, 2 Sep 2026:** the wizard step-count reduction (`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`) is a deliberate, project-owner-authorized departure — see §3.9's own updated step count and the `AGENTS.md` note this plan's Task 10 Step 1 adds. This item's general rule is otherwise unchanged.
```

- [ ] **Step 4: `docs/design/design-system.md` §3.2 — one more stale reference found (post-audit), not caught by earlier drafts of this task**

Line 367 (§3.2, form-field rules): `` Optional fields are labelled `(opsional)` — for a 9-step form, marking the smaller set is kinder. `` → update "9-step form" to "4-step form" (this line is about booking's field-labelling convention specifically, confirmed by its surrounding context in §3.2's form-field rules).

- [ ] **Step 5: `docs/product/booking-wizard-fields.md`**

Read the full file (currently has 9 numbered `## Step N — <Name>` headings per this plan's research grep, lines 15-169). Restructure to 4 headings matching the new `BookingWizardStep::LABELS`: `## Step 1 — Cari & Pilih` (merging the content currently under the old Steps 1-4), `## Step 2 — Data Pemesan & Data Almarhum` (merging old Steps 6-7's content), `## Step 3 — Pembayaran` (old Step 8's content, unchanged), `## Step 4 — Konfirmasi` (old Step 9's content, unchanged). The OLD "## Step 5 — Ringkasan Pesanan" section's content becomes a non-numbered subsection (e.g. "### Ringkasan sidebar") describing it as a persistent display element, not a step — do not delete its field-level documentation, only its step-number framing.

- [ ] **Step 6: `.kiro/specs/renewal-and-grave-registry` — AC1 in `requirements.md`, AND `tasks.md` (real, factually-broken content found post-audit, not covered by an earlier draft of this task)**

**`requirements.md`'s real AC1** (line 9): `` 1. THE SYSTEM SHALL implement the public renewal flow as six visible steps: city, TPU/TPS, grave search, fee, payment, and confirmation/invoice. `` This repo's real, established convention for superseding a `.kiro` requirement in place — confirmed by reading `.kiro/specs/platform-identity-and-access/requirements.md` directly, not assumed — is strikethrough on the original line plus a pointer, plus a dedicated `## Superseded (DATE)` section (this file currently has none; add one). Apply the same shape:

```
1. ~~THE SYSTEM SHALL implement the public renewal flow as six visible steps: city, TPU/TPS, grave search, fee, payment, and confirmation/invoice.~~ Superseded 2 Sep 2026 — see the `## Superseded` section below.
```

Add, at the end of the file:

```
## Superseded (2 Sep 2026)

AC1's "six visible steps" is superseded by a deliberate, project-owner-authorized step-count
reduction to three real steps (search, fee & payment, confirmation) — see
`docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md` for the full record, including
the explicit authorization to depart from the RKS-sourced step count this AC originally encoded.

Per `AGENTS.md`'s source-precedence order, this spec outranks the code — this note is that
approval, mirroring the shape `platform-identity-and-access/requirements.md`'s own
`## Superseded (22 Aug 2026)` section uses for its MFA-removal precedent.
```

**`tasks.md` — real, currently-broken-by-this-plan content, missed entirely by an earlier draft of this task.** This file documents "six visible steps"/"six-step stepper" extensively (at minimum: a checked-off item citing "Implement the six visible journey steps," a comparison table row "Six-step progress | `<x-mk.stepper>` §3.9 ... six steps," and a checked-off item "Build the six-step stepper") AND cites exact test names as its own evidence — two of which this plan directly affects: `RenewalStartTest::test_the_stepper_shows_this_journeys_six_steps_not_the_nine_booking_ones` (Task 7 Step 7 rewrites this test for 3 steps, so the OLD name `tasks.md` cites stops existing) and `RenewalJourneyStepTest::test_the_renewal_labels_are_not_the_nine_booking_labels` (in the file Task 2 wholesale-replaces — this exact test is deleted). A third cited test, `GraveSearchStatesTest::test_the_stepper_shows_this_journeys_six_steps_and_not_the_nine_booking_ones`, already references a component (`GraveSearch`) that no longer exists from the PRIOR wizard-screen-consolidation redesign — already dead before this plan, not a new problem this plan introduces, but worth noting so it isn't mistaken for a fresh regression.

Add a note near the top of `tasks.md` (same file-level convention as the `requirements.md` fix above — this repo has no per-line strikethrough convention established for `tasks.md` specifically, based on the `platform-payment-adapter/tasks.md` precedent, which uses an inline **Superseded DATE:** note directly after the affected item rather than a separate section): add an inline `**Superseded 2 Sep 2026:**` note directly after each of the three step-count-citing items identified above (the "six visible steps" implementation item, the "Six-step progress" table row, and the "six-step stepper" build item), each pointing at `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md` and stating the two specific test names that no longer exist under their old names. Do not rewrite the historical narrative text itself (it stays accurate as a record of what was built in Sprint 4/L8) — only annotate that the step count and the two named tests are now superseded.

- [ ] **Step 7: Run the docs verification gate**

```bash
bash ci/verify-docs.sh
```

Expected: all gates pass — this script scans for hardcoded design values and (per this repo's CLAUDE.md) content-survival rules; confirm none of Steps 1-6's edits trip a gate (e.g. accidentally introducing a hardcoded Tailwind value while editing Markdown near a code fence is an easy, unrelated mistake to avoid here).

- [ ] **Step 8: Commit**

```bash
git add AGENTS.md docs/design/design-system.md docs/product/booking-wizard-fields.md .kiro/specs/renewal-and-grave-registry/
git commit -m "docs: amend step-count contracts for the wizard step reduction"
```

---

## Self-Review

**Spec coverage:** Decision 1 (Ringkasan cut) → Task 5 Step 7. Decision 2/7/9 (booking DISCOVERY merge, including the now-4-way merge of Lokasi+TPU/TPS+Jenis Layanan+Pilih Layanan) → Tasks 1, 3, 4, 6. Decision 3/4 (renewal merges) → Tasks 2, 7. Decision 5 (stepper tracks screens) → Tasks 1, 2, 6 — corrected from the spec's inaccurate "already screen-based" framing per this plan's Global Constraints. Decision 6 (in-flight draft unresumable) → Task 9, corrected to booking-only per the renewal persistence finding. Decision 8 (customer+deceased merge, accepted trade-off) → Tasks 1, 3, 5. `AGENTS.md`/`design-system.md`/`booking-wizard-fields.md`/`.kiro` amendments → Task 10, with the `.kiro` convention question resolved via the real `~~strikethrough~~ Superseded DATE` / `## Superseded (DATE)` precedent (confirmed by direct read of `platform-identity-and-access/requirements.md`, not assumed) rather than left open. Test-file sweep → Task 8, with the real grep-discovered file list including `BookingWizardStepsFourAndFiveTest.php` (found in this correction pass). The progressive-reveal redesign inside DISCOVERY (a real gap the spec never named at all) → Task 6 Step 4.

**Placeholder scan:** No "TBD"/"handle appropriately"/unshown code steps found on review — every code step has real PHP, every test has real assertions against named fixtures verified against the actual current files (not guessed helper names or invented constant values — see the post-audit correction notes throughout Tasks 3, 4, 6, 7, 9, 10 for the specific fabrications this pass replaced with verified real ones).

**Type consistency:** `saveStep1`'s new 5-arg signature is consistent between Task 4 (definition) and Task 6 (Blade call site update). `currentScreen(): int` return type consistent across Task 5 (definition) and Task 6 (consumption, unchanged signature). `BookingWizardScreen::labels()`/`RenewalWizardScreen::labels()` both return `array<int, string>`, consistent between Tasks 1/2 (definition) and Tasks 6/7 (consumption). `RenewalWizardScreen`'s step-2 label is now consistently `'Biaya & Bayar'` everywhere in this document (matching the REAL, already-shipped screen title in `RenewalPayment.php`'s own doc block — `'Biaya & Pembayaran'` was an inconsistent leftover from an earlier draft, now corrected in every occurrence, including inside `RenewalJourneyStep::LABELS` itself, not just the screen-vocabulary class).

**This is a post-audit correction pass** (three independent adversarial reviews of the original draft, one per task cluster) — every finding from all three reviews was re-verified independently against the real code/docs before being applied, not copy-pasted from the review reports. Two prior "unresolved, needs the implementer to investigate" items are now RESOLVED, not left open:

1. **The plot-hold `wasRecentlyCreated` question is resolved, not open.** Confirmed by direct read of `HoldPlotForDraft.php` and `PlotReservation::activeForDraft()`: the flag is always `false` on a re-fetched model, so release-on-failure logic built on it would silently never fire. Resolution (Task 4 Step 5): drop release-on-failure entirely — an unrelated field's typo must not cost a customer their already-held plot — and rely on the confirmed-real, confirmed-scheduled `plot-reservation:expire-stale-draft-holds` command (`routes/console.php`: `->everyMinute()->withoutOverlapping()`) as the safety net for a genuinely abandoned attempt.
2. **A second, deeper gap found during this correction pass (not in any of the three audit reports): `holdPlotForStep2()` REQUIRES a draft to already exist** (`if ($this->draftId === null) { ...error...; return; }`), which held under the old flow only because Step 1 always ran first. Under the DISCOVERY merge nothing creates the draft until the final combined save — strictly AFTER the plot pick — so the plot-hold method itself would always hit this guard and fail. Resolved (Task 4 Step 5): `holdPlotForDiscovery()` now lazily creates the draft itself via `currentOrNewDraft()` on first use, without persisting any DISCOVERY field or redirecting yet.
3. **The progressive-reveal gating inside Screen 1/DISCOVERY was never actually resolved by the original spec or the original draft of this plan** — both hand-waved "the UI keeps its existing interaction, only the save call changes," which doesn't work: the existing `@if ($currentStep === X)` gates reference `BookingWizardStep` constants (`LOCATION`/`CEMETERY`/`SERVICE_TYPE`/`SERVICES`) that Task 1 deletes outright, and even if they compiled, `$currentStep` never advances mid-DISCOVERY under the merge. Resolved (Task 6 Step 4) by mirroring `RenewalStart.php`'s already-proven, already-shipped pattern: local, non-persisting property-driven reveal (`selectCity()`/`selectCemetery()`/`selectServiceType()` setters, `@if ($city !== '')`-style gates) instead of step-driven reveal.

Nothing in this plan is now flagged as "genuinely unresolved, read more code before finalizing" — every real complication found across three independent audits plus this correction pass's own re-verification has a concrete resolution written into the relevant task, not a pointer to investigate further.
