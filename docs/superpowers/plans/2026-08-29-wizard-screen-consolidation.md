# Wizard Screen Consolidation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reduce the booking wizard from 9 page-turns to 4 progressive-reveal screens, and the renewal wizard from 6 page-turns to 3 (two of which are real Livewire component merges), without changing either wizard's documented step count, order, labels, or any validation/domain behavior.

**Architecture:** Booking is a template-only change: one existing Livewire component (`BookingWizard`) gains a pure `currentScreen(): int` function of its existing `$currentStep`, and `wizard.blade.php`'s per-step `@if/@elseif` chain is re-nested so a screen's already-completed steps stack in one continuous scroll instead of replacing each other. Renewal requires two real component merges (`GraveSearch` into `RenewalStart`, `RenewalFee` into `RenewalPayment`) because renewal has no single persisted draft row to key a screen-boundary function off of; a new small session-backed helper (`RenewalGraveSelection`) closes a pre-existing gap (no live UI path from search to fee) without ever putting a grave's id in a URL, which would reopen the privacy tradeoff `GraveRecordProjection` exists to hold.

**Tech Stack:** Laravel 13, Livewire 4, Blade, PostgreSQL 18, PHPUnit (via the pinned CI Docker image), Redis 8.2 (queues/cache, not directly exercised by these tasks).

**Spec:** `docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-design.md`

## Global Constraints

- `declare(strict_types=1);` on every new/modified PHP file.
- NO change to `saveStepN()` signatures, `SaveBookingDraftStep` validation/sequencing rules, `OpenRenewal`, `GuardRenewalPaymentOpening`'s four conditions, `PaymentMode`/`ModeResolver` gating, or any domain Action anywhere — this is presentation-layer only.
- No `design-system.md` amendment; `<x-mk.stepper>` itself is not modified. Booking keeps rendering all 9 flat dots with no `labels` override (§9.2 MUST-NOT-9); renewal keeps rendering all 6 dots via `RenewalJourneyStep::labels()`.
- This host's PHP is 8.3.6; `composer.json` requires `~8.5.0` — the host CANNOT run composer/pint/phpstan/phpunit directly. EVERY verification command in every task's steps is written as:
  `docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 <command>`
- Tests need real Postgres 18 + Redis 8.2, never SQLite. Spin up disposable containers per task (never touch the live `makam-nonprod-postgres-1`/`makam-nonprod-redis-1`):
  `docker run -d --rm --name <unique> -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p <unique-host-port>:5432 postgres:18` then, once ready, `CREATE EXTENSION pg_trgm;`
  `docker run -d --rm --name <unique> -p <unique-host-port>:6379 redis:8.2-alpine`
  Tear both down with `docker stop <name>` after each task's verification. Use distinct container names/ports per task.
- `vendor/bin/pint --test` and `vendor/bin/phpstan analyse --memory-limit=1G` must stay clean after every task.
- Use `php -d memory_limit=1G vendor/bin/phpunit <path>` directly for tests — NOT `artisan test` (its subprocess wrapper does not inherit a `-d` flag passed to the parent `php` invocation).
- Every existing booking/renewal feature test must keep passing, proving unchanged BEHAVIOR (a component genuinely merging is allowed to move a test's target class, but the observable behavior it pins must not change). New tests must prove the regrouping/merging didn't change behavior, not merely that new markup renders without error.
- Bookmark/URL preservation for renewal is load-bearing for the parameters that were EVER real UI links: `kota`, `tpu`, `nama`, `blok`, `tanggal` on the search screen, and `perpanjangan` on the payment/confirmation screens. `makam` was never a real UI link (see Task 5's Ruling) and its removal is not a regression.

## Implementation Decisions (resolved here, not left to the executor)

1. **`goToStep()` stays step-level; the screen wraps around it, not the other way round.** Every existing "Kembali"/"Lanjutkan" button already calls `goToStep()` with an exact `BookingWizardStep` constant (e.g. `goToStep({{ BookingWizardStep::DECEASED_DATA }})`). `currentScreen()` is a pure `match` over the resulting `$currentStep`, so every existing button keeps working with zero changes to its `wire:click` target, and there is exactly one source of truth for progress (`$currentStep`) — matching the spec's explicit "screen boundary MUST be a pure function ... NOT new persisted/stored state." Making `goToStep()` screen-aware would require it to invent a "first step of screen N" mapping for no behavioral gain, since nothing anywhere ever calls it with a screen number.
2. **`routeBackToPlotPickerAfterExpiredHold()` needs no change.** It already does `$this->currentStep = BookingWizardStep::CEMETERY;` (unchanged). Under progressive reveal this now lands the visitor on Screen 1 as a whole (`currentScreen()` maps step 2 → screen 1) with the CEMETERY section's plot picker expanded and the `plot` error visible in place, because by the time a hold can expire at submission the visitor has already completed steps 1–4, so all four of Screen 1's sections render simultaneously per the reveal predicate below — the CEMETERY section (with its now-reopened picker and error) is simply one of several visible sections rather than the only one. This is a strict UX improvement (the visitor sees their whole prior selection while re-picking) and needs zero special-casing.
3. **Renewal's screen-2 "same component" requirement (spec's Solution section: "Once search and fee-quoting live in the same component...") is reconciled with the screen table (search in Screen 1/`RenewalStart`, fee in Screen 2/`RenewalPayment`) as follows, because taken literally the two spec passages contradict each other** — `GraveRecordProjection` (what search results are shaped as) has no `id` property at all, so even within one component a rendered result row has nothing to embed in a `wire:click` target; and `OpenRenewal` must fire only from Screen 2's explicit "Terima Tarif" click (never from Screen 1), so Screen 1 cannot pre-resolve a `Renewal` id to use as the cross-screen reference either. The resolution implemented here: Screen 1 lets a visitor pick a search result by its **ordinal position** in the current result set (`wire:click="selectGraveForRenewal({{ $loop->index }})"` — an index, never a database id), which a new domain method (`GraveRegistryPublicQuery::resolveOpenRecordAt()`, Task 3) re-resolves server-side into a real `GraveRecord` by re-running the identical search. The resulting id is held in a new session-backed helper (`RenewalGraveSelection`, Task 3) — never a `#[Url]`-bound property, never a query parameter — and Screen 2 reads it server-side to show the fee section before any `Renewal` exists. This satisfies both passages' real intent: no grave id ever reaches a URL, the rendered HTML, or a Livewire client payload (the literal "same component" and "no id in the URL" requirements), while still following the screen table's literal 3-screen/3-component boundary and preserving `OpenRenewal`'s explicit-click-only contract in Screen 2.
4. **The merged `RenewalPayment` (Screen 2) never redirects between its own fee and payment halves.** `RenewalFee::terimaDanLanjutkan()` today calls `$this->redirect(route('perpanjangan.pembayaran', [...]), navigate: true)`. In the merged component, accepting the quote instead sets `$this->perpanjangan = $renewal->id` directly (a `#[Url(history: true)]` property, which Livewire reflects into the browser URL without a full navigation) and returns `null`. The very next `render()` call finds the now-real `Renewal` via the existing `resolveState()` path and renders the payment section — the same code path a genuine bookmark arrival already uses, unmodified. This is what makes screen 2 "progressive reveal within one screen" rather than two screens wearing one stepper dot.

---

### Task 1: Booking — `currentScreen()` computed method

**Files:**
- Modify: `app/Livewire/Public/Booking/BookingWizard.php`
- Test: `tests/Feature/Livewire/Public/Booking/BookingWizardScreenBoundaryTest.php` (new)

**Interfaces:**
- Produces: `BookingWizard::currentScreen(): int` — returns 1 for `$currentStep` 1–4 (LOCATION..SERVICES), 2 for 5–7 (SUMMARY..DECEASED_DATA), 3 for 8 (PAYMENT), 4 for 9 (CONFIRMATION). Task 2 consumes this from the Blade view as `$this->currentScreen()`.

- [ ] **Step 1: Add `currentScreen()` to `BookingWizard.php`**

In `app/Livewire/Public/Booking/BookingWizard.php`, insert immediately after the closing brace of `goToStep()` (the method ending at line 1082 in the current file, right before `canReachStep()`):

```php
    /**
     * Which of the four consolidated screens `$currentStep` belongs to — a
     * pure function of the existing step state, not a second piece of
     * tracked progress. Screen 1 (Cari & Pilih) = steps 1-4; Screen 2 (Detail
     * Pemesanan) = steps 5-7; Screen 3 (Pembayaran) = step 8 alone (kept
     * standalone — too much conditional online/manual/sandbox/session-
     * recovery branching to merge safely); Screen 4 (Konfirmasi) = step 9
     * alone (terminal). See `docs/superpowers/specs/
     * 2026-08-29-wizard-screen-consolidation-design.md`.
     */
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

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/Livewire/Public/Booking/BookingWizardScreenBoundaryTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\BookingWizardStep;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `BookingWizard::currentScreen()` — the consolidation's whole screen
 * boundary. Verified as a pure function directly (mutating the raw PHP
 * instance's `$currentStep`, bypassing Livewire's `#[Locked]`-enforced
 * request cycle, which only guards the client-facing update path — not
 * plain PHP property access on the object this test already holds) and via
 * the real save path for the steps that path can reach.
 */
final class BookingWizardScreenBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_step_maps_to_its_documented_screen(): void
    {
        $wizard = Livewire::test(BookingWizard::class)->instance();

        $expectations = [
            BookingWizardStep::LOCATION => 1,
            BookingWizardStep::CEMETERY => 1,
            BookingWizardStep::SERVICE_TYPE => 1,
            BookingWizardStep::SERVICES => 1,
            BookingWizardStep::SUMMARY => 2,
            BookingWizardStep::CUSTOMER_DATA => 2,
            BookingWizardStep::DECEASED_DATA => 2,
            BookingWizardStep::PAYMENT => 3,
            BookingWizardStep::CONFIRMATION => 4,
        ];

        foreach ($expectations as $step => $expectedScreen) {
            $wizard->currentStep = $step;

            $this->assertSame(
                $expectedScreen,
                $wizard->currentScreen(),
                "Step [{$step}] should map to screen [{$expectedScreen}]."
            );
        }
    }

    /**
     * The real save path (`saveStep1()`) advances `$currentStep` exactly the
     * way `SaveBookingDraftStep` always has — this test proves
     * `currentScreen()` tracks that real transition, not just a
     * hand-set property.
     */
    public function test_completing_step_1_keeps_the_wizard_on_screen_1(): void
    {
        $component = Livewire::test(BookingWizard::class)
            ->call('saveStep1', \App\Domain\CemeteryDirectory\LaunchCityCode::JAKARTA);

        $this->assertSame(BookingWizardStep::CEMETERY, $component->get('currentStep'));
        $this->assertSame(1, $component->instance()->currentScreen());
    }
}
```

- [ ] **Step 3: Run the test to verify it fails**

```bash
docker run -d --rm --name wsc-t1-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p 55501:5432 postgres:18
docker run -d --rm --name wsc-t1-redis -p 63501:6379 redis:8.2-alpine
# wait for postgres to accept connections, then:
docker run --network host --rm -e PGPASSWORD=test postgres:18 psql -h 127.0.0.1 -p 55501 -U test -d makam_test -c "CREATE EXTENSION pg_trgm;"
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55501 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63501 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardScreenBoundaryTest.php
```

Expected: FAIL with "Call to undefined method App\Livewire\Public\Booking\BookingWizard::currentScreen()".

- [ ] **Step 4: Confirm Step 1's implementation makes it pass**

Re-run the same command as Step 3. Expected: PASS (2 tests, both assertions per the loop in the first test).

- [ ] **Step 5: Run Pint and PHPStan**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: both clean.

- [ ] **Step 6: Tear down containers and commit**

```bash
docker stop wsc-t1-pg wsc-t1-redis
git add app/Livewire/Public/Booking/BookingWizard.php tests/Feature/Livewire/Public/Booking/BookingWizardScreenBoundaryTest.php
git commit -m "feat(booking): add pure currentScreen() computed from currentStep"
```

---

### Task 2: Booking — template regrouping into 4 progressive-reveal screens

**Files:**
- Modify: `resources/views/livewire/public/booking/wizard.blade.php`
- Modify: `app/Livewire/Public/Booking/BookingWizard.php` (two `render()` guard fixes)
- Test: existing booking Blade/feature tests (triage below), plus new `tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php`

**Interfaces:**
- Consumes: `BookingWizard::currentScreen(): int` (Task 1).
- Produces: no new public interface — this task only changes what renders for a given `$currentStep`/`$completedSteps` state, using the visibility predicate `currentStep === N || in_array(N, $completedSteps, true)` for each of steps 2, 3, 4 (screen 1) and 6, 7 (screen 2). Step 1 (LOCATION) and step 5 (SUMMARY, read-only) render unconditionally whenever their screen is active; steps 8 (PAYMENT) and 9 (CONFIRMATION) are single-step screens with no inner gating.

#### Why this is a sequence of narrow, exact edits, not a rewrite

`wizard.blade.php`'s per-step content (the actual form markup, `@error` blocks, buttons) is **byte-for-byte unchanged** by this task — only the `@if`/`@elseif`/`@endif` structure wrapping it changes, from one flat mutually-exclusive chain into two levels of nesting (an outer "is this screen active" `@if` around steps 1–4 and 5–7, an inner "is this step's section visible yet" `@if` around steps 2, 3, 4, 6, 7). Every `old_string` below is copied verbatim from the file as it exists on this branch; every `new_string` preserves every line of section content and changes only the directive lines around it. Apply them in order — each edit's `old_string` is unique in the file (grep-verified before writing this plan).

- [ ] **Step 1: Edit — open Screen 1 and Step 1's (LOCATION) inner gate**

In `resources/views/livewire/public/booking/wizard.blade.php`:

old_string:
```
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::LOCATION)
            <section aria-labelledby="booking-step-1-heading">
```

new_string:
```
        @if ($this->currentScreen() === 1)
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::LOCATION || in_array(\App\Domain\Booking\BookingWizardStep::LOCATION, $completedSteps, true))
            <section aria-labelledby="booking-step-1-heading">
```

- [ ] **Step 2: Edit — close Step 1's gate, open Step 2's (CEMETERY) gate**

old_string:
```
                @error('city_code')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CEMETERY)
            <section aria-labelledby="booking-step-2-heading">
```

new_string:
```
                @error('city_code')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
            </section>
        @endif
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::CEMETERY || in_array(\App\Domain\Booking\BookingWizardStep::CEMETERY, $completedSteps, true))
            <section aria-labelledby="booking-step-2-heading">
```

- [ ] **Step 3: Edit — close Step 2's gate, open Step 3's (SERVICE_TYPE) gate**

old_string:
```
                @error('cemetery_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
                @error('cemetery_package_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::LOCATION }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE)
            <section aria-labelledby="booking-step-3-heading">
```

new_string:
```
                @error('cemetery_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
                @error('cemetery_package_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::LOCATION }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @endif
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE || in_array(\App\Domain\Booking\BookingWizardStep::SERVICE_TYPE, $completedSteps, true))
            <section aria-labelledby="booking-step-3-heading">
```

- [ ] **Step 4: Edit — close Step 3's gate, open Step 4's (SERVICES) gate**

old_string:
```
                @error('service_type')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CEMETERY }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICES)
            <section aria-labelledby="booking-step-4-heading">
```

new_string:
```
                @error('service_type')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CEMETERY }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @endif
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICES || in_array(\App\Domain\Booking\BookingWizardStep::SERVICES, $completedSteps, true))
            <section aria-labelledby="booking-step-4-heading">
```

- [ ] **Step 5: Edit — close Step 4's gate and Screen 1, open Screen 2 (SUMMARY renders unconditionally within it)**

old_string:
```
                <div class="mt-4 flex gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="continueFromStep4"
                        wire:loading.attr="disabled"
                        wire:target="continueFromStep4"
                    >
                        Lanjutkan
                    </x-mk.button>
                </div>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SUMMARY)
            <section aria-labelledby="booking-step-5-heading">
```

new_string:
```
                <div class="mt-4 flex gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="continueFromStep4"
                        wire:loading.attr="disabled"
                        wire:target="continueFromStep4"
                    >
                        Lanjutkan
                    </x-mk.button>
                </div>
            </section>
        @endif
        @endif
        @if ($this->currentScreen() === 2)
            {{-- Screen 2 "Detail Pemesanan": Ringkasan renders unconditionally
                 here — reaching screen 2 at all already means step 4 is done,
                 which is Ringkasan's only precondition — as a persistent
                 summary card, not its own page. --}}
            <section aria-labelledby="booking-step-5-heading">
```

- [ ] **Step 6: Edit — no gate change at Step 5's own boundary, open Step 6's (CUSTOMER_DATA) gate**

old_string:
```
                <div class="mt-4 flex items-center gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICES }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA }})"
                    >
                        Lanjut ke Data Pemesan
                    </x-mk.button>
                </div>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA)
            <section aria-labelledby="booking-step-6-heading">
```

new_string:
```
                <div class="mt-4 flex items-center gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICES }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA }})"
                    >
                        Lanjut ke Data Pemesan
                    </x-mk.button>
                </div>
            </section>
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA || in_array(\App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA, $completedSteps, true))
            <section aria-labelledby="booking-step-6-heading">
```

- [ ] **Step 7: Edit — close Step 6's gate, open Step 7's (DECEASED_DATA) gate**

old_string:
```
                        <span wire:loading wire:target="saveStep6" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data pemesan&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::DECEASED_DATA)
            <section aria-labelledby="booking-step-7-heading">
```

new_string:
```
                        <span wire:loading wire:target="saveStep6" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data pemesan&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @endif
        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::DECEASED_DATA || in_array(\App\Domain\Booking\BookingWizardStep::DECEASED_DATA, $completedSteps, true))
            <section aria-labelledby="booking-step-7-heading">
```

- [ ] **Step 8: Edit — close Step 7's gate and Screen 2, open Screen 3 (PAYMENT, single-step, no inner gate)**

old_string:
```
                        <span wire:loading wire:target="saveStep7" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data almarhum&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::PAYMENT)
            <section aria-labelledby="booking-step-8-heading">
```

new_string:
```
                        <span wire:loading wire:target="saveStep7" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data almarhum&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @endif
        @endif
        @if ($this->currentScreen() === 3)
            <section aria-labelledby="booking-step-8-heading">
```

- [ ] **Step 9: Edit — close Screen 3, open Screen 4 (CONFIRMATION, single-step, no inner gate)**

old_string:
```
                <x-mk.button
                    variant="tertiary"
                    wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::DECEASED_DATA }})"
                    wire:loading.attr="disabled"
                    wire:target="saveStep8"
                    class="mt-4"
                >
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CONFIRMATION)
            <section aria-labelledby="booking-step-9-heading">
```

new_string:
```
                <x-mk.button
                    variant="tertiary"
                    wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::DECEASED_DATA }})"
                    wire:loading.attr="disabled"
                    wire:target="saveStep8"
                    class="mt-4"
                >
                    Kembali
                </x-mk.button>
            </section>
        @endif
        @if ($this->currentScreen() === 4)
            <section aria-labelledby="booking-step-9-heading">
```

The file's final `@endif` (currently the line right after Step 9's closing `</section>`, at what was line 1574) needs **no edit** — it already closes exactly one level (the `@if ($this->currentScreen() === 4)` just opened in Step 9 above), and the nesting is now balanced: 10 opens (Screen 1, LOCATION, CEMETERY, SERVICE_TYPE, SERVICES, Screen 2, CUSTOMER_DATA, DECEASED_DATA, Screen 3, Screen 4) against 10 closes (one per edit above, plus the pre-existing final `@endif`).

- [ ] **Step 10: Verify the Blade file still compiles**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php artisan view:cache
```

Expected: exits 0 with no "unexpected end of file" / unmatched `@if` error for `wizard.blade.php`. Run `php artisan view:clear` afterward so a stale compiled view is never left checked into `storage/`.

- [ ] **Step 11: Fix the two `render()` guards in `BookingWizard.php` that assumed step-exclusivity**

Under progressive reveal, the SERVICES section (step 4) can be visible while `$currentStep` has moved past it within screen 1 (e.g. after using "Kembali" to revisit step 1 or 2, `$currentStep` drops below 4 again while step 4 stays in `$completedSteps` and its section keeps rendering) — so `$basicServices`/`$additionalServices` must be computed whenever screen 1 is active, not only when `$currentStep === SERVICES` exactly. Likewise the SUMMARY card is now visible for the whole of screen 2 (steps 5–7), not only while `$currentStep === SUMMARY`.

In `app/Livewire/Public/Booking/BookingWizard.php`, `render()`:

old_string:
```php
        if ($this->currentStep === BookingWizardStep::SERVICES) {
            $activeServices = ServiceCatalogQuery::allActive();
```

new_string:
```php
        // Screen 1's Step 4 section can be visible while $currentStep has
        // moved to an earlier step within the same screen (progressive
        // reveal keeps a completed section on screen after "Kembali") — see
        // BookingWizardProgressiveRevealTest and this method's own screen
        // (not step) guard below.
        if ($this->currentScreen() === 1) {
            $activeServices = ServiceCatalogQuery::allActive();
```

old_string:
```php
        $summary = null;
        if ($this->currentStep === BookingWizardStep::SUMMARY && $this->draftId !== null) {
```

new_string:
```php
        // Ringkasan is a persistent summary card across the whole of Screen
        // 2 (steps 5-7), not only while $currentStep === SUMMARY exactly —
        // see this task's own report / the design spec's Screen 2 row.
        $summary = null;
        if ($this->currentScreen() === 2 && $this->draftId !== null) {
```

- [ ] **Step 12: Write new progressive-reveal tests**

Create `tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Booking;

use App\Domain\Booking\Actions\SaveBookingDraftStep;
use App\Domain\Booking\Actions\StartBookingDraft;
use App\Domain\Booking\BookingWizardStep;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Booking\BookingWizard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Screen 1 ("Cari & Pilih") stacks steps 1-4 in one continuous scroll as
 * each becomes valid-and-saved, per `docs/superpowers/specs/
 * 2026-08-29-wizard-screen-consolidation-design.md`. The stepper's active
 * dot still advances 1..9 exactly as before — only how many sections are
 * simultaneously ON SCREEN changes.
 */
final class BookingWizardProgressiveRevealTest extends TestCase
{
    use RefreshDatabase;

    public function test_step_2_is_not_shown_before_step_1_is_saved(): void
    {
        Livewire::test(BookingWizard::class)
            ->assertSee('Langkah 1')
            ->assertDontSee('Langkah 2 &mdash; Pilih TPU/TPS', false)
            ->assertDontSee('Langkah 2');
    }

    public function test_completing_step_1_reveals_step_2_without_hiding_step_1(): void
    {
        Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->assertSee('Langkah 1')
            ->assertSee('Langkah 2');
    }

    /**
     * The whole reason for this consolidation: after completing steps 1-3,
     * all three of their sections remain visible together with step 4's,
     * in one screen — not replaced one at a time.
     */
    public function test_all_four_screen_1_sections_stack_once_step_3_is_saved(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();

        $component = Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->call('saveStep2', $cemetery->id)
            ->call('saveStep3', 'NEW_GRAVE');

        $component
            ->assertSee('Langkah 1')
            ->assertSee('Langkah 2')
            ->assertSee('Langkah 3')
            ->assertSee('Langkah 4');

        $this->assertSame(1, $component->instance()->currentScreen());
    }

    /**
     * The stepper's own dot still advances one at a time as sections
     * reveal within Screen 1 — the consolidation changes what wraps the
     * dots, never the dots themselves (design-system.md §9.2 MUST-NOT-9).
     */
    public function test_the_stepper_dot_still_advances_one_step_at_a_time_within_screen_1(): void
    {
        $draftId = Livewire::test(BookingWizard::class)
            ->call('saveStep1', LaunchCityCode::JAKARTA)
            ->get('draftId');

        Livewire::test(BookingWizard::class, ['draftId' => $draftId])
            ->assertSet('currentStep', BookingWizardStep::CEMETERY)
            ->call('saveStep2', Cemetery::query()
                ->where('city', LaunchCityCode::JAKARTA)
                ->where('publication_status', 'published')
                ->whereDoesntHave('packages')
                ->firstOrFail()->id)
            ->assertSet('currentStep', BookingWizardStep::SERVICE_TYPE);
    }

    /**
     * Screen 2: Ringkasan is a persistent summary card across the whole
     * screen, visible alongside Data Pemesan and Data Almarhum once they
     * reveal — not its own page (spec's Screen 2 row).
     */
    public function test_ringkasan_stays_visible_alongside_data_pemesan_on_screen_2(): void
    {
        $draft = (new StartBookingDraft)();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::LOCATION, ['city_code' => LaunchCityCode::JAKARTA], 'idem-a');
        $cemetery = Cemetery::query()
            ->where('city', LaunchCityCode::JAKARTA)
            ->where('publication_status', 'published')
            ->whereDoesntHave('packages')
            ->firstOrFail();
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::CEMETERY, ['cemetery_id' => $cemetery->id], 'idem-b');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICE_TYPE, ['service_type' => 'NEW_GRAVE'], 'idem-c');
        $draft = (new SaveBookingDraftStep)($draft, BookingWizardStep::SERVICES, [
            'selected_services' => [
                ['code' => 'DOCUMENT_PROCESSING', 'quantity' => 1],
                ['code' => 'GRAVE_DIGGING', 'quantity' => 1],
            ],
        ], 'idem-d');

        Livewire::test(BookingWizard::class, ['draftId' => $draft->id])
            ->call('goToStep', BookingWizardStep::CUSTOMER_DATA)
            ->assertSee('Ringkasan Pesanan')
            ->assertSee('Data Pemesan');
    }
}
```

- [ ] **Step 13: Run the new tests**

```bash
docker run -d --rm --name wsc-t2-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p 55502:5432 postgres:18
docker run -d --rm --name wsc-t2-redis -p 63502:6379 redis:8.2-alpine
docker run --network host --rm -e PGPASSWORD=test postgres:18 psql -h 127.0.0.1 -p 55502 -U test -d makam_test -c "CREATE EXTENSION pg_trgm;"
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55502 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63502 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php
```

Expected: all 5 tests PASS.

- [ ] **Step 14: Run the FULL existing booking test suite and triage any step-exclusivity assumptions**

```bash
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55502 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63502 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/
```

This is expected to be mostly green (every test that drives the wizard forward through `saveStepN()` calls and asserts on the CURRENT step's content keeps passing unchanged — progressive reveal only ADDS visible content, it never removes any). The specific, real risk is any assertion of the shape `assertDontSee('Langkah N')` for an N whose section is now expected to remain visible after moving past it (i.e., an assertion that a PRIOR, now-completed step's heading is absent once a later step is reached) — check these files by name for that pattern and fix any found, changing the assertion to reflect that the prior section correctly stays visible:
- `tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoCardContentTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepTwoPackagesTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepsSixToNineEndToEndTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardStepFiveToSixHandoffTest.php`
- `tests/Feature/Livewire/Public/Booking/BookingWizardEndToEndTest.php`

For each failure found: read the failing assertion, confirm it is asserting step-exclusivity (a prior section's absence) rather than a real regression (wrong content, wrong error, wrong redirect), and update ONLY that assertion to match the new, intended behavior — do not weaken an assertion that is failing for any other reason; that is a real bug to fix in the template edits above, not a test to loosen.

- [ ] **Step 15: Re-run Pint and PHPStan**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: both clean.

- [ ] **Step 16: Tear down containers and commit**

```bash
docker stop wsc-t2-pg wsc-t2-redis
git add resources/views/livewire/public/booking/wizard.blade.php app/Livewire/Public/Booking/BookingWizard.php tests/Feature/Livewire/Public/Booking/BookingWizardProgressiveRevealTest.php
# add any test files touched during Step 14's triage
git commit -m "feat(booking): regroup 9-step wizard into 4 progressive-reveal screens"
```

---

### Task 3: Renewal — domain support (`resolveOpenRecordAt()` + `RenewalGraveSelection`)

**Files:**
- Modify: `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php`
- Create: `app/Domain/Renewal/RenewalGraveSelection.php`
- Test: `tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryResolveOpenRecordAtTest.php` (new)
- Test: `tests/Unit/Domain/Renewal/RenewalGraveSelectionTest.php` (new)

**Interfaces:**
- Produces: `GraveRegistryPublicQuery::resolveOpenRecordAt(GraveSearchCriteria $criteria, int $index): ?GraveRecord` — re-runs the identical search and returns the real `GraveRecord` model at ordinal position `$index` within the OPEN-mode subset of the match, or `null` if the index is out of range or nothing matches (including a race where the record changed access mode or was removed between render and click).
- Produces: `RenewalGraveSelection::remember(string $graveId): void`, `RenewalGraveSelection::current(): ?string`, `RenewalGraveSelection::forget(): void` — Task 4 and Task 5 consume these to hand a selected grave off between the two merged renewal screens without ever putting its id in a URL, rendered HTML, or Livewire client payload.

- [ ] **Step 1: Write the failing test for `resolveOpenRecordAt()`**

Create `tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryResolveOpenRecordAtTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class GraveRegistryPublicQueryResolveOpenRecordAtTest extends TestCase
{
    use RefreshDatabase;

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
    }

    public function test_it_resolves_the_real_record_at_the_given_index_of_the_open_subset(): void
    {
        $cemetery = $this->cemetery();
        $open = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Terbuka Satu',
            'access_mode' => GraveRecordAccessMode::OPEN,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Terbuka Satu', block: '', deathDate: '');

        $resolved = GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0);

        $this->assertNotNull($resolved);
        $this->assertSame($open->id, $resolved->id);
    }

    /**
     * A restricted (limited/closed) match must never be resolvable through
     * this method — it exists to let Screen 1 hand Screen 2 a grave the
     * visitor is actually allowed to renew, and RenewalFee's own gate
     * already refuses a non-open record. Defence in depth: even if a caller
     * mis-indexes, this method itself only ever returns an OPEN record.
     */
    public function test_a_restricted_record_is_never_returned_even_if_it_matches(): void
    {
        $cemetery = $this->cemetery();
        GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Terbatas Dua',
            'access_mode' => GraveRecordAccessMode::CLOSED,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Terbatas Dua', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0));
    }

    public function test_an_out_of_range_index_returns_null_rather_than_throwing(): void
    {
        $cemetery = $this->cemetery();
        GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Tunggal',
            'access_mode' => GraveRecordAccessMode::OPEN,
        ]);

        $criteria = GraveSearchCriteria::make(cemeteryId: $cemetery->id, name: 'Contoh Tunggal', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 5));
    }

    public function test_it_mirrors_search_by_returning_nothing_for_a_criteria_with_no_terms(): void
    {
        $criteria = GraveSearchCriteria::make(cemeteryId: $this->cemetery()->id, name: '', block: '', deathDate: '');

        $this->assertNull(GraveRegistryPublicQuery::resolveOpenRecordAt($criteria, 0));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
docker run -d --rm --name wsc-t3-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p 55503:5432 postgres:18
docker run -d --rm --name wsc-t3-redis -p 63503:6379 redis:8.2-alpine
docker run --network host --rm -e PGPASSWORD=test postgres:18 psql -h 127.0.0.1 -p 55503 -U test -d makam_test -c "CREATE EXTENSION pg_trgm;"
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55503 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63503 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryResolveOpenRecordAtTest.php
```

Expected: FAIL with "Call to undefined method ... resolveOpenRecordAt()".

- [ ] **Step 3: Implement `resolveOpenRecordAt()`, refactoring `search()`'s shared query into a private helper**

In `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php`:

old_string:
```php
    public static function search(GraveSearchCriteria $criteria): GraveSearchOutcome
    {
        // The UUID-shape check is defence in depth, not duplicated
        // validation. `grave_records.cemetery_id` is a real `uuid` column on
        // PostgreSQL, so comparing it against a non-UUID string is a
        // database type error rather than a miss — it would throw instead of
        // returning nothing. `App\Domain\CemeteryDirectory\
        // CemeteryPublicQuery::findPublishedById()` already stops that on the
        // public screen's path; this stops it for any other caller too, since this is a
        // public read entry point and the failure mode is a 500 on a search
        // form rather than an empty result.
        if (! $criteria->hasAnyTerm() || ! Str::isUuid($criteria->cemeteryId)) {
            return GraveSearchOutcome::empty();
        }

        // The date-shape check is the UUID check's reasoning applied to the
        // other criteria field backed by a typed column:
        // `grave_records.death_date` is a real `date` on PostgreSQL, so
        // `whereDate()` against a non-date string is a database type error
        // rather than a miss — it throws instead of returning nothing.
        //
        // Defence in depth ONLY, and it deliberately cannot produce the right
        // screen state on its own: an empty outcome renders as *no-result*,
        // which is the very conflation this module exists to prevent. The
        // correctness-carrying fix is `GraveSearch::mount()` populating the
        // error bag so §6.3's validation state renders and no search runs.
        // This clause exists so that a FUTURE caller which forgets to
        // validate gets nothing back rather than a 500 on a public form.
        if ($criteria->deathDate !== '' && ! self::isIsoDate($criteria->deathDate)) {
            return GraveSearchOutcome::empty();
        }

        $records = self::buildQuery($criteria)
            ->limit(self::MAX_RESULTS)
            ->get();

        $open = [];
        $restricted = [];

        foreach ($records as $record) {
            $projection = GraveRecordProjection::fromRecord($record, $record->cemetery?->name);

            if ($projection->isRestricted()) {
                $restricted[] = $projection;

                continue;
            }

            $open[] = $projection;
        }

        return new GraveSearchOutcome(openResults: $open, restrictedResults: $restricted);
    }
```

new_string:
```php
    public static function search(GraveSearchCriteria $criteria): GraveSearchOutcome
    {
        $records = self::matchedRecords($criteria);

        $open = [];
        $restricted = [];

        foreach ($records as $record) {
            $projection = GraveRecordProjection::fromRecord($record, $record->cemetery?->name);

            if ($projection->isRestricted()) {
                $restricted[] = $projection;

                continue;
            }

            $open[] = $projection;
        }

        return new GraveSearchOutcome(openResults: $open, restrictedResults: $restricted);
    }

    /**
     * The renewal journey's Screen 1 → Screen 2 handoff (`docs/superpowers/
     * specs/2026-08-29-wizard-screen-consolidation-design.md`). A visitor
     * picks a result by its ORDINAL POSITION in the open subset of the
     * current search — never a database id, because `GraveRecordProjection`
     * (what the rendered result rows actually are) deliberately has no `id`
     * property at all. This method re-runs the IDENTICAL search server-side
     * and resolves the real `GraveRecord` at that position, restricted to
     * OPEN-mode rows only — a restricted row can never be renewed online
     * regardless (`RenewalFee`'s own gate already refuses it), so there is
     * no legitimate reason for this method to ever hand one back.
     *
     * Returns `null` for an out-of-range index or a criteria that would not
     * search at all (mirroring `search()`'s own early returns) rather than
     * throwing — a race between render and click (the registry changed
     * underneath the visitor) is an ordinary, expected condition here, not
     * an error.
     */
    public static function resolveOpenRecordAt(GraveSearchCriteria $criteria, int $index): ?GraveRecord
    {
        if ($index < 0) {
            return null;
        }

        $openRecords = self::matchedRecords($criteria)->filter(
            static fn (GraveRecord $record): bool => ! GraveRecordProjection::fromRecord($record, null)->isRestricted()
        )->values();

        $record = $openRecords->get($index);

        return $record instanceof GraveRecord ? $record : null;
    }

    /**
     * @return \Illuminate\Support\Collection<int, GraveRecord>
     */
    private static function matchedRecords(GraveSearchCriteria $criteria): \Illuminate\Support\Collection
    {
        // See `search()`'s former inline comments (now here, since both
        // callers share this guard): the UUID/date shape checks are defence
        // in depth against a database type error on PostgreSQL's typed
        // columns, not duplicated validation — the screen's own validation
        // is still what produces the right §6.3 state.
        if (! $criteria->hasAnyTerm() || ! Str::isUuid($criteria->cemeteryId)) {
            return collect();
        }

        if ($criteria->deathDate !== '' && ! self::isIsoDate($criteria->deathDate)) {
            return collect();
        }

        return self::buildQuery($criteria)->limit(self::MAX_RESULTS)->get();
    }
```

- [ ] **Step 4: Run the test again to verify it passes**

Same command as Step 2. Expected: all 4 tests PASS.

- [ ] **Step 5: Re-run the EXISTING `GraveRegistryPublicQueryTest` to prove `search()`'s refactor changed nothing observable**

```bash
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55503 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63503 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryTest.php tests/Feature/Domain/GraveRegistry/GraveRecordTrigramSearchTest.php
```

Expected: all PASS, unchanged.

- [ ] **Step 6: Write the failing test for `RenewalGraveSelection`**

Create `tests/Unit/Domain/Renewal/RenewalGraveSelectionTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Renewal;

use App\Domain\Renewal\RenewalGraveSelection;
use Tests\TestCase;

final class RenewalGraveSelectionTest extends TestCase
{
    public function test_it_round_trips_a_remembered_grave_id(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000001');

        $this->assertSame('0198f000-0000-7000-8000-000000000001', RenewalGraveSelection::current());
    }

    public function test_nothing_remembered_reads_as_null(): void
    {
        $this->assertNull(RenewalGraveSelection::current());
    }

    public function test_forgetting_clears_it(): void
    {
        RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000002');
        RenewalGraveSelection::forget();

        $this->assertNull(RenewalGraveSelection::current());
    }
}
```

- [ ] **Step 7: Run it to verify it fails**

```bash
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55503 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63503 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Unit/Domain/Renewal/RenewalGraveSelectionTest.php
```

Expected: FAIL — class `App\Domain\Renewal\RenewalGraveSelection` not found.

- [ ] **Step 8: Create `RenewalGraveSelection`**

Create `app/Domain/Renewal/RenewalGraveSelection.php`:

```php
<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use Illuminate\Support\Facades\Session;

/**
 * Carries the grave a visitor selected from Screen 1's search results
 * (`RenewalStart`, after the `GraveSearch` merge) across the redirect to
 * Screen 2 (`RenewalPayment`, after the `RenewalFee` merge), server-side
 * only.
 *
 * Never a `#[Url]`-bound property and never a query parameter.
 * `GraveRecordProjection` deliberately carries no `id` at all — a public
 * search result must never leak an addressable id an attacker could
 * enumerate (its own doc block) — and putting the id in the URL to bridge
 * the two screens would reopen exactly that tradeoff. The PHP session is
 * this visitor's own storage, keyed to their session cookie, never rendered
 * into a Blade view and never a Livewire component's public property (so it
 * is never part of Livewire's serialised client payload either).
 *
 * Same shape and reasoning as `App\Domain\Booking\BookingDraftBinding`'s use
 * of the session for state that must survive a redirect without becoming a
 * client-visible identifier — see that class's own doc block.
 */
final class RenewalGraveSelection
{
    private const string SESSION_KEY = 'renewal.selected_grave_id';

    public static function remember(string $graveId): void
    {
        Session::put(self::SESSION_KEY, $graveId);
    }

    public static function current(): ?string
    {
        $value = Session::get(self::SESSION_KEY);

        return is_string($value) ? $value : null;
    }

    public static function forget(): void
    {
        Session::forget(self::SESSION_KEY);
    }
}
```

- [ ] **Step 9: Run the test again to verify it passes**

Same command as Step 7. Expected: all 3 tests PASS.

- [ ] **Step 10: Pint, PHPStan, teardown, commit**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --memory-limit=1G
docker stop wsc-t3-pg wsc-t3-redis
git add app/Domain/GraveRegistry/GraveRegistryPublicQuery.php app/Domain/Renewal/RenewalGraveSelection.php tests/Feature/Domain/GraveRegistry/GraveRegistryPublicQueryResolveOpenRecordAtTest.php tests/Unit/Domain/Renewal/RenewalGraveSelectionTest.php
git commit -m "feat(renewal): add index-based grave resolution and session-backed selection handoff"
```

---

### Task 4: Renewal — merge `GraveSearch` into `RenewalStart` (Screen 1 "Cari Makam")

**Files:**
- Modify: `app/Livewire/Public/Renewal/RenewalStart.php`
- Modify: `resources/views/livewire/public/renewal/start.blade.php`
- Delete: `app/Livewire/Public/Renewal/GraveSearch.php`
- Delete: `resources/views/livewire/public/renewal/grave-search.blade.php`
- Modify: `routes/web.php` (remove `/perpanjangan/cari`)
- Modify: `tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php` (absorb `GraveSearchStatesTest`'s and `GraveSearchPerformanceTest`'s methods)
- Delete: `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php`
- Delete: `tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php`

**Interfaces:**
- Consumes: `GraveRegistryPublicQuery::resolveOpenRecordAt()`, `RenewalGraveSelection::remember()` (Task 3).
- Produces: `RenewalStart::selectGraveForRenewal(int $index): mixed` — Screen 1's new forward action, called by a search result row's `wire:click`.

- [ ] **Step 1: Confirm no other code references the route being removed**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 php artisan route:list --name=perpanjangan
```

Cross-check the output against a repo-wide search: `grep -rn "perpanjangan\.cari\|perpanjangan/cari" app resources routes tests` — already run for this plan; the only real (non-doc, non-comment) reference outside `GraveSearch.php`/`grave-search.blade.php` themselves is the hardcoded link in `resources/views/livewire/public/renewal/start.blade.php:211` (`href="/perpanjangan/cari?tpu=..."`), which Step 5 below replaces. If this grep now finds anything else, stop and investigate before deleting the route — do not proceed on the assumption this list is exhaustive for a branch that may have moved since this plan was written.

- [ ] **Step 2: Rewrite `RenewalStart.php` to absorb `GraveSearch`**

Replace the entire contents of `app/Livewire/Public/Renewal/RenewalStart.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\GraveSearchOutcome;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan` — Screen 1 "Cari Makam" of the consolidated renewal
 * journey (`docs/superpowers/specs/2026-08-29-wizard-screen-consolidation-
 * design.md`). Folds journey steps 1-3 (Kota, TPU/TPS, Cari Makam) into one
 * screen, progressively revealed: TPU/TPS once a city is chosen, the search
 * form once a TPU/TPS is chosen, results once a search runs.
 *
 * This class is the MERGE of the former `RenewalStart` (steps 1-2) and
 * `GraveSearch` (step 3, formerly its own route `/perpanjangan/cari`). Every
 * property, validation rule and query below is carried over unchanged from
 * whichever of the two owned it; see each member's own doc block for its
 * original screen (PUB-030 / PUB-031) if that history matters.
 *
 * ---------------------------------------------------------------------------
 * Why the gate produces a BANNER for city/cemetery and a full explanatory
 * PAGE for search — unchanged reasoning, now within one component
 * ---------------------------------------------------------------------------
 * `G-DATA-01` closed means the grave-search capability is unavailable
 * (AC16). City and TPU/TPS selection work perfectly well either way, so
 * those sections render `<x-mk.alert>` up front (dismissible, per
 * `GraveSearchMode::fallback()`); the search section itself renders §6.4's
 * full explanatory page instead of a form, exactly as `GraveSearch` always
 * has.
 */
final class RenewalStart extends Component
{
    /**
     * `#[Url(as: 'kota', history: true)]` — Step 1. Empty means step 1 is
     * still open.
     */
    #[Url(as: 'kota', history: true)]
    public string $city = '';

    /**
     * `#[Url(as: 'tpu', history: true)]` — Step 2, formerly `GraveSearch::
     * $cemeteryId`. Selecting a TPU/TPS now sets this property directly
     * (`selectCemetery()`) instead of navigating to a separate route.
     */
    #[Url(as: 'tpu', history: true)]
    public string $cemeteryId = '';

    #[Url(as: 'nama', history: true)]
    public string $name = '';

    #[Url(as: 'blok', history: true)]
    public string $block = '';

    #[Url(as: 'tanggal', history: true)]
    public string $deathDate = '';

    /**
     * §6.5 "Provider unavailable" for the city/TPU-TPS read — set only when
     * that query itself throws.
     */
    public bool $cemeteryListUnavailable = false;

    /**
     * `true` once the visitor has actually asked for a search. See
     * `GraveSearch`'s original doc block: without this, arriving at the
     * search section would immediately render the no-result empty state
     * before anything had been searched for.
     */
    public bool $searched = false;

    /**
     * §6.5 "Provider unavailable" for the grave search itself.
     */
    public bool $searchUnavailable = false;

    public function mount(): void
    {
        $this->normalizeCity();
        $this->normalizeCemetery();

        if ($this->cemeteryId === '') {
            return;
        }

        // Carried over verbatim from `GraveSearch::mount()` — a
        // `?nama=`/`?blok=`/`?tanggal=` already present on the initial GET
        // is a real search request (a shared/bookmarked result link), and
        // the error-bag population (rather than `$this->validate()`) is
        // what keeps a malformed `?tanggal=` from ever reaching a typed
        // PostgreSQL column unvalidated. See that method's own doc block
        // for the full reasoning; it is unchanged here.
        $this->searched = $this->criteria()->hasAnyTerm();

        $validator = Validator::make(
            [
                'name' => $this->name,
                'block' => $this->block,
                'deathDate' => $this->deathDate,
            ],
            $this->rules(),
            $this->messages(),
        );

        foreach ($validator->errors()->messages() as $field => $messages) {
            foreach ($messages as $message) {
                $this->addError($field, $message);
            }
        }
    }

    private function normalizeCity(): void
    {
        if ($this->city !== '' && ! LaunchCityQuery::isKnown($this->city)) {
            $this->city = '';
        }
    }

    /**
     * A tampered/stale `?tpu=` (unknown id, or a real but unpublished
     * cemetery) is discarded the same way `normalizeCity()` discards a bad
     * `?kota=` — dropping the visitor back to a working TPU/TPS list rather
     * than a 404, since nothing about this URL names a record whose
     * existence itself could leak.
     */
    private function normalizeCemetery(): void
    {
        if ($this->cemeteryId !== '' && CemeteryPublicQuery::findPublishedById($this->cemeteryId) === null) {
            $this->cemeteryId = '';
        }
    }

    public function selectCity(string $city): void
    {
        if (! LaunchCityQuery::isKnown($city)) {
            return;
        }

        $this->city = $city;
        $this->resetCemetery();
    }

    public function resetCity(): void
    {
        $this->city = '';
        $this->resetCemetery();
    }

    public function selectCemetery(string $cemeteryId): void
    {
        if (CemeteryPublicQuery::findPublishedById($cemeteryId) === null) {
            return;
        }

        $this->cemeteryId = $cemeteryId;
        $this->resetSearch();
    }

    public function resetCemetery(): void
    {
        $this->cemeteryId = '';
        $this->resetSearch();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:120'],
            'block' => ['nullable', 'string', 'max:64'],
            'deathDate' => ['nullable', 'date_format:Y-m-d'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'name.max' => 'Nama almarhum terlalu panjang (maksimal 120 karakter).',
            'block.max' => 'Blok terlalu panjang (maksimal 64 karakter).',
            'deathDate.date_format' => 'Tanggal wafat harus berupa tanggal yang valid.',
        ];
    }

    public function search(): void
    {
        $this->validate();

        if (! $this->criteria()->hasAnyTerm()) {
            $this->addError('name', 'Isi minimal satu kolom pencarian: nama almarhum, blok, atau tanggal wafat.');
            $this->searched = false;

            return;
        }

        $this->searched = true;
    }

    public function resetSearch(): void
    {
        $this->reset(['name', 'block', 'deathDate', 'searched']);
        $this->resetValidation();
    }

    private function criteria(): GraveSearchCriteria
    {
        return GraveSearchCriteria::make(
            cemeteryId: $this->cemeteryId,
            name: $this->name,
            block: $this->block,
            deathDate: $this->deathDate,
        );
    }

    /**
     * Screen 1 → Screen 2 handoff — see this plan's Implementation Decision
     * 3 and `GraveRegistryPublicQuery::resolveOpenRecordAt()`'s own doc
     * block. `$index` is the result row's ORDINAL POSITION in the current
     * search's open subset, never a database id.
     */
    public function selectGraveForRenewal(int $index): mixed
    {
        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return null;
        }

        $record = GraveRegistryPublicQuery::resolveOpenRecordAt($this->criteria(), $index);

        if ($record === null) {
            $this->addError('name', 'Data makam yang dipilih sudah tidak tersedia. Silakan cari ulang.');
            $this->searched = false;

            return null;
        }

        RenewalGraveSelection::remember($record->id);

        return $this->redirect(route('perpanjangan.pembayaran'), navigate: true);
    }

    /**
     * Back-navigation target for a completed stepper dot — unchanged from
     * the original `RenewalStart::goToStep()`, still an allow-list of one.
     */
    public function goToStep(int $step): void
    {
        if ($step === RenewalJourneyStep::CITY) {
            $this->resetCity();
        }
    }

    private function currentStep(): int
    {
        return match (true) {
            $this->city === '' => RenewalJourneyStep::CITY,
            $this->cemeteryId === '' => RenewalJourneyStep::CEMETERY,
            default => RenewalJourneyStep::GRAVE_SEARCH,
        };
    }

    public function render(): View
    {
        $this->normalizeCity();
        $this->normalizeCemetery();

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

        $selectedCityLabel = null;

        foreach (CemeteryPublicQuery::launchCities() as $cityOption) {
            if ($cityOption['code'] === $this->city) {
                $selectedCityLabel = $cityOption['label'];
            }
        }

        $selectedCemetery = $this->cemeteryId !== ''
            ? CemeteryPublicQuery::findPublishedById($this->cemeteryId)
            : null;

        $graveSearchMode = app(ModeResolver::class)->graveSearchMode();
        $gateClosed = $graveSearchMode === GraveSearchMode::ManualAssistance;

        $outcome = GraveSearchOutcome::empty();
        $this->searchUnavailable = false;

        $shouldSearch = ! $gateClosed
            && $selectedCemetery !== null
            && $this->searched
            && ! $this->getErrorBag()->isNotEmpty();

        if ($shouldSearch) {
            try {
                $outcome = GraveRegistryPublicQuery::search($this->criteria());
            } catch (Throwable $e) {
                report($e);
                $this->searchUnavailable = true;
            }
        }

        return view('livewire.public.renewal.start', [
            'cities' => CemeteryPublicQuery::launchCities(),
            'cemeteries' => $cemeteries,
            'selectedCityLabel' => $selectedCityLabel,
            'selectedCemetery' => $selectedCemetery,
            'currentStep' => $this->currentStep(),
            'stepLabels' => RenewalJourneyStep::labels(),
            'graveSearchMode' => $graveSearchMode,
            'gateClosed' => $gateClosed,
            'outcome' => $outcome,
            'resultsShown' => $shouldSearch && ! $this->searchUnavailable,
            'maxResults' => GraveRegistryPublicQuery::MAX_RESULTS,
        ])->layout('layouts.app', [
            'title' => $selectedCityLabel !== null
                ? 'Perpanjangan Makam '.$selectedCityLabel.' - Makam.co.id'
                : 'Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}
```

- [ ] **Step 3: Rewrite `start.blade.php` to fold in `grave-search.blade.php`'s three-empty-state search section**

Replace the entire contents of `resources/views/livewire/public/renewal/start.blade.php`:

```blade
{{--
    resources/views/livewire/public/renewal/start.blade.php

    App\Livewire\Public\Renewal\RenewalStart's view — `/perpanjangan`,
    Screen 1 "Cari Makam" of the consolidated renewal journey (journey steps
    1-3: Kota, TPU/TPS, Cari Makam). Merge of the former start.blade.php
    (steps 1-2) and grave-search.blade.php (step 3) — every state each one
    rendered is preserved verbatim below, only regrouped into one
    progressively-revealed screen instead of two routes.

    THE THREE EMPTY SEARCH STATES ARE STILL NOT INTERCHANGEABLE — see the
    former grave-search.blade.php's own header, carried over in spirit: gate
    closed (§6.4 explanatory page, search never ran) vs. privacy-limited
    (§6.2, matched but withheld) vs. no-result (§6.2, three parts, the only
    branch allowed to say nothing was found). Every branch below still reads
    a separate fact off `App\Domain\GraveRegistry\GraveSearchOutcome`.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @php
            $graveSearchFallback = $graveSearchMode->fallback();
        @endphp

        @if ($graveSearchFallback)
            <div class="mb-8">
                <x-mk.alert
                    :intent="$graveSearchFallback->intent"
                    icon="alert-circle"
                    title="Pencarian Data Makam Belum Tersedia Online"
                    :dismissible="$graveSearchFallback->dismissible"
                    live="polite"
                >
                    Anda tetap dapat memilih kota dan TPU/TPS di halaman ini. Namun pencarian data makam secara
                    online belum kami aktifkan, sehingga langkah berikutnya akan mengarahkan Anda ke bantuan
                    petugas kami. Ini bukan berarti data makam yang Anda cari tidak ada.
                </x-mk.alert>
            </div>
        @endif

        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                Perpanjangan Makam
            </h1>
            <p class="text-base text-neutral-600">
                Cari makam yang akan diperpanjang masa sewanya.
            </p>
        </div>

        {{-- ============ Step 1 — city ============ --}}
        <section aria-labelledby="renewal-step-1-heading" class="mb-10">
            <h2 id="renewal-step-1-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 1 &mdash; Pilih Kota
            </h2>

            <ul class="flex flex-wrap gap-3" aria-label="Kota peluncuran">
                @foreach ($cities as $cityOption)
                    @php($isSelectedCity = $city === $cityOption['code'])
                    <li>
                        <x-mk.button
                            :variant="$isSelectedCity ? 'primary' : 'secondary'"
                            wire:click="selectCity('{{ $cityOption['code'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="selectCity"
                            :aria-current="$isSelectedCity ? 'step' : null"
                        >
                            {{ $cityOption['label'] }}
                        </x-mk.button>
                    </li>
                @endforeach
            </ul>

            @if ($city !== '')
                <p class="mt-3 text-sm text-neutral-600">
                    Kota terpilih: <span class="font-medium text-neutral-900">{{ $selectedCityLabel }}</span>.
                    <x-mk.button variant="link" wire:click="resetCity">
                        Ganti kota
                    </x-mk.button>
                </p>
            @endif
        </section>

        {{-- ============ Step 2 — TPU/TPS ============ --}}
        @if ($city !== '')
        <section aria-labelledby="renewal-step-2-heading" class="mb-10">
            <h2 id="renewal-step-2-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 2 &mdash; Pilih TPU/TPS
            </h2>

            <div wire:loading.delay wire:target="selectCity,resetCity" class="grid gap-4 md:grid-cols-2" aria-busy="true">
                <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <span class="sr-only">Memuat daftar TPU/TPS&hellip;</span>
            </div>

            <div wire:loading.remove.delay wire:target="selectCity,resetCity">
                @if ($cemeteryListUnavailable)
                    <x-mk.alert intent="pending" title="Daftar TPU/TPS sedang tidak dapat dimuat" live="polite">
                        Kami tidak dapat memuat daftar TPU/TPS untuk {{ $selectedCityLabel }} saat ini. Silakan coba
                        beberapa saat lagi, atau
                        <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                        agar petugas kami membantu langsung.
                    </x-mk.alert>
                @elseif ($cemeteries->isEmpty())
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                        <h3 class="text-lg font-semibold text-neutral-800">
                            Belum ada TPU/TPS terdaftar di {{ $selectedCityLabel }}.
                        </h3>
                        <p class="max-w-prose text-base text-neutral-600">
                            Data TPU/TPS untuk kota ini belum lengkap di sistem kami. Ini tidak berarti tidak ada
                            TPU/TPS di {{ $selectedCityLabel }} &mdash; hanya belum terdaftar di sini. Silakan pilih
                            kota lain, atau hubungi Bantuan agar petugas kami membantu pencarian Anda.
                        </p>
                        <x-mk.button variant="secondary" href="/bantuan" class="mt-2">
                            Hubungi Bantuan
                        </x-mk.button>
                    </div>
                @else
                    <p class="sr-only" role="status" aria-live="polite">
                        {{ $cemeteries->count() }} TPU/TPS ditemukan di {{ $selectedCityLabel }}.
                    </p>

                    <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar TPU/TPS">
                        @foreach ($cemeteries as $cemetery)
                            @php($isSelectedCemetery = $cemeteryId === $cemetery->id)
                            <li>
                                <x-mk.card class="flex h-full flex-col gap-2">
                                    <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                                    <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>

                                    @if ($cemetery->operator_name)
                                        <p class="text-sm text-neutral-600">
                                            Pengelola: {{ $cemetery->operator_name }}
                                        </p>
                                    @endif

                                    <div class="mt-auto pt-3">
                                        <x-mk.button
                                            :variant="$isSelectedCemetery ? 'primary' : 'secondary'"
                                            wire:click="selectCemetery('{{ $cemetery->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="selectCemetery"
                                        >
                                            {{ $isSelectedCemetery ? 'Terpilih' : 'Lanjut ke Pencarian Makam' }}
                                        </x-mk.button>
                                    </div>
                                </x-mk.card>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
        @endif

        {{-- ============ Step 3 — Cari Makam ============ --}}
        @if ($selectedCemetery !== null)
        <section aria-labelledby="renewal-step-3-heading">
            <h2 id="renewal-step-3-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 3 &mdash; Cari Makam
            </h2>

            @if ($gateClosed)
                <x-mk.gate-closed-page heading="Pencarian Data Makam Belum Tersedia" icon="inbox">
                    <p>
                        Pencarian data makam secara online belum kami aktifkan. Basis data registri makam sedang kami
                        siapkan bersama pengelola TPU/TPS, dan kami belum dapat membukanya untuk pencarian mandiri.
                    </p>
                    <p class="mt-3">
                        <strong class="font-semibold">Ini tidak berarti data makam yang Anda cari tidak ada.</strong>
                        Petugas kami dapat membantu memeriksakan data makam dan proses perpanjangannya secara manual.
                    </p>

                    <x-slot:fallback>
                        <x-mk.button variant="primary" href="/bantuan">
                            Hubungi Bantuan
                        </x-mk.button>
                    </x-slot:fallback>

                    <x-slot:support>
                        Anda juga dapat membaca
                        <a href="/faq" class="font-medium underline underline-offset-2">pertanyaan yang sering diajukan</a>.
                    </x-slot:support>
                </x-mk.gate-closed-page>
            @else
                <p class="mb-6 text-base text-neutral-600">
                    Mencari di <span class="font-medium text-neutral-900">{{ $selectedCemetery->name }}</span>.
                </p>

                <form wire:submit.prevent="search" role="search" aria-label="Cari data makam" class="mx-auto mb-8 max-w-form">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="grave-search-name" class="text-base font-medium text-neutral-800">
                                Nama almarhum
                            </label>
                            <input
                                type="search"
                                id="grave-search-name"
                                name="name"
                                wire:model="name"
                                autocomplete="off"
                                placeholder="Contoh: Budi Santoso"
                                @if ($errors->has('name')) aria-invalid="true" aria-describedby="grave-search-name-error" @endif
                                class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                    placeholder:text-neutral-500
                                    transition-[border-color,box-shadow] duration-fast ease-standard
                                    focus:outline-none focus:ring-2 focus:ring-offset-1
                                    {{ $errors->has('name')
                                        ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                        : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                            >
                            <p class="text-sm text-neutral-600">
                                Pencarian memaklumi perbedaan ejaan dan tanda baca, jadi tidak harus persis sama.
                            </p>
                            @error('name')
                                <p id="grave-search-name-error" class="text-sm text-danger-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-4 sm:flex-row">
                            <div class="flex flex-1 flex-col gap-1.5">
                                <label for="grave-search-block" class="text-base font-medium text-neutral-800">
                                    Blok <span class="font-normal text-neutral-600">(opsional)</span>
                                </label>
                                <input
                                    type="text"
                                    id="grave-search-block"
                                    name="block"
                                    wire:model="block"
                                    autocomplete="off"
                                    placeholder="Contoh: A-12"
                                    @if ($errors->has('block')) aria-invalid="true" aria-describedby="grave-search-block-error" @endif
                                    class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                        placeholder:text-neutral-500
                                        transition-[border-color,box-shadow] duration-fast ease-standard
                                        focus:outline-none focus:ring-2 focus:ring-offset-1
                                        {{ $errors->has('block')
                                            ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                            : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                                >
                                @error('block')
                                    <p id="grave-search-block-error" class="text-sm text-danger-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-1 flex-col gap-1.5">
                                <label for="grave-search-death-date" class="text-base font-medium text-neutral-800">
                                    Tanggal wafat <span class="font-normal text-neutral-600">(opsional)</span>
                                </label>
                                <input
                                    type="date"
                                    id="grave-search-death-date"
                                    name="death_date"
                                    wire:model="deathDate"
                                    @if ($errors->has('deathDate')) aria-invalid="true" aria-describedby="grave-search-death-date-error" @endif
                                    class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                        placeholder:text-neutral-500
                                        transition-[border-color,box-shadow] duration-fast ease-standard
                                        focus:outline-none focus:ring-2 focus:ring-offset-1
                                        {{ $errors->has('deathDate')
                                            ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                            : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                                >
                                @error('deathDate')
                                    <p id="grave-search-death-date-error" class="text-sm text-danger-700">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-mk.button
                                variant="primary"
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="search"
                            >
                                Cari Data Makam
                            </x-mk.button>

                            @if ($name !== '' || $block !== '' || $deathDate !== '')
                                <x-mk.button variant="secondary" wire:click="resetSearch">
                                    Reset pencarian
                                </x-mk.button>
                            @endif
                        </div>
                    </div>
                </form>

                <div wire:loading.delay wire:target="search" class="space-y-3" aria-busy="true">
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <span class="sr-only">Mencari data makam&hellip;</span>
                </div>

                <div wire:loading.remove.delay wire:target="search">
                    @if ($searchUnavailable)
                        <x-mk.alert intent="pending" title="Pencarian sedang tidak dapat diproses" live="polite">
                            Sistem pencarian data makam sedang tidak dapat diakses. Ini bukan hasil pencarian &mdash; kami
                            belum sempat memeriksa data apa pun. Silakan coba lagi beberapa saat lagi, atau
                            <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                            agar petugas kami memeriksakan secara manual.
                        </x-mk.alert>

                    @elseif ($resultsShown)
                        <p class="sr-only" role="status" aria-live="polite">
                            @if ($outcome->isNoResult())
                                Data makam tidak ditemukan di {{ $selectedCemetery->name }}. Registri makam kami belum tentu lengkap, jadi hasil ini belum tentu berarti makam yang Anda cari tidak ada. Lanjutkan lewat tombol Input manual atau Hubungi bantuan di bawah.
                            @else
                                {{ $outcome->matchCount() }} data makam cocok dengan pencarian Anda.
                            @endif
                        </p>

                        @if ($outcome->hasOpenResults())
                            <ul class="flex flex-col gap-3" aria-label="Hasil pencarian data makam">
                                @foreach ($outcome->openResults as $index => $row)
                                    <li wire:key="grave-result-{{ $index }}">
                                        <x-mk.card class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                                <dt class="text-neutral-600">Nama Almarhum</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->deceasedName }}</dd>
                                                <dt class="text-neutral-600">Blok</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->block }}</dd>
                                                <dt class="text-neutral-600">Tanggal Wafat</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->deathDate ?? 'Tidak tercatat' }}</dd>
                                                <dt class="text-neutral-600">Jatuh Tempo</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->dueDate ?? 'Tidak tercatat' }}</dd>
                                            </dl>
                                            <x-mk.button
                                                variant="primary"
                                                wire:click="selectGraveForRenewal({{ $index }})"
                                                wire:loading.attr="disabled"
                                                wire:target="selectGraveForRenewal"
                                            >
                                                Lanjutkan ke Pembayaran
                                            </x-mk.button>
                                        </x-mk.card>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($outcome->matchCount() >= $maxResults)
                                <p class="mt-3 text-sm text-neutral-600">
                                    Menampilkan {{ $maxResults }} data teratas. Persempit pencarian dengan menambahkan blok
                                    atau tanggal wafat bila makam yang Anda cari belum terlihat.
                                </p>
                            @endif
                        @endif

                        @if ($outcome->isPrivacyLimited())
                            <div class="mt-6">
                                <x-mk.card intent="info" class="flex flex-col gap-3">
                                    <div class="flex items-start gap-3">
                                        <x-dynamic-component component="icon.shield-check" class="size-6 shrink-0 text-neutral-600" aria-hidden="true" />
                                        <div class="space-y-2">
                                            <h2 class="text-lg font-semibold text-neutral-900">
                                                {{ $outcome->restrictedCount() }} data makam cocok, tetapi aksesnya dibatasi.
                                            </h2>
                                            <p class="max-w-prose text-base text-neutral-700">
                                                Data makam tersebut <span class="font-semibold">ada di sistem kami</span>.
                                                Pengelola TPU/TPS membatasi informasi yang boleh ditampilkan untuk pencarian
                                                publik, sehingga kami tidak dapat menampilkan nama, tanggal wafat, dan
                                                jatuh temponya di halaman ini.
                                            </p>
                                            <p class="max-w-prose text-base text-neutral-700">
                                                Petugas kami dapat memverifikasi data ini bersama Anda sebagai ahli waris
                                                dan melanjutkan proses perpanjangan.
                                            </p>
                                        </div>
                                    </div>

                                    @php
                                        $restrictedBlocks = collect($outcome->restrictedResults)
                                            ->filter(fn ($row) => $row->block !== null)
                                            ->pluck('block')
                                            ->unique()
                                            ->values();
                                    @endphp

                                    @if ($restrictedBlocks->isNotEmpty())
                                        <p class="text-sm text-neutral-700">
                                            Blok yang cocok:
                                            <span class="font-medium">{{ $restrictedBlocks->implode(', ') }}</span>.
                                        </p>
                                    @endif

                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <x-mk.button variant="primary" href="/bantuan">
                                            Verifikasi lewat Bantuan
                                        </x-mk.button>
                                    </div>
                                </x-mk.card>
                            </div>
                        @endif

                        @if ($outcome->hasExampleData())
                            <p class="mt-3 text-sm text-neutral-600">
                                Sebagian hasil pencarian ini adalah <span class="font-medium">data contoh</span> untuk
                                keperluan uji coba, bukan data makam yang sebenarnya.
                            </p>
                        @endif

                        @if ($outcome->isNoResult())
                            <div class="flex flex-col items-center gap-3 py-12 text-center">
                                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />

                                <h2 class="text-lg font-semibold text-neutral-800">
                                    Data makam tidak ditemukan.
                                </h2>

                                <p class="max-w-prose text-base text-neutral-600">
                                    Tidak ada data yang cocok dengan pencarian Anda di {{ $selectedCemetery->name }}.
                                    <span class="font-medium text-neutral-800">Registri makam kami belum tentu lengkap</span>
                                    &mdash; banyak data lama belum kami terima dari pengelola TPU/TPS.
                                    <span class="font-medium text-neutral-800">Hasil ini belum tentu berarti makam yang Anda cari tidak ada.</span>
                                </p>

                                <p class="max-w-prose text-base text-neutral-600">
                                    Coba ejaan lain atau kosongkan blok/tanggal wafat, atau lanjutkan lewat jalur di bawah
                                    ini agar petugas kami mencarikan secara manual.
                                </p>

                                <div class="flex flex-wrap justify-center gap-2 pt-2">
                                    <x-mk.button variant="primary" href="/bantuan">
                                        Input manual
                                    </x-mk.button>
                                    <x-mk.button variant="secondary" href="/bantuan">
                                        Hubungi bantuan
                                    </x-mk.button>
                                </div>
                            </div>
                        @endif

                    @else
                        <p class="py-8 text-center text-base text-neutral-600">
                            Isi minimal satu kolom di atas, lalu tekan &ldquo;Cari Data Makam&rdquo;.
                        </p>
                    @endif
                </div>
            @endif
        </section>
        @endif

        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan menelusuri data makam?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
```

- [ ] **Step 4: Delete the two files this task absorbs**

```bash
git rm app/Livewire/Public/Renewal/GraveSearch.php resources/views/livewire/public/renewal/grave-search.blade.php
```

- [ ] **Step 5: Remove the `/perpanjangan/cari` route and its now-unused import**

In `routes/web.php`:

old_string:
```
use App\Livewire\Public\Renewal\GraveSearch;
use App\Livewire\Public\Renewal\RenewalConfirmation;
```

new_string:
```
use App\Livewire\Public\Renewal\RenewalConfirmation;
```

old_string:
```
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/cari', GraveSearch::class)->name('perpanjangan.cari');
Route::get('/perpanjangan/biaya', RenewalFee::class)->name('perpanjangan.biaya');
```

new_string:
```
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/biaya', RenewalFee::class)->name('perpanjangan.biaya');
```

(Task 5 removes the `/perpanjangan/biaya` line and its `RenewalFee` import in turn — left here for now since Task 5 hasn't merged `RenewalFee` yet.)

- [ ] **Step 6: Migrate `GraveSearchStatesTest`'s and `GraveSearchPerformanceTest`'s assertions into `RenewalStartTest`**

Every test in `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php` follows the shape `Livewire::test(GraveSearch::class, ['cemeteryId' => $cemetery->id, ...])->...`. Migrate each into `RenewalStartTest.php` by: importing `RenewalStart` instead of `GraveSearch`, and changing the constructor params from `['cemeteryId' => $id, 'name' => ...]` to `['cemeteryId' => $id, 'name' => ...]` unchanged (the property name is identical on the merged class) — the ONLY required change is the target class. Two fully worked examples (apply the same transformation to every other method in that file):

```php
    /**
     * Migrated from GraveSearchStatesTest — the privacy-limited state must
     * never say "not found": the record demonstrably exists.
     */
    public function test_the_privacy_limited_state_never_says_the_record_was_not_found(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
        $grave = \App\Domain\GraveRegistry\Models\GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Terbatas Uji',
            'access_mode' => \App\Domain\GraveRegistry\GraveRecordAccessMode::LIMITED,
        ]);

        Livewire::test(\App\Livewire\Public\Renewal\RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Contoh Terbatas Uji',
        ])
            ->call('search')
            ->assertDontSee('tidak ditemukan');
    }

    /**
     * Migrated from GraveSearchStatesTest — a search backend failure must
     * never be reported as "not found" (that would be indistinguishable
     * from a genuine empty result).
     */
    public function test_a_search_backend_failure_is_never_reported_as_not_found(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();

        Schema::drop('grave_records');

        Livewire::test(\App\Livewire\Public\Renewal\RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Siapapun',
        ])
            ->call('search')
            ->assertSee('sedang tidak dapat diproses')
            ->assertDontSee('tidak ditemukan');
    }
```

Apply the identical mechanical transformation (target class → `RenewalStart`, constructor params → `['cemeteryId' => ..., 'name'/'block'/'deathDate' => ...]`, `->call('search')` inserted where the original relied on `$searched` already being true via constructor `#[Url]` values if it did) to the remaining methods:
- `test_the_privacy_limited_state_discloses_no_withheld_name`
- `test_the_no_result_state_is_not_confused_with_the_other_two`
- `test_the_gate_closed_state_never_implies_the_record_does_not_exist`
- `test_a_blank_submission_is_a_validation_error_not_a_no_result`
- every other test method in `GraveSearchStatesTest.php` (read the file for its exact current list before migrating — this plan's earlier research pass enumerated the ones above from `traceability-matrix.md`; treat that list as a floor, not a ceiling, and confirm against the real file before deleting it)

Migrate `GraveSearchPerformanceTest.php`'s one test (`test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale`) the same way — same class-name and constructor-shape change, no other change; it is measuring `RenewalStart::search()`'s wall-clock cost, and that method is now on `RenewalStart` unchanged.

After migrating every method, delete both source files:

```bash
git rm tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php
```

- [ ] **Step 7: Write new tests for the Screen 1 → Screen 2 handoff**

Add to `RenewalStartTest.php`:

```php
    public function test_selecting_a_search_result_stores_the_grave_id_in_session_never_in_the_url(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();
        $grave = \App\Domain\GraveRegistry\Models\GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Handoff Uji',
            'access_mode' => \App\Domain\GraveRegistry\GraveRecordAccessMode::OPEN,
        ]);

        $component = Livewire::test(RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Contoh Handoff Uji',
        ])->call('search');

        $html = $component->html();
        $this->assertStringNotContainsString($grave->id, $html);

        $component->call('selectGraveForRenewal', 0)
            ->assertRedirect(route('perpanjangan.pembayaran'));

        $this->assertSame($grave->id, \App\Domain\Renewal\RenewalGraveSelection::current());
    }

    public function test_an_index_that_no_longer_matches_shows_a_retry_error_instead_of_redirecting(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = Cemetery::query()->where('slug', CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])->sole();

        Livewire::test(RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Nama Yang Tidak Ada Sama Sekali',
        ])
            ->call('search')
            ->call('selectGraveForRenewal', 0)
            ->assertNoRedirect()
            ->assertSee('sudah tidak tersedia');
    }
```

- [ ] **Step 8: Run the merged `RenewalStartTest` and Pint/PHPStan**

```bash
docker run -d --rm --name wsc-t4-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p 55504:5432 postgres:18
docker run -d --rm --name wsc-t4-redis -p 63504:6379 redis:8.2-alpine
docker run --network host --rm -e PGPASSWORD=test postgres:18 psql -h 127.0.0.1 -p 55504 -U test -d makam_test -c "CREATE EXTENSION pg_trgm;"
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55504 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63504 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: all tests PASS, Pint and PHPStan clean. `php artisan route:list --name=perpanjangan.cari` (run inside the same docker wrapper) must return no rows.

- [ ] **Step 9: Teardown and commit**

```bash
docker stop wsc-t4-pg wsc-t4-redis
git add app/Livewire/Public/Renewal/RenewalStart.php resources/views/livewire/public/renewal/start.blade.php routes/web.php tests/Feature/Livewire/Public/Renewal/RenewalStartTest.php
git commit -m "feat(renewal): merge GraveSearch into RenewalStart as Screen 1 'Cari Makam'"
```

---

### Task 5: Renewal — merge `RenewalFee` into `RenewalPayment` (Screen 2 "Biaya & Bayar")

**Files:**
- Modify: `app/Livewire/Public/Renewal/RenewalPayment.php`
- Modify: `resources/views/livewire/public/renewal/payment.blade.php`
- Delete: `app/Livewire/Public/Renewal/RenewalFee.php`
- Delete: `resources/views/livewire/public/renewal/fee.blade.php`
- Modify: `routes/web.php` (remove `/perpanjangan/biaya`)
- Modify: `tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php` (absorb `RenewalFeeTest`'s methods)
- Delete: `tests/Feature/Livewire/Public/Renewal/RenewalFeeTest.php`

**Interfaces:**
- Consumes: `RenewalGraveSelection::current()`, `::forget()` (Task 3); the merged `RenewalStart::selectGraveForRenewal()` (Task 4) is what populates the value this task reads.
- Produces: `RenewalPayment::terimaDanLanjutkan(): mixed` — Screen 2's fee-acceptance action, now setting `$this->perpanjangan` in place instead of redirecting (Implementation Decision 4).

- [ ] **Step 1: Confirm no other code references `/perpanjangan/biaya`**

```bash
grep -rn "perpanjangan\.biaya\|perpanjangan/biaya" app resources routes tests
```

Only `routes/web.php`'s own registration, `RenewalFee.php`/`fee.blade.php`'s own doc comments, and this plan's own text should remain — nothing renders a link to it anywhere (`RenewalFee`'s `?makam=` was never reachable from a real UI link, per the design spec's own Problem Statement; that is exactly the gap this merge closes).

- [ ] **Step 2: Rewrite `RenewalPayment.php` to absorb `RenewalFee`**

Replace the entire contents of `app/Livewire/Public/Renewal/RenewalPayment.php`:

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalGraveSelection;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Domain\Renewal\RenewalQuoteDraft;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use App\Platform\Payment\Actions\OpenPaymentSession;
use App\Platform\Payment\Actions\OpenPaymentSessionCommand;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutProviderException;
use App\Platform\Payment\Checkout\Exceptions\PaymentCheckoutUnavailableException;
use App\Platform\Payment\Exceptions\PaymentSessionOpeningDeniedException;
use App\Platform\Payment\Exceptions\PaymentSessionOrderAlreadyPaidException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\OrderType;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\SessionState;
use App\Platform\SiteSettings\Models\SiteSetting;
use App\Platform\SiteSettings\SettingsService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/perpanjangan/pembayaran` — Screen 2 "Biaya & Bayar" of the consolidated
 * renewal journey (`docs/superpowers/specs/2026-08-29-wizard-screen-
 * consolidation-design.md`). Folds journey steps 4-5 (Biaya, Pembayaran)
 * into one screen: the fee section renders first, and only once the
 * visitor's explicit "Terima Tarif" click accepts it does the payment
 * section reveal — on the SAME screen, no navigation (Implementation
 * Decision 4 in the plan this class was built from).
 *
 * This class is the MERGE of the former `RenewalFee` (step 4, formerly its
 * own route `/perpanjangan/biaya`) and `RenewalPayment` (step 5). Every
 * property, guard call and payment mechanic below is carried over from
 * whichever of the two owned it, UNCHANGED in behavior — see each member's
 * own doc block for its original screen if that history matters.
 *
 * ---------------------------------------------------------------------------
 * Which section renders is a THREE-WAY state, resolved fresh every render
 * ---------------------------------------------------------------------------
 *  1. `$perpanjangan !== ''` (a real `Renewal` exists — either a genuine
 *     bookmark arrival, or one this same instance just created via
 *     `terimaDanLanjutkan()`) → the PAYMENT section, via the unchanged
 *     `resolveState()`/`GuardRenewalPaymentOpening` path `RenewalPayment`
 *     has always used.
 *  2. No `$perpanjangan` but `RenewalGraveSelection::current() !== null`
 *     (Screen 1 just handed off a selection) → the FEE section, via the
 *     unchanged `QuoteRenewal`/`GraveRecordProjection` path `RenewalFee`
 *     has always used.
 *  3. Neither → "tidak ditemukan", exactly `RenewalPayment`'s original
 *     no-parameter case.
 *
 * `terimaDanLanjutkan()` — the only bridge between 2 and 1 — fires ONLY from
 * an explicit click and, exactly as `RenewalFee::terimaDanLanjutkan()`
 * always has, calls `OpenRenewal` (the only write in the entire renewal
 * journey) and nothing else. It never redirects: it sets `$this->
 * perpanjangan` directly, which `#[Url(history: true)]` reflects into the
 * browser URL, and the NEXT render finds state 1 above — the same
 * `resolveState()` a genuine bookmark arrival already uses.
 */
final class RenewalPayment extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    /**
     * Set only by `terimaDanLanjutkan()`, to report a handled failure of the
     * acceptance itself (formerly `RenewalFee::$actionMessage`).
     */
    #[Locked]
    public string $actionMessage = '';

    public ?string $checkoutError = null;

    public function render(): View
    {
        $state = $this->resolveState();

        return view('livewire.public.renewal.payment', [
            ...$state,
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'currentStep' => $state['mode'] === 'fee' ? RenewalJourneyStep::FEE : RenewalJourneyStep::PAYMENT,
            'stepLabels' => RenewalJourneyStep::labels(),
            'checkoutError' => $this->checkoutError,
            'isSandboxPayment' => config('payment.default') === PaymentProviders::SUMOPOD_SANDBOX,
        ])->layout('layouts.app', [
            'title' => $state['mode'] === 'fee'
                ? 'Biaya Perpanjangan Makam - Makam.co.id'
                : 'Pembayaran Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }

    /**
     * The fee section's acceptance — formerly `RenewalFee::
     * terimaDanLanjutkan()`. Re-resolves the grave and re-quotes
     * server-side rather than trusting any value carried on the component,
     * exactly as before. The only change from the original: no redirect —
     * see this class's own doc block, "Implementation Decision 4".
     */
    public function terimaDanLanjutkan(): mixed
    {
        $graveId = RenewalGraveSelection::current();

        if ($graveId === null) {
            return null;
        }

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return null;
        }

        $grave = Str::isUuid($graveId) ? GraveRecord::query()->find($graveId) : null;

        if (! $grave instanceof GraveRecord) {
            return null;
        }

        if ((string) $grave->access_mode !== GraveRecordAccessMode::OPEN) {
            return null;
        }

        try {
            $renewal = app(OpenRenewal::class)($grave);
        } catch (DuplicateRenewalPeriodException) {
            $this->actionMessage = 'Perpanjangan untuk periode ini sudah tercatat. Silakan hubungi petugas kami untuk memeriksa statusnya.';

            return null;
        } catch (\InvalidArgumentException) {
            $this->actionMessage = 'Tarif tidak tersedia. Silakan hubungi petugas kami.';

            return null;
        }

        RenewalGraveSelection::forget();
        $this->perpanjangan = $renewal->id;

        return null;
    }

    /**
     * The payment section's ONLINE branch — unchanged from `RenewalPayment::
     * payOnline()`. See that method's original doc block (carried over
     * verbatim in spirit) for the full re-click-guard and exception-mapping
     * reasoning; nothing about it changes with this merge.
     */
    public function payOnline(): void
    {
        $this->checkoutError = null;

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            $this->checkoutError = 'Data perpanjangan tidak ditemukan. Silakan muat ulang halaman ini.';

            return;
        }

        $sessionKey = 'renewal_online_payment.'.$renewal->getKey();
        $stored = session($sessionKey);

        if (is_array($stored) && isset($stored['session_id'])) {
            $existing = PaymentSession::query()->find($stored['session_id']);

            if ($existing instanceof PaymentSession) {
                $state = SessionState::tryFrom((string) $existing->state);

                if ($state === SessionState::Paid || $state === SessionState::Failed || $state === SessionState::Expired) {
                    return;
                }

                $link = is_string($stored['link_url'] ?? null) ? $stored['link_url'] : '';

                if ($link !== '') {
                    $this->redirect($link);

                    return;
                }
            }

            session()->forget($sessionKey);
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            $this->checkoutError = 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.';

            return;
        }

        try {
            $session = app(OpenPaymentSession::class)(new OpenPaymentSessionCommand(
                orderType: OrderType::Renewal,
                orderRef: $renewal->reference,
                amountMinor: $quote->amountAsMoney()->toMinorInt(),
                merchantRef: (string) app(SettingsService::class)
                    ->setting(SiteSetting::KEY_PAYMENT_MERCHANT_REF, (string) config('payment.merchant_ref', '')),
                successReturnUrl: route('payments.return'),
                cancelReturnUrl: route('payments.cancel'),
            ));
        } catch (PaymentSessionOpeningDeniedException) {
            $this->checkoutError = 'Pembayaran online belum dapat dibuka saat ini. Silakan hubungi petugas kami untuk koordinasi manual.';

            return;
        } catch (PaymentSessionOrderAlreadyPaidException) {
            $this->checkoutError = 'Perpanjangan ini telah dibayar dan tidak perlu dibayar lagi.';

            return;
        } catch (PaymentCheckoutProviderException|PaymentCheckoutUnavailableException) {
            $this->checkoutError = 'Layanan pembayaran online sedang tidak tersedia. Silakan coba lagi atau hubungi dukungan.';

            return;
        } catch (Throwable $e) {
            report($e);
            $this->checkoutError = 'Pembayaran online belum dapat diproses. Silakan hubungi dukungan.';

            return;
        }

        session([
            $sessionKey => [
                'session_id' => $session->id,
                'link_url' => $session->payment_link_url,
            ],
        ]);

        $this->redirect($session->payment_link_url);
    }

    /**
     * Resolves this render's screen state — mode `'not_found'` (neither a
     * real renewal nor a pending selection), `'fee'` (a selection is
     * pending, formerly `RenewalFee::resolveState()`), or the three
     * payment-section outcomes `'denied'|'manual'|'online'` (a real renewal
     * exists, formerly `RenewalPayment::resolveState()`) — driven fresh from
     * the database on every render, exactly as before; the guard is never
     * trusted from an earlier render.
     *
     * @return array{mode: string, errorMessage: string, privacyRestricted: bool, quoteUnavailable: bool, graveView: ?GraveRecordProjection, quote: ?RenewalQuoteDraft, paymentState: string}
     */
    private function resolveState(): array
    {
        $empty = [
            'mode' => 'not_found',
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'privacyRestricted' => false,
            'quoteUnavailable' => false,
            'graveView' => null,
            'quote' => null,
            'paymentState' => 'none',
        ];

        if ($this->perpanjangan !== '') {
            return [...$empty, ...$this->resolvePaymentState()];
        }

        $graveId = RenewalGraveSelection::current();

        if ($graveId === null) {
            return $empty;
        }

        return [...$empty, ...$this->resolveFeeState($graveId)];
    }

    /**
     * @return array{mode: string, errorMessage: string, privacyRestricted: bool, quoteUnavailable: bool, graveView: ?GraveRecordProjection, quote: ?RenewalQuoteDraft}
     */
    private function resolveFeeState(string $graveId): array
    {
        $fee = [
            'mode' => 'fee',
            'errorMessage' => '',
            'privacyRestricted' => false,
            'quoteUnavailable' => false,
            'graveView' => null,
            'quote' => null,
        ];

        if (app(ModeResolver::class)->graveSearchMode() === GraveSearchMode::ManualAssistance) {
            return [...$fee, 'errorMessage' => 'Pencarian data makam secara online belum tersedia. Silakan hubungi petugas kami.'];
        }

        $grave = Str::isUuid($graveId)
            ? GraveRecord::query()->with('cemetery')->find($graveId)
            : null;

        if (! $grave instanceof GraveRecord) {
            return [...$fee, 'errorMessage' => 'Data makam tidak ditemukan.'];
        }

        $graveView = GraveRecordProjection::fromRecord($grave, $grave->cemetery?->name);

        if ($graveView->isRestricted()) {
            return [...$fee, 'graveView' => $graveView, 'privacyRestricted' => true];
        }

        if ($this->actionMessage !== '') {
            return [...$fee, 'graveView' => $graveView, 'errorMessage' => $this->actionMessage];
        }

        try {
            $quote = app(QuoteRenewal::class)($grave);
        } catch (\InvalidArgumentException) {
            return [...$fee, 'graveView' => $graveView, 'quoteUnavailable' => true];
        }

        return [...$fee, 'graveView' => $graveView, 'quote' => $quote];
    }

    /**
     * @return array{mode: string, errorMessage: string, paymentState: string}
     */
    private function resolvePaymentState(): array
    {
        $notFound = [
            'mode' => 'not_found',
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'paymentState' => 'none',
        ];

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            return $notFound;
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            return ['mode' => 'payment', 'errorMessage' => '', 'paymentState' => 'denied'];
        }

        $result = app(GuardRenewalPaymentOpening::class)($renewal, $quote->amountAsMoney());

        if (! $result->isAllowed()) {
            return ['mode' => 'payment', 'errorMessage' => '', 'paymentState' => 'denied'];
        }

        return [
            'mode' => 'payment',
            'errorMessage' => '',
            'paymentState' => $result->isManualCoordinationRequired() ? 'manual' : 'online',
        ];
    }
}
```

- [ ] **Step 3: Rewrite `payment.blade.php` to fold in `fee.blade.php`'s fee card**

Replace the entire contents of `resources/views/livewire/public/renewal/payment.blade.php`:

```blade
{{--
    resources/views/livewire/public/renewal/payment.blade.php

    App\Livewire\Public\Renewal\RenewalPayment's view — `/perpanjangan/
    pembayaran`, Screen 2 "Biaya & Bayar" of the consolidated renewal
    journey (journey steps 4-5: Biaya, Pembayaran). Merge of the former
    payment.blade.php (step 5) and fee.blade.php (step 4) — every state each
    one rendered is preserved verbatim below. `$mode` (`'not_found'`|
    `'fee'`|`'payment'`) drives which half renders; within `'payment'` mode
    the pre-existing `$paymentState` (`'denied'`|`'manual'`|`'online'`)
    still drives the three payment branches exactly as before.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @if ($mode === 'not_found')
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">
                    {{ $errorMessage }}
                </h1>
                <x-mk.button variant="primary" href="/bantuan">
                    Hubungi Bantuan
                </x-mk.button>
            </div>

        @elseif ($mode === 'fee')
            @if ($errorMessage)
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                    <h1 class="text-lg font-semibold text-neutral-800">
                        {{ $errorMessage }}
                    </h1>
                    <x-mk.button variant="primary" href="/bantuan">
                        Hubungi Bantuan
                    </x-mk.button>
                </div>
            @elseif ($privacyRestricted)
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                    <h1 class="text-lg font-semibold text-neutral-800">
                        Data makam ini dibatasi.
                    </h1>
                    <p class="max-w-prose text-base text-neutral-600">
                        Data makam ini tercatat, namun tidak dapat ditampilkan secara
                        online. Silakan hubungi petugas kami untuk mengurus perpanjangan
                        makam ini.
                    </p>
                    <x-mk.button variant="primary" href="/bantuan" class="mt-2">
                        Hubungi Bantuan
                    </x-mk.button>
                </div>
            @elseif ($quoteUnavailable)
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                    <h1 class="text-lg font-semibold text-neutral-800">
                        Tarif tidak tersedia.
                    </h1>
                    <p class="max-w-prose text-base text-neutral-600">
                        Kami belum dapat menghitung tarif perpanjangan untuk makam ini.
                        Silakan hubungi petugas kami untuk informasi lebih lanjut.
                    </p>
                    <x-mk.button variant="primary" href="/bantuan" class="mt-2">
                        Hubungi Bantuan
                    </x-mk.button>
                </div>
            @elseif ($graveView && $quote)
                <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                        Biaya Perpanjangan
                    </h1>
                    <p class="text-base text-neutral-600">
                        Perpanjangan masa sewa makam
                        <span class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</span>
                        di
                        <span class="font-medium text-neutral-900">{{ $graveView->cemeteryName ?? 'TPU' }}</span>.
                    </p>
                </div>

                <div class="mx-auto mb-8 max-w-prose">
                    <x-mk.card>
                        <div class="flex flex-col gap-6">
                            <div class="flex flex-col gap-2 border-b border-neutral-200 pb-4">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <span class="text-neutral-600">Nama almarhum</span>
                                    <span class="font-medium text-neutral-900">{{ $graveView->deceasedName }}</span>

                                    <span class="text-neutral-600">Blok</span>
                                    <span class="font-medium text-neutral-900">{{ $graveView->block ?? '—' }}</span>

                                    <span class="text-neutral-600">Jatuh tempo saat ini</span>
                                    <span class="font-medium text-neutral-900">
                                        {{ $graveView->dueDate ?? '—' }}
                                    </span>
                                </div>
                            </div>

                            <div class="flex flex-col gap-1">
                                <span class="text-sm text-neutral-600">Estimasi biaya perpanjangan</span>
                                <span class="font-mono text-3xl font-bold text-neutral-900">
                                    {{ $quote->amountAsMoney()->format() }}
                                </span>
                            </div>

                            <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                    <span class="text-neutral-600">Sumber tarif</span>
                                    <span class="font-medium text-neutral-900">{{ $quote->tariffSource }}</span>

                                    <span class="text-neutral-600">Terakhir diperbarui</span>
                                    <span class="font-medium text-neutral-900">
                                        {{ $quote->tariffEffectiveAt?->format('d F Y') ?? '—' }}
                                    </span>
                                </div>
                            </div>

                            @if ($quote->hasLateFine())
                                <div class="flex flex-col gap-1 border-t border-neutral-200 pt-4">
                                    <span class="text-sm text-neutral-600">Denda keterlambatan</span>
                                    <span class="font-mono text-lg font-semibold text-neutral-900">
                                        {{ $quote->lateFineAsMoney()->format() }}
                                    </span>
                                    <span class="text-sm text-neutral-500">
                                        Dasar: {{ $quote->lateFineBasis }}
                                    </span>
                                </div>
                            @endif

                            <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                                <x-mk.button
                                    variant="primary"
                                    type="button"
                                    wire:click="terimaDanLanjutkan"
                                    wire:loading.attr="disabled"
                                    class="w-full justify-center"
                                >
                                    Terima Tarif &mdash; Lanjut ke Pembayaran
                                </x-mk.button>
                                <p class="text-center text-sm text-neutral-500">
                                    Dengan melanjutkan, Anda menyetujui estimasi biaya di atas.
                                </p>
                            </div>
                        </div>
                    </x-mk.card>
                </div>
            @endif

        @else
            {{-- $mode === 'payment' — unchanged from the pre-merge payment.blade.php --}}
            @if ($paymentState === 'denied')
                <div class="flex flex-col items-center gap-3 py-12 text-center">
                    <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                    <h1 class="text-lg font-semibold text-neutral-800">
                        Pembayaran tidak dapat diproses
                    </h1>
                    <p class="max-w-prose text-base text-neutral-600">
                        Perpanjangan ini belum dapat dilanjutkan ke pembayaran.
                        Silakan hubungi petugas kami untuk memeriksa statusnya.
                    </p>
                    <x-mk.button variant="primary" href="/bantuan" class="mt-2">
                        Hubungi Bantuan
                    </x-mk.button>
                </div>
            @else
                <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                        Pembayaran Perpanjangan
                    </h1>
                    <p class="text-base text-neutral-600">
                        Perpanjangan masa sewa makam.
                    </p>
                </div>

                <div class="mx-auto mb-8 max-w-prose">
                    @if ($paymentState === 'online')
                        <x-mk.card>
                            <div class="flex flex-col gap-3">
                                <h3 class="text-base font-semibold text-neutral-900">
                                    Pembayaran Online
                                </h3>
                                <p class="text-sm text-neutral-600">
                                    Anda akan diarahkan ke halaman pembayaran untuk
                                    menyelesaikan perpanjangan masa sewa makam.
                                </p>
                            </div>

                            @if ($isSandboxPayment)
                                <x-mk.alert
                                    intent="urgent"
                                    icon="exclamation-triangle"
                                    title="ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN"
                                    live="off"
                                    class="mt-3"
                                >
                                    <p class="text-sm">
                                        Makam.co.id masih dalam masa uji coba publik (beta). Halaman
                                        pembayaran di bawah ini adalah <strong>simulasi (sandbox)</strong>
                                        milik penyedia pembayaran &mdash; tidak ada transaksi finansial
                                        nyata yang terjadi, berapa pun nominal yang tertera. Perpanjangan
                                        Anda tetap tercatat, dan tim kami akan menghubungi Anda secara
                                        langsung apabila diperlukan.
                                    </p>
                                </x-mk.alert>
                            @endif

                            @if ($checkoutError !== null)
                                <x-mk.alert
                                    intent="danger"
                                    title="Pembayaran online belum dapat diproses"
                                    live="assertive"
                                    class="mt-3"
                                >
                                    <p class="text-sm">{{ $checkoutError }}</p>
                                    <x-slot name="action">
                                        <x-mk.button variant="secondary" size="sm" href="/bantuan">
                                            Butuh bantuan?
                                        </x-mk.button>
                                    </x-slot>
                                </x-mk.alert>
                            @endif

                            <div class="mt-3 flex flex-wrap items-center gap-3">
                                <x-mk.button
                                    variant="primary"
                                    wire:click="payOnline"
                                    wire:loading.attr="disabled"
                                    wire:target="payOnline"
                                >
                                    Bayar Sekarang
                                </x-mk.button>
                                <span wire:loading wire:target="payOnline" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                    <x-mk.spinner class="size-4" aria-hidden="true" />
                                    Membuka halaman pembayaran&hellip;
                                </span>
                            </div>
                        </x-mk.card>
                    @else
                        <x-mk.card>
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-col gap-3">
                                    <div class="flex items-start gap-3">
                                        <x-dynamic-component
                                            component="icon.alert-circle"
                                            class="size-6 text-blue-600 flex-shrink-0 mt-0.5"
                                            aria-hidden="true"
                                        />
                                        <div class="flex flex-col gap-1">
                                            <p class="font-medium text-neutral-900">
                                                Koordinasi manual diperlukan
                                            </p>
                                            <p class="text-sm text-neutral-600">
                                                Pembayaran online untuk perpanjangan makam saat ini
                                                belum tersedia. Silakan hubungi petugas kami untuk
                                                koordinasi manual.
                                            </p>
                                        </div>
                                    </div>
                                </div>

                                <div class="flex flex-col gap-2 border-t border-neutral-200 pt-4">
                                    <x-mk.button
                                        variant="primary"
                                        href="/perpanjangan/konfirmasi?perpanjangan={{ $perpanjangan }}"
                                        class="w-full justify-center"
                                    >
                                        Lanjutkan ke Konfirmasi
                                    </x-mk.button>
                                    <p class="text-center text-sm text-neutral-500">
                                        Dengan melanjutkan, Anda akan menyelesaikan proses
                                        perpanjangan setelah pembayaran dilakukan.
                                    </p>
                                </div>
                            </div>
                        </x-mk.card>
                    @endif
                </div>
            @endif
        @endif

        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan dengan pembayaran?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
```

- [ ] **Step 4: Delete the two files this task absorbs**

```bash
git rm app/Livewire/Public/Renewal/RenewalFee.php resources/views/livewire/public/renewal/fee.blade.php
```

- [ ] **Step 5: Remove the `/perpanjangan/biaya` route and its now-unused import**

In `routes/web.php`:

old_string:
```
use App\Livewire\Public\Renewal\RenewalConfirmation;
use App\Livewire\Public\Renewal\RenewalFee;
use App\Livewire\Public\Renewal\RenewalPayment;
```

new_string:
```
use App\Livewire\Public\Renewal\RenewalConfirmation;
use App\Livewire\Public\Renewal\RenewalPayment;
```

old_string:
```
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/biaya', RenewalFee::class)->name('perpanjangan.biaya');
Route::get('/perpanjangan/pembayaran', RenewalPayment::class)->name('perpanjangan.pembayaran');
```

new_string:
```
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/pembayaran', RenewalPayment::class)->name('perpanjangan.pembayaran');
```

- [ ] **Step 6: Migrate `RenewalFeeTest`'s methods into `RenewalPaymentTest`**

Every test in `RenewalFeeTest.php` follows `Livewire::test(RenewalFee::class, ['makam' => $grave->id])->...`, where `$grave->id` was the ONLY thing standing in for "a selection has been made." Migrate each by: replacing the target class with `RenewalPayment`, and replacing the constructor param `['makam' => $grave->id]` with a session seed (`RenewalGraveSelection::remember($grave->id)`) called BEFORE `Livewire::test(RenewalPayment::class)` (no constructor param — the merged component reads the selection from session, not from a public property). Two fully worked examples (apply the same transformation to every other method in that file):

```php
    /**
     * Migrated from RenewalFeeTest — the fee section still always shows
     * tariff source and last-update, now reached via a session-remembered
     * selection instead of a `?makam=` constructor param.
     */
    public function test_the_fee_screen_always_shows_the_tariff_source_and_last_update(): void
    {
        $this->openTheDataGate();
        $cemetery = $this->cemeteryWithPrice();
        $grave = \App\Domain\GraveRegistry\Models\GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);
        \App\Domain\Renewal\RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->assertOk()
            ->assertSee('Sumber tarif')
            ->assertSee('Terakhir diperbarui');
    }

    /**
     * Migrated from RenewalFeeTest — acceptance still creates exactly one
     * renewal; the merged component no longer redirects (Implementation
     * Decision 4) so this asserts `$perpanjangan` is now set in place of
     * the old `assertRedirect()`.
     */
    public function test_accepting_the_quote_creates_exactly_one_renewal_and_reveals_payment_in_place(): void
    {
        $this->openTheDataGate();
        $grave = \App\Domain\GraveRegistry\Models\GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        \App\Domain\Renewal\RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('perpanjangan', fn (string $id) => $id !== '')
            ->assertSee('Pembayaran');

        $this->assertDatabaseCount('renewals', 1);
        $this->assertDatabaseCount('renewal_quotes', 1);

        \App\Domain\Renewal\RenewalGraveSelection::remember($grave->id);
        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSet('actionMessage', fn (string $m) => str_contains($m, 'sudah tercatat'));

        $this->assertDatabaseCount('renewals', 1);
    }
```

Apply the identical mechanical transformation (target class → `RenewalPayment`, constructor param removed, `RenewalGraveSelection::remember($grave->id)` seeded before each `Livewire::test()` call, any `->assertRedirect()`/`->assertNoRedirect()` on the acceptance flow re-expressed against `$perpanjangan`/`actionMessage` as shown above) to the remaining methods in `RenewalFeeTest.php`:
- `test_no_late_fine_figure_is_rendered_when_there_is_no_written_basis`
- `test_the_fee_screen_shows_the_renewal_amount`
- `test_a_grave_without_a_tariff_source_renders_a_useful_error`
- `test_the_stepper_shows_step_4_as_current`
- `test_support_escape_hatch_is_present`
- `test_rendering_the_fee_screen_writes_nothing`
- `test_a_closed_record_shows_the_privacy_limited_state_and_no_grave_fields`
- `test_a_limited_record_shows_the_privacy_limited_state_and_no_identity`
- `test_a_restricted_record_cannot_be_renewed_by_calling_the_action_directly`
- `test_an_unknown_grave_reports_not_found_rather_than_rendering_a_broken_card` — this one and the next need a DIFFERENT transformation: they exercise a malformed/unknown `?makam=` value, which no longer exists as an input at all (the merged component never accepts a grave id from the client). Re-express them instead as: `RenewalGraveSelection::remember('0198f000-0000-7000-8000-000000000000')` (unknown) / `RenewalGraveSelection::remember('not-a-uuid')` (malformed) before `Livewire::test(RenewalPayment::class)`, keeping the same `assertSee('tidak ditemukan')` expectations.
- `test_a_malformed_makam_parameter_reports_not_found_rather_than_crashing` — see the note directly above; same re-expression.
- `test_a_grave_without_a_due_date_shows_quote_unavailable_and_acceptance_writes_nothing`

After migrating every method, delete the source file:

```bash
git rm tests/Feature/Livewire/Public/Renewal/RenewalFeeTest.php
```

- [ ] **Step 7: Write new tests for the merged screen's id-lessness, the explicit-click-only guarantee, and the live guard re-evaluation**

Add to `RenewalPaymentTest.php`:

```php
    /**
     * The whole point of Task 3's `RenewalGraveSelection` — proves a
     * complete search-then-fee-then-accept flow never puts the grave's id
     * anywhere the client can read it: not in the fee screen's rendered
     * HTML, not in any `#[Url]`-bound property, not in the URL Livewire
     * reflects for `$perpanjangan` (which only ever holds the RENEWAL's id,
     * created only after explicit acceptance).
     */
    public function test_a_search_then_fee_flow_never_exposes_the_grave_id_anywhere_client_visible(): void
    {
        $this->openTheDataGate();
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
        $cemetery = $this->cemeteryWithPrice();
        $grave = GraveRecord::factory()->create([
            'cemetery_id' => $cemetery->id,
            'deceased_name' => 'Contoh Tanpa Id Uji',
        ]);

        $search = Livewire::test(\App\Livewire\Public\Renewal\RenewalStart::class, [
            'cemeteryId' => $cemetery->id,
            'name' => 'Contoh Tanpa Id Uji',
        ])->call('search');

        $this->assertStringNotContainsString($grave->id, $search->html());

        $search->call('selectGraveForRenewal', 0)->assertRedirect(route('perpanjangan.pembayaran'));

        $fee = Livewire::test(RenewalPayment::class);
        $this->assertStringNotContainsString($grave->id, $fee->html());
        $fee->assertSet('perpanjangan', '');

        $fee->call('terimaDanLanjutkan');

        $renewalId = $fee->get('perpanjangan');
        $this->assertNotSame('', $renewalId);
        $this->assertNotSame($grave->id, $renewalId);
        $this->assertDatabaseHas('renewals', ['id' => $renewalId, 'grave_record_id' => $grave->id]);
    }

    /**
     * OpenRenewal must fire only from the explicit "Terima Tarif" click —
     * never from mount, never from a bare render. Merely rendering the fee
     * section with a pending selection must write nothing.
     */
    public function test_merely_rendering_the_fee_section_writes_nothing(): void
    {
        $this->openTheDataGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        \App\Domain\Renewal\RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)->assertOk();
        Livewire::test(RenewalPayment::class)->assertOk();

        $this->assertDatabaseCount('renewals', 0);
    }

    /**
     * The payment section re-evaluates GuardRenewalPaymentOpening fresh on
     * every render, even immediately after acceptance within the same
     * component instance — never trusting a stale accepted-state from the
     * fee half. Mirrors `test_pay_online_is_refused_when_the_gate_closes_
     * before_the_click` but exercises the NEW in-place fee-to-payment
     * transition rather than a bookmark arrival.
     */
    public function test_the_payment_section_re_evaluates_the_guard_immediately_after_in_place_acceptance(): void
    {
        $this->openTheDataGate();
        $this->closeThePaymentGate();
        $grave = GraveRecord::factory()->create(['cemetery_id' => $this->cemeteryWithPrice()->id]);
        \App\Domain\Renewal\RenewalGraveSelection::remember($grave->id);

        Livewire::test(RenewalPayment::class)
            ->call('terimaDanLanjutkan')
            ->assertSee('koordinasi manual')
            ->assertDontSee('Bayar Sekarang');
    }
```

- [ ] **Step 8: Run the merged `RenewalPaymentTest`, the full renewal suite, and Pint/PHPStan**

```bash
docker run -d --rm --name wsc-t5-pg -e POSTGRES_USER=test -e POSTGRES_PASSWORD=test -e POSTGRES_DB=makam_test -p 55505:5432 postgres:18
docker run -d --rm --name wsc-t5-redis -p 63505:6379 redis:8.2-alpine
docker run --network host --rm -e PGPASSWORD=test postgres:18 psql -h 127.0.0.1 -p 55505 -U test -d makam_test -c "CREATE EXTENSION pg_trgm;"
docker run --network host --user 1000:1000 \
  -e DB_HOST=127.0.0.1 -e DB_PORT=55505 -e DB_DATABASE=makam_test -e DB_USERNAME=test -e DB_PASSWORD=test \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=63505 \
  -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html \
  ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 \
  php -d memory_limit=1G vendor/bin/phpunit tests/Feature/Livewire/Public/Renewal/
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/pint --test
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 vendor/bin/phpstan analyse --memory-limit=1G
```

Expected: every test in `tests/Feature/Livewire/Public/Renewal/` PASSES, Pint and PHPStan clean, `route:list --name=perpanjangan.biaya` (and `.cari`, from Task 4) return no rows, and exactly three renewal routes remain: `perpanjangan.index`, `perpanjangan.pembayaran`, `perpanjangan.konfirmasi`.

- [ ] **Step 9: Teardown and commit**

```bash
docker stop wsc-t5-pg wsc-t5-redis
git add app/Livewire/Public/Renewal/RenewalPayment.php resources/views/livewire/public/renewal/payment.blade.php routes/web.php tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php
git commit -m "feat(renewal): merge RenewalFee into RenewalPayment as Screen 2 'Biaya & Bayar'"
```

---

### Task 6: Documentation — screen-inventory.md and traceability-matrix.md

**Files:**
- Modify: `docs/product/screen-inventory.md`
- Modify: `docs/domain/traceability-matrix.md`

**Interfaces:** None — documentation only. No AC numbers, PUB screen ids, or BOOK-/REN- row keys are added, removed, or renumbered; only which physical screen a row's UI now lives on is corrected, per this repo's own "do not duplicate canonical catalogue data" and "annotate, don't silently rewrite" conventions (see the existing dated correction notes already in both files).

- [ ] **Step 1: Add a dated correction note to `screen-inventory.md`**

In `docs/product/screen-inventory.md`, insert a new note following the file's existing convention (a dated paragraph above the table, matching the style of the "PUB-080 was asserting something false" and "S4-T4/S4-T5 resumed" notes already there):

Insert after the existing note beginning `The batches whose rows below said "implemented, pending merge/CI"...` (the one ending `...per this file's convention of annotating superseded reasoning rather than silently rewriting it.`), before the `PUB-017, PUB-019, and PUB-023 are corrected below` note:

```markdown
**29 Aug 2026 — wizard screen consolidation.** Both public wizards were
regrouped into fewer page-turns without changing their documented step
count, order, or labels (`docs/superpowers/specs/2026-08-29-wizard-screen-
consolidation-design.md`). PUB-010 through PUB-018 (booking's nine steps)
now render behind four screens of the SAME `/pemesanan-makam` route: Screen
1 "Cari & Pilih" = PUB-010/011/012/013 (steps 1-4); Screen 2 "Detail
Pemesanan" = PUB-014/015/016 (steps 5-7, Ringkasan now a persistent summary
card alongside Data Pemesan/Almarhum rather than its own page); Screen 3
"Pembayaran" = PUB-017 (step 8, kept standalone); Screen 4 "Konfirmasi" =
PUB-018 (step 9). No row's states, ACs, or route changed — only how many of
their step headings are simultaneously on screen at once. PUB-030 and
PUB-031 (renewal's Kota/TPU/TPS/Cari Makam) merge into one screen at the
SAME `/perpanjangan` route (`GraveSearch` merged into `RenewalStart`);
`/perpanjangan/cari` no longer resolves to a route. PUB-032 and PUB-033
(Biaya, Pembayaran) merge into one screen at the SAME `/perpanjangan/
pembayaran` route (`RenewalFee` merged into `RenewalPayment`);
`/perpanjangan/biaya` no longer resolves to a route. PUB-034 (Konfirmasi)
is unchanged. The pre-existing gap PUB-031/PUB-032 recorded implicitly — no
live UI path from a search result to the fee screen, `RenewalFee`'s
`?makam=` reachable only via a hand-built URL — is closed by this merge via
a session-backed handoff (`App\Domain\Renewal\RenewalGraveSelection`) that
never puts a grave id in a URL.
```

- [ ] **Step 2: Update the individual PUB-010..018 and PUB-030..034 row text**

In `docs/product/screen-inventory.md`, append a short route note to each affected row (do not touch any other text in the row — states, AC coverage and shipped dates are unchanged facts). Example for PUB-010 (apply the same one-clause append to PUB-011 through PUB-018, substituting the correct screen number 1-4 per the mapping in Step 1's note):

old_string:
```
| PUB-010 | Booking Step 1 — Kota — `/pemesanan-makam` | loading, populated, no city — **shipped** 13 Aug 2026 (PR #27, `lane/l6-booking-completion`). All five launch cities are offered here in canonical order, so this screen no longer borrows PUB-011's filters as evidence |
```

new_string:
```
| PUB-010 | Booking Step 1 — Kota — `/pemesanan-makam` (**Screen 1 "Cari & Pilih"** since 29 Aug 2026 — see this file's wizard-screen-consolidation note) | loading, populated, no city — **shipped** 13 Aug 2026 (PR #27, `lane/l6-booking-completion`). All five launch cities are offered here in canonical order, so this screen no longer borrows PUB-011's filters as evidence |
```

For PUB-030 and PUB-031, append `(**Screen 1 "Cari Makam"**, merged into `RenewalStart` 29 Aug 2026)`; for PUB-032 and PUB-033, append `(**Screen 2 "Biaya & Bayar"**, merged into `RenewalPayment` 29 Aug 2026; route `/perpanjangan/biaya` retired)`. PUB-034 and PUB-020 through PUB-024 (marketplace, unaffected) and every other row are left untouched.

- [ ] **Step 3: Add a matching correction note to `traceability-matrix.md`**

In `docs/domain/traceability-matrix.md`, insert a new dated note near the existing REN-04/REN-05/REN-06 correction note (search for `**REN-04, REN-05, REN-06 (fee, payment, confirmation).**`), following its own convention of appending rather than rewriting:

```markdown
- **BOOK-01…BOOK-09, REN-01…REN-06 (29 Aug 2026 — wizard screen
  consolidation).** Every row's Screen and Evidence cells above are
  unchanged and remain accurate: an AC's evidence is still the SAME test
  file, and a screen's PUB id still names the SAME functional screen. What
  changed is presentation grouping only, recorded in `screen-inventory.md`'s
  own dated note for this change — no AC gained or lost coverage, and no
  test referenced here was deleted, only some were moved between files
  during the `GraveSearch`→`RenewalStart` and `RenewalFee`→`RenewalPayment`
  merges (their new locations: `tests/Feature/Livewire/Public/Renewal/
  RenewalStartTest.php` and `RenewalPaymentTest.php` respectively).
```

- [ ] **Step 4: Verify `ci/verify-docs.sh` still passes**

```bash
docker run --network host --user 1000:1000 -v /home/ubuntu/makam-app/.worktrees/wizard-screen-consolidation:/var/www/html -w /var/www/html ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3 bash ci/verify-docs.sh
```

Expected: exits 0. (This script needs no Postgres/Redis and can run in the same Docker image invocation as the other checks.)

- [ ] **Step 5: Commit**

```bash
git add docs/product/screen-inventory.md docs/domain/traceability-matrix.md
git commit -m "docs: record wizard screen consolidation's new screen boundaries"
```

---

## Self-Review

**Spec coverage:**
- Booking 9→4 screens, progressive reveal, stepper/dot behavior unchanged, `<x-mk.stepper>` untouched, no `labels` override — Tasks 1-2. ✅
- `goToStep`/screen-boundary Ruling written explicitly — Implementation Decision 1. ✅
- `routeBackToPlotPickerAfterExpiredHold()` interaction with screen 1's reveal state thought through and documented — Implementation Decision 2. ✅
- Renewal 6→3 screens, `GraveSearch`→`RenewalStart` merge, `RenewalFee`→`RenewalPayment` merge, `RenewalConfirmation` untouched — Tasks 3-5. ✅
- Two routes removed (`/perpanjangan/cari`, `/perpanjangan/biaya`), codebase-wide reference check performed before removal — Tasks 4-5, Step 1 of each. ✅
- Bookmarkability preserved with the exact same param names (`kota`, `tpu`, `nama`, `blok`, `tanggal`, `perpanjangan`) — Tasks 4-5 (all `#[Url]` bindings carried over unchanged). ✅
- The id-less search→fee gap closed without reopening the privacy tradeoff, `GraveRecordProjection` untouched — Task 3 + Implementation Decision 3. ✅
- `OpenRenewal` fires only from an explicit "Terima Tarif" click, never from `mount()`/render/GET — Task 5's `terimaDanLanjutkan()`, proven by Task 5 Step 7's `test_merely_rendering_the_fee_section_writes_nothing`. ✅
- `GuardRenewalPaymentOpening` re-evaluated fresh every render, same four conditions, same manual-coordination fallback — Task 5's `resolvePaymentState()` (byte-for-byte the original `RenewalPayment::resolveState()` logic) + Step 7's guard-re-evaluation test. ✅
- Documentation task (screen-inventory.md, traceability-matrix.md) — Task 6. ✅
- Global Constraints (strict_types, Docker wrapper, Postgres/Redis containers, Pint/PHPStan, no domain Action signature changes) — stated verbatim and applied in every task. ✅

**Placeholder scan:** No "TBD"/"add appropriate handling"/"similar to Task N" phrasing was used for any NEW production code — every new class, method, and Blade block above is complete, real code. The two places bulk pre-existing tests are migrated (Task 4 Step 6, Task 5 Step 6) give a fully specified mechanical transformation rule plus two complete worked examples each, plus an explicit, named list of every remaining method to migrate — this is a specified rule applied repeatedly, not an unspecified "write appropriate tests" instruction. Task 2 Step 14's "triage any step-exclusivity assumptions" step names the exact test files at risk and the exact assertion SHAPE to look for and fix, for the same reason (an existing 1,600-line-plus test suite cannot be reproduced verbatim in a plan without drowning the actually-new logic).

**Type/name consistency across tasks:**
- `BookingWizard::currentScreen(): int` (Task 1) is called as `$this->currentScreen()` in the Blade edits (Task 2) and in the `render()` guard fixes (Task 2) — consistent.
- `GraveRegistryPublicQuery::resolveOpenRecordAt(GraveSearchCriteria, int): ?GraveRecord` (Task 3) is called identically in `RenewalStart::selectGraveForRenewal()` (Task 4).
- `RenewalGraveSelection::remember(string): void` / `::current(): ?string` / `::forget(): void` (Task 3) are called with matching signatures in `RenewalStart` (Task 4: `remember()`) and `RenewalPayment` (Task 5: `current()`, `forget()`).
- `RenewalPayment::$perpanjangan` stays a `#[Url(as: 'perpanjangan', history: true)] public string` property throughout — Task 5 does not rename it, so `payment.blade.php`'s pre-existing `href="...?perpanjangan={{ $perpanjangan }}"` link (manual-coordination card) keeps working unchanged.
- Route name `perpanjangan.pembayaran` is what `RenewalStart::selectGraveForRenewal()` (Task 4) redirects to and what remains registered after Task 5 removes `perpanjangan.biaya` — same string, verified against the real `routes/web.php` edit in both tasks.
