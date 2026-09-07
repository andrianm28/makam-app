# Batch 2D — Booking Wizard Step 2 Validator Ignores Service Type (UXB-02) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix `SaveBookingDraftStep::validateDeceasedData()` so the Pre-Need booking path no longer fails validation on `CUSTOMER_AND_DECEASED_DATA` (Step 2) purely because it does not know `service_type`, while keeping the At-Need path's genuinely-required fields mandatory.

**Architecture:** `SaveBookingDraftStep::__invoke()` already has `$draft` in scope when it calls `validateDeceasedData($payload)`. `$draft->service_type` was persisted by the earlier `DISCOVERY` step and is read-only here — no new parameter is threaded through `__invoke`'s own signature, only through the private static helper. `validateDeceasedData(array $payload, ?string $serviceType)` branches on `$serviceType === BookingServiceType::PRE_NEED`:

- **Pre-Need**: `deceased_full_name` and `deceased_relationship` become optional (validated only if a non-blank value is present); dates were already going to become optional-but-validated for At-Need, so Pre-Need gets the same treatment for free by unifying the date logic.
- **At-Need (`NEW_GRAVE`, `OVERLAPPING_GRAVE`, `URGENT_TODAY`, and — for the isolated unit-test fixtures that never actually save `DISCOVERY` — `null`)**: `deceased_full_name` and `deceased_relationship` stay mandatory exactly as today. `deceased_date_of_birth` and `deceased_date_of_death` become optional-but-validated-if-present for both branches, matching the Blade copy "Isi sebisa Anda" (`resources/views/livewire/public/booking/wizard.blade.php:993-995`), which already promised this and was contradicted by the current strict validator.
- `deceased_gender` and the three `document_*_path` refusals are unaffected by this batch.

The arm-persistence `match` in the same file's `DB::transaction()` closure currently writes `deceased_full_name`, `deceased_date_of_birth`, `deceased_date_of_death`, `deceased_relationship` unconditionally from the payload (`self::trimmed(...)` / raw passthrough). Once validation no longer requires these keys to be present, an absent key raises a PHP "undefined array key" warning and — worse — persists `''`/`null` inconsistently. Fix: use `self::nullIfBlank($payload[...] ?? null)` for all four fields (matching the existing `deceased_gender` convention), so a Pre-Need draft that supplies none of them persists clean `NULL`s, not empty strings.

**Tech Stack:** Laravel 13 Actions pattern, PHPUnit Feature tests against real PostgreSQL (never SQLite per project convention).

**Spec:** `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` §Batch 2D (UXB-02).

## Global Constraints

- declare(strict_types=1) is already present in the target file; no new files needed for the fix itself.
- vendor/bin/pint --test and vendor/bin/phpstan analyse must stay clean.
- bash ci/verify-docs.sh must stay clean.
- Real Postgres for any test touching the database — never SQLite.
- No product-facing copy, route, or step is renamed, reordered, or hidden — this is a validator/persistence fix only.
- Existing `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php` fixtures create drafts with `service_type` left `null` (they never actually save `DISCOVERY`). Under the new rule `null !== PRE_NEED`, so those fixtures keep exercising the At-Need path. The two tests in that file asserting a *missing* date of birth/death is rejected are updated in place (not deleted) to assert the new optional-but-validated-if-present behavior, since the plan explicitly changes this for At-Need too; every other assertion in that file (mandatory name/relationship, format/range validation when a date *is* present, gender optionality, document-path refusal) is unchanged and must keep passing.

---

### Task 1: Thread `service_type` into `validateDeceasedData()` and make persistence match

**Files:**
- Modify: `app/Domain/Booking/Actions/SaveBookingDraftStep.php`
- Modify: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php` (update the two now-incorrect "missing date" assertions)
- Create: `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepServiceTypeValidationTest.php` (new PRE_NEED-optional and AT_NEED-partial-optional coverage)

**Interfaces:**
- Consumes: `App\Domain\Booking\BookingServiceType::PRE_NEED` (already imported in the target file).
- Changes signature of a `private static` helper only — no public API change, no caller outside this file to update.

- [ ] **Step 1: Write the failing tests** — one Feature test class asserting a `PRE_NEED` draft (with `DISCOVERY` completed and `service_type = PRE_NEED`) saves `CUSTOMER_AND_DECEASED_DATA` successfully with every `deceased_*` key omitted, persisting `NULL`s; and asserting an At-Need draft (`service_type = NEW_GRAVE`) still rejects a missing `deceased_full_name`/`deceased_relationship` but now accepts a missing `deceased_date_of_birth`/`deceased_date_of_death`, while still rejecting an unparseable date when one is supplied.
- [ ] **Step 2: Update `validateDeceasedData`'s signature and body** to accept `?string $serviceType`, branch full name/relationship requiredness on `$serviceType !== BookingServiceType::PRE_NEED`, and make both dates optional-but-validated-if-present for every service type.
- [ ] **Step 3: Update the one call site** in `__invoke()` to pass `$draft->service_type`.
- [ ] **Step 4: Fix persistence** — change `deceased_full_name`, `deceased_date_of_birth`, `deceased_date_of_death`, `deceased_relationship` in the `CUSTOMER_AND_DECEASED_DATA` arm of the attribute `match` to `self::nullIfBlank($payload['...'] ?? null)`.
- [ ] **Step 5: Update the two pre-existing "missing date" tests** in `SaveBookingDraftStepSteps678Test.php` to reflect the new optional-but-validated behavior.
- [ ] **Step 6: Run the full suite against real Postgres/Redis in Docker**; run pint, phpstan, verify-docs.sh.
