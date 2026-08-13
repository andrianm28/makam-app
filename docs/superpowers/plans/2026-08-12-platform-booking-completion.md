# Platform Booking Completion — Steps 6-9 Implementation Plan

> **For agentic workers:** Use superpowers:subagent-driven-development or superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Complete the booking wizard (Steps 6-9: Customer Data, Deceased Data + Documents, Payment, Confirmation) with `SaveBookingDraftStep` autosave for Steps 6-8 and draft resume, per `docs/product/mvp-scope.md` §2 and `docs/product/booking-wizard-fields.md`.

**Architecture:** Extends the existing `App\Domain\Booking` module — `BookingDraft` model gains fields for Steps 6-8; `SaveBookingDraftStep` gains `match` arms for Steps 6-8; `BookingWizard` Livewire component gains new `$persistableProperties` and step-action methods; the Blade view gains `{{-- Steps 6-9 --}}` branches.

**Privacy ruling: RESOLVED 12 Aug 2026 by the coordinator.** The question was how the draft token (`booking_drafts.id` UUID), exposed to unauthenticated users in the URL for autosave/resume, may guard the PII a draft accumulates from Step 6.

Ruling, as implemented:

1. **Session-only binding.** Cross-device resume is NOT a requirement. On `StartBookingDraft` a high-entropy secret is issued into the session and its SHA-256 stored on the row (`booking_drafts.resume_secret_hash`); `mount()` and every `saveStep*` resolve through `BookingDraftQuery::findBound()`, which fails closed when the secret is absent, empty or mismatched. The id alone grants nothing. See `App\Domain\Booking\BookingDraftBinding`.
2. **Real, minimal consent.** `privacy_notice_accepted_at` is stamped SERVER-SIDE only after validation observes a genuine `true` from an initially-unticked checkbox linked to `/privasi`. A caller-supplied timestamp is never honoured. The earlier implementation stamped `now()` unconditionally, recording consent nobody gave.
3. **Retention is bounded.** Abandoned drafts are deleted past a configured window by `booking:purge-stale-drafts` (`config/booking.php`, scheduled daily). PII does not persist indefinitely behind an abandoned booking.

---

## Step 0: Context from specs

- **MVP Scope §2:** Step 6 = Data Pemesan (name, mobile, email, address, relationship, contact channel, privacy notice); Step 7 = Data Almarhum + Documents (KTP, KK, Death Certificate — private upload, malware quarantine, signed URL); Step 8 = Payment (online when gate active, manual fallback when closed); Step 9 = Konfirmasi (order reference, status, invoice, notification status, next steps).
- **Order Lifecycle:** Draft submission → MASUK → DIVERIFIKASI → ... → DIBAYAR → DIPROSES → SELESAI. Step 9 (Konfirmasi) is the success screen after payment is confirmed.
- **Funeral Case Model:** At-Need/Urgent creates a `FuneralCase`. Step 8 payment triggers `MENUNGGU_PEMBAYARAN` → `DIBAYAR` transition.
- **Gated fallback rules:** Step 8 must show manual instructions when payment gate is closed.

---

## Step 1: Migration — add Steps 6-8 fields to `booking_drafts`

**File:** `database/migrations/2026_08_12_100000_add_booking_draft_steps_6_to_8_fields.php`

**Fields added:**

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `resume_secret_hash` | string(64) | yes | Access control — SHA-256 of the session-issued resume secret (privacy ruling 1) |
| `customer_full_name` | string(191) | yes | Step 6 |
| `customer_mobile` | string(20) | yes | Step 6 |
| `customer_email` | string(191) | yes | Step 6 |
| `customer_address` | text | yes | Step 6 |
| `customer_relationship` | string(32) | yes | Step 6 |
| `customer_contact_channel` | string(16) | yes | Step 6 |
| `privacy_notice_accepted_at` | timestamp | yes | Step 6 |
| `deceased_full_name` | string(191) | yes | Step 7 |
| `deceased_date_of_birth` | date | yes | Step 7 |
| `deceased_date_of_death` | date | yes | Step 7 |
| `deceased_relationship` | string(32) | yes | Step 7 |
| `deceased_gender` | string(16) | yes | Step 7 |
| `document_ktp_path` | string(500) | yes | Step 7 — private quarantine path |
| `document_kk_path` | string(500) | yes | Step 7 — private quarantine path |
| `document_death_certificate_path` | string(500) | yes | Step 7 — private quarantine path |
| `payment_method` | string(32) | yes | Step 8 — `ONLINE` or `MANUAL` |
| `payment_reference` | string(191) | yes | Step 8 — manual payment reference |

- [ ] **Step 1a: Write migration**
- [ ] **Step 1b: Verify syntax** `php -l migration`
- [ ] **Step 1c: Commit**

---

## Step 2: `BookingDraft` model — add Steps 6-8 fillable/casts/relations

**File:** `app/Domain/Booking/Models/BookingDraft.php`

Add to `$fillable`:
```php
'customer_full_name',
'customer_mobile',
'customer_email',
'customer_address',
'customer_relationship',
'customer_contact_channel',
'privacy_notice_accepted_at',
'deceased_full_name',
'deceased_date_of_birth',
'deceased_date_of_death',
'deceased_relationship',
'deceased_gender',
'document_ktp_path',
'document_kk_path',
'document_death_certificate_path',
'payment_method',
'payment_reference',
```

Add to `$casts`:
```php
'deceased_date_of_birth' => 'date',
'deceased_date_of_death' => 'date',
'privacy_notice_accepted_at' => 'datetime',
```

- [ ] **Step 2a: Update BookingDraft model**
- [ ] **Step 2b: Run existing model tests** `vendor/bin/phpunit tests/Feature/Domain/Booking/BookingDraftClosedListValidationTest.php`
- [ ] **Step 2c: Commit**

---

## Step 3: `SaveBookingDraftStep` — extend to Steps 6-8

**File:** `app/Domain/Booking/Actions/SaveBookingDraftStep.php`

### Step 6 — CUSTOMER_DATA validation

Required: `customer_full_name`, `customer_mobile`, `customer_email`, `customer_address`, `customer_relationship`, `customer_contact_channel`, `privacy_notice_accepted_at` (boolean)

Validation rules:
- `customer_full_name`: string, min 3, max 191
- `customer_mobile`: Indonesian mobile pattern (`+62` or `0` prefix, 9-13 digits)
- `customer_email`: valid email format
- `customer_address`: string, min 10 chars
- `customer_relationship`: one of `PASANGAN`, `ANAK`, `ORANG_TUA`, `SAUDARA`, `LAINNYA`
- `customer_contact_channel`: one of `WHATSAPP`, `TELEPON`, `EMAIL`
- `privacy_notice_accepted`: a BOOLEAN that must be strictly `true` (privacy ruling 2). The caller asserts only that the box was ticked; a caller-supplied `privacy_notice_accepted_at` is ignored.

Attributes set: all above fields, plus `privacy_notice_accepted_at` stamped from the SERVER clock once consent is observed.

### Step 7 — DECEASED_DATA validation

Required: `deceased_full_name`, `deceased_date_of_birth`, `deceased_date_of_death`, `deceased_relationship`

**Deviation (deliberate, 12 Aug 2026):** `deceased_gender` is OPTIONAL, not required as this plan originally listed. A family may not wish to state it, and a funeral booking must not be blocked on it. The Blade label reads "(opsional)" to match. Recorded here rather than left as a silent divergence between plan and code.

Document paths are REFUSED, not stored. Upload belongs to `App\Platform\DocumentVault` and is out of scope here, so there is no legitimate caller with a path to supply; accepting free text would let a caller point a draft at an arbitrary storage location, including another customer's quarantined identity document. A non-null `document_ktp_path`, `document_kk_path` or `document_death_certificate_path` is a validation error. The columns remain for the lane that wires DocumentVault in properly.

Validation rules:
- `deceased_full_name`: string, min 3, max 191
- `deceased_date_of_birth`: valid date, must be before `deceased_date_of_death`
- `deceased_date_of_death`: valid date, must be before or equal today
- `deceased_relationship`: same closed list as Step 6
- `deceased_gender`: one of `LAKI_LAKI`, `PEREMPUAN`

Attributes set: all above + document paths.

### Step 8 — PAYMENT validation

Required: `payment_method`

Validation rules:
- `payment_method`: one of `ONLINE`, `MANUAL`
- `payment_reference`: required when `payment_method === MANUAL`, max 191 chars
- **G-PAY-01 is enforced HERE, server-side**, via `app(ModeResolver::class)->paymentMode()`: `ONLINE` is rejected whenever the gate is closed. The Blade view decides what to RENDER; it never decides what is ALLOWED. Hiding the online button while the Action still accepted `ONLINE` left the closed gate bypassable by any caller invoking `saveStep8('ONLINE')`, which Livewire exposes as a plain client-callable method.

Attributes set: `payment_method`, `payment_reference`.

### Step 9 — CONFIRMATION is read-only

Same guard as Step 5: throw `InvalidArgumentException` with "read-only" message.

- [ ] **Step 3a: Write validation methods** for Steps 6, 7, 8
- [ ] **Step 3b: Add `match` arms** in the main `__invoke` method
- [ ] **Step 3c: Update `BookingWizardStep::LAST_IMPLEMENTED`** to `CONFIRMATION` (9)
- [ ] **Step 3d: Write tests** `tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php`
- [ ] **Step 3e: Run tests** `vendor/bin/phpunit tests/Feature/Domain/Booking/Actions/SaveBookingDraftStepSteps678Test.php`
- [ ] **Step 3f: Commit**

---

## Step 4: `BookingWizard` Livewire — add Steps 6-9 support

**File:** `app/Livewire/Public/Booking/BookingWizard.php`

Add new public properties:
```php
// Step 6 — customer data
public string $customerFullName = '';
public string $customerMobile = '';
public string $customerEmail = '';
public string $customerAddress = '';
public string $customerRelationship = '';
public string $customerContactChannel = '';
public ?Carbon $privacyNoticeAcceptedAt = null;

// Step 7 — deceased data
public string $deceasedFullName = '';
public ?Carbon $deceasedDateOfBirth = null;
public ?Carbon $deceasedDateOfDeath = null;
public string $deceasedRelationship = '';
public string $deceasedGender = '';

// Step 8 — payment
public string $paymentMethod = '';
public string $paymentReference = '';
```

Add new methods:
- `saveStep6(array $payload): void` — calls `SaveBookingDraftStep` with `CUSTOMER_DATA`
- `saveStep7(array $payload): void` — calls `SaveBookingDraftStep` with `DECEASED_DATA`
- `saveStep8(string $paymentMethod, ?string $paymentReference = null): void` — calls `SaveBookingDraftStep` with `PAYMENT`
- Update `hydrateFrom(BookingDraft $draft)` to rehydrate all new properties
- Update `idempotencyKeyFor()` to handle new steps

- [ ] **Step 4a: Add properties and methods**
- [ ] **Step 4b: Update hydrateFrom**
- [ ] **Step 4c: Run existing wizard tests** `vendor/bin/phpunit tests/Feature/Livewire/Public/Booking/`
- [ ] **Step 4d: Commit**

---

## Step 5: Blade view — add Steps 6-9 sections

**File:** `resources/views/livewire/public/booking/wizard.blade.php`

Add after the Step 5 `SUMMARY` section (before the closing `@endif` of step sections):

```blade
@elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA)
    {{-- Step 6: Data Pemesan --}}
    <section>...</section>

@elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::DECEASED_DATA)
    {{-- Step 7: Data Almarhum + Documents --}}
    <section>...</section>

@elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::PAYMENT)
    {{-- Step 8: Pembayaran --}}
    <section>...</section>

@elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CONFIRMATION)
    {{-- Step 9: Konfirmasi --}}
    <section>...</section>
```

Each section follows the same state-handling patterns as Steps 1-5:
- loading state via `$autosaveState`
- empty state for missing data
- error state via `@error()`
- pending/success states

Step 8 (Payment) checks `FeatureFlag::isActive('payment_gate')` to render either online checkout button or manual instruction card.

Step 9 (Confirmation) is READ-ONLY — shows order reference, status, and next steps.

- [ ] **Step 5a: Add Step 6 Blade section**
- [ ] **Step 5b: Add Step 7 Blade section** (with file upload form)
- [ ] **Step 5c: Add Step 8 Blade section** (online + manual fallback)
- [ ] **Step 5d: Add Step 9 Blade section**
- [ ] **Step 5e: Run browser/accessibility tests**
- [ ] **Step 5f: Commit**

---

## Step 6: SDD Ledger

**File:** `.superpowers/sdd/2026-08-12-platform-booking-completion/progress.md`

Create with lane metadata:
- worktree: `.worktrees/platform-booking-completion`
- branch: `lane/l6-booking-completion`
- plan commit: `<hash of this plan commit>`
- task breakdown: per Step above

Update as each step completes.

---

## Step 7: Verification

- [ ] `vendor/bin/phpunit tests/Feature/Domain/Booking/ tests/Feature/Livewire/Public/Booking/`
- [ ] `vendor/bin/pint --test app/Domain/Booking/ app/Livewire/Public/Booking/ resources/views/livewire/public/booking/`
- [ ] `vendor/bin/phpstan analyse app/Domain/Booking/ app/Livewire/Public/Booking/ --memory-limit=512M`
- [ ] `bash ci/verify-docs.sh`
- [ ] `git diff --check`

---

## Task breakdown

| Task | Description | Status |
|------|-------------|--------|
| 1 | Migration: add Steps 6-8 fields | Pending |
| 2 | BookingDraft model: add fillable/casts | Pending |
| 3 | SaveBookingDraftStep: add Steps 6-8 match arms + validation | Pending |
| 4 | BookingWizard Livewire: add properties + methods for Steps 6-9 | Pending |
| 5 | wizard.blade.php: add Steps 6-9 sections | Pending |
| 6 | SDD ledger | Pending |
| 7 | Verification | Pending |

---

## Step 8: Navigation and sequencing corrections (added 12 Aug 2026)

Two defects found by the correctness review lens, both fixed in this lane:

- **Steps 6 and 7 were individually skippable.** `validateStepSequencing()` required only steps 1-4 for every step from 6 onward, so a caller could save step 8 directly and land on Confirmation with an em dash for every customer and deceased field — `public-booking-wizard` AC13's "unskippable" rule broken for precisely the steps carrying PII. Now each writable step requires every writable step below it (step 5 excluded, being read-only and never recorded in `completed_steps`).
- **Steps 5 and 9 became permanently unreachable once left.** `goToStep()` admitted only steps present in `completed_steps`, and the two read-only steps are never added to it. Leaving step 9 for step 6 lost the confirmation screen for good and made step 6's own "Kembali" button dead. `BookingWizard::canReachStep()` now treats a read-only step as reachable once the writable step before it is complete — the summary after step 4, the confirmation after step 8.

Also removed: the `$step > LAST_IMPLEMENTED` "not implemented yet" branch in `SaveBookingDraftStep`. With `LAST_IMPLEMENTED` now 9 and `assertKnown()` already rejecting anything outside 1-9, it was unreachable — a guard that cannot fire reads as protection that does not exist.

---

## Out of scope

- **GuardPaymentSession / GuardCondition** — L7 owns
- **QuoteRenewal / GuardRenewalPaymentOpening** — L8 owns
- **L11 marketplace files** — separate lane
- **Document upload to storage** — file upload handling uses quarantine path references only; actual file upload to storage is separate infrastructure
- **Payment webhook handling** — `POST /api/payments/webhook/{merchant}` is handled by L7
- **FuneralCase creation** — L7 order-orchestration creates the case from the submitted draft
- **Pre-Need interest registration** — handled by separate flow when `PRE_NEED` service type selected
