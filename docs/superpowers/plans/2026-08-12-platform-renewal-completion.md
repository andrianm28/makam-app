# Renewal Journey Completion — Implementation Plan (Lane L8, Wave 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use `superpowers:subagent-driven-development`
> to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Complete the public renewal journey — steps 4 (tariff quote), 5
(payment), 6 (confirmation/invoice) — and enforce one renewal settlement per
grave period.

**Architecture:** Three renewal-owned tables (`renewals`, `renewal_quotes`,
`renewal_external_markings`) behind Actions in `app/Domain/Renewal/`, with a
database-level unique business key `(grave_record_id, target_due_period)` as the
AC11 duplicate guard. Online renewals and admin external markings both write a
`renewals` row, so **one** unique index enforces the shared uniqueness domain
`design.md` requires. Three Livewire screens continue the existing stateless
GET-per-step pattern. The payment step consumes the merged payment platform
read-only and never modifies it.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, PostgreSQL 18, PHPUnit 12.

**Spec:** [`.kiro/specs/renewal-and-grave-registry/`](../../../.kiro/specs/renewal-and-grave-registry/)
(`requirements.md`, `design.md`, `tasks.md`)
**Acceptance criteria in scope:** AC6, AC7, AC8, AC9, AC10, AC11
**Branch:** `lane/l8-renewal-completion`, forked from trunk tip `0b9ce5e`
**Worktree:** `.worktrees/platform-renewal-completion`
**Review tier:** FULL — this lane touches payment and a financial/business
invariant, so it gets independent task-scoped review, a bounded fix loop, and
one whole-branch review before the PR. No part of it is downgraded to the
light tier, including the presentation-only confirmation copy.

---

## Global Constraints

Binding, from [`AGENTS.md`](../../../AGENTS.md) unless otherwise cited.

1. **Never create payment before an accepted quote** (§Domain and financial
   invariants). Step 5 may not open a payment session until step 4's quote has
   been accepted and persisted.
2. **Never mark paid from a browser return URL** (§Domain and financial
   invariants). Step 6 renders the server's recorded state; the return URL is a
   navigation event, never a state transition.
3. **One renewal settlement per period** (§Domain and financial invariants) —
   this is AC11, and it is the invariant this lane exists to protect.
4. **No invented fine** — `G-RATE-01` is a Structural gate whose documented
   closed-behavior is exactly that ([`assumptions-and-gates.md:26`](../../governance/assumptions-and-gates.md)).
   This is AC7 and it is a gate read, not a new policy.
5. **Closed online-payment gate uses the manual fallback, and the step is never
   removed** (§Domain and financial invariants; design-system.md §6.9). This is
   AC8 via `G-PAY-01`.
6. **Policies and query-level scope are mandatory**, scoped by grave among
   others (§Authorization and files).
7. **Human review is mandatory** before security, authorization, financial, or
   privacy changes (§Infrastructure-agent execution). This lane is all four, so
   it opens a PR and does not self-merge.
8. **Never report `PASS` for a check that was not executed** — use `BLOCKED` or
   `NOT TESTED` (§Infrastructure-agent execution).
9. **No hardcoded design values**, no Tailwind arbitrary values
   ([`design-system.md`](../../design/design-system.md) §9.2;
   [`tokens.css`](../../../resources/css/tokens.css) is the single source of truth).
10. **PostgreSQL 18 is the real target.** SQLite has repeatedly hidden real
    defects in this codebase (constraint behavior, race conditions). Every
    schema and duplicate-guard claim in this lane must be verified against a
    disposable PostgreSQL 18, not against the hermetic SQLite suite.
11. **No `composer install` / `npm run build` on this host** — CI owns builds
    ([`CLAUDE.md`](../../../CLAUDE.md) §Scope note).

---

## Current state — read before planning any change

Everything in this section was verified against the tree at `0b9ce5e`, not
assumed from documentation.

### What already exists and works

- **Journey steps 1–3 are shipped.** `RenewalStart` (`/perpanjangan`, steps 1–2:
  city, cemetery) and `GraveSearch` (`/perpanjangan/cari`, step 3) —
  [`routes/web.php:173-174`](../../../routes/web.php). AC1–AC5, AC14 and AC16
  are done and CI-green. **Do not modify these components** except to extend the
  stepper's reach into steps 4–6.
- **The step vocabulary is already complete.**
  [`app/Domain/Renewal/RenewalJourneyStep.php`](../../../app/Domain/Renewal/RenewalJourneyStep.php)
  declares all six steps with Indonesian labels (`Biaya`, `Pembayaran`,
  `Konfirmasi` for 4/5/6). Only `LAST_IMPLEMENTED` (line 84) needs to move
  forward as each step lands.
- **The grave registry is shipped.** `App\Domain\GraveRegistry\Models\GraveRecord`
  plus `GraveRecordAccessMode` (three AC14 modes, defaulting to the most
  restrictive) and a `pg_trgm` GIN index. `grave_records` is the table this
  lane's business key points at.
- **The step-to-step handoff pattern is established and stateless.** Step 2 hands
  off with a plain link carrying a query parameter
  (`/perpanjangan/cari?tpu={id}`, `start.blade.php:211`), which the next
  component reads through a Livewire `#[Url]` property. Every step is a
  bookmarkable GET. This lane follows the same pattern rather than introducing
  session-carried journey state.
- **Server-side gate modes are inherited, not rebuilt.**
  [`ModeResolver`](../../../app/Platform/FeatureGate/ModeResolver.php) already
  exposes `paymentMode()` (`G-PAY-01`) alongside the `graveSearchMode()` the
  existing renewal screens call. `PaymentMode::MANUAL_COORDINATION` is AC8's
  fallback and it already exists.
- **All four gates this lane needs are already registered** with documented
  closed-behaviors ([`assumptions-and-gates.md:18-27`](../../governance/assumptions-and-gates.md)):

  | Gate | Concern | Closed behavior | This lane's AC |
  |---|---|---|---|
  | `G-PAY-01` | Online payment | Manual coordination | AC8 |
  | `G-RATE-01` | Renewal tariff/fine | **No invented fine** | AC6, AC7 |
  | `G-EXT-01` | Outside-system renewal marking | Manual marking | AC10 |
  | `G-DATA-01` | Grave search/reminder | Disabled with explanation | (already consumed by step 3) |

  AC7 and AC10 therefore resolve to *gate reads against an existing registry*,
  not to new policy this lane invents.
- **`TARIFF_SOURCE_CHANGE` is already on the sensitive-action closed list**
  ([`app/Platform/Audit/SensitiveActions.php:33`](../../../app/Platform/Audit/SensitiveActions.php)),
  and `Audit::record()`'s Unicode-blank/malformed-UTF-8 reason check was
  hardened at `0b9ce5e`. This lane calls that API; it does not rebuild it.
- **The payment adapter platform is merged and reviewed** —
  `app/Platform/Payment/` (`PaymentSession`, `PaymentIntent`,
  `PaymentVerification`, `PaymentReversal`, `ProviderEvent`, and the
  return/cancel/verify/reversal controllers), plan doc
  [`2026-08-09-platform-payment-adapter.md`](2026-08-09-platform-payment-adapter.md).
  Step 5 consumes it. See the seam survey at
  [`../research/l8-seam-survey.md`](../research/l8-seam-survey.md).
- **The identity/authorization seam is merged** — `app/Platform/IdentityAccess/`
  now has a real `ActorRole` closed list and `actor_role_assignments`. AC10's
  privileged marking action authorizes against this seam.

### What is missing — this lane's whole job

Verified: `app/Domain/Renewal/Actions/` and `app/Domain/Renewal/Models/` are
empty, and no migration creates `renewals`, `renewal_quotes`, or
`renewal_external_markings`.

- No screen for PUB-032 (fee), PUB-033 (payment), PUB-034 (confirmation) —
  [`screen-inventory.md:41-43`](../../product/screen-inventory.md) records all
  three as "no screen exists".
- No renewal record, so **AC11 has nothing to duplicate and is currently NOT
  TESTED** rather than passing.
- No tariff/quote shape. `GraveRecordSource.php:18-19` explicitly defers the
  AC6 tariff source to `app/Domain/Renewal`'s quote shape — i.e. to this lane.

### What this lane deliberately does NOT do

- **AC4** (< 500 ms at 100k records) stays **NOT TESTED**. It is a
  standing backlog item on the search half of this spec, unrelated to steps 4–6.
- **AC13** (async 10k-row import) and **AC15** (reminder scheduler) are out of
  scope — different lanes, different ACs.
- **Browser/accessibility certification** stays **NOT TESTED**: no Dusk,
  Playwright, or Cypress harness exists in this repository. Any CLS or
  screen-reader claim would be unexecuted, and `AGENTS.md` forbids reporting
  `PASS` for those.

### Contract already written for this surface

[`docs/contracts/openapi.yaml`](../../contracts/openapi.yaml) already specifies
the semantics this lane must honor, and they are binding even though the MVP
renders through Livewire rather than a JSON API (the booking wizard shipped the
same way — a contract entry exists for `/orders/{orderId}/confirmation` with no
REST route behind it):

- `POST /renewals` (line 248) → `201 Renewal created`, **`409 Duplicate period
  or outside-system renewal already marked`**. This is independent confirmation
  that external marking and online renewal share one uniqueness domain, exactly
  as `design.md` §Duplicate prevention states.
- `POST /renewals/{renewalId}/payment-session` (line 417) → "Open online payment
  **or return manual coordination instructions**", `409` on "Tariff, duplicate
  period, or gate issue".
- `renewalId` is typed `format: uuid` (line 428), so the `renewals` primary key
  is a UUID, not an autoincrement integer.

---

## Two open rulings — read before starting Task 5 or Task 7

Tasks 1–4 and 6 are unaffected and may proceed immediately. Two decisions are
with the coordinator; the seam survey
([`../research/l8-seam-survey.md`](../research/l8-seam-survey.md)) reached both
conclusions independently of this plan's author.

**Ruling A — the payment platform cannot open a session, for anyone.**
`PaymentSession` refuses to insert (`PaymentSession.php:84-87` throws
`PaymentSessionCreationUnavailableException::becauseGuardIsDenyOnly()`),
`GuardResult` has only a `denied()` factory and an `isAllowed()` hardwired to
`false` (`GuardResult.php:49,63,79`), and there is no `CreatePaymentSession`.
This is approved Wave 1b ruling 1b-L3-01, enforced in three layers with tests
asserting the absence, and its own instruction to a lane that hits the wall is
to escalate rather than stub. Task 5 is therefore written for the **manual
coordination** path only, and AC8's online half is recorded **BLOCKED
(upstream deny-only)** — never `PASS`. Additionally `payment_intents` has no
subject column and `GuardPaymentSession::__invoke()` accepts only a `Money`, so
a renewal has nowhere to name itself there; Task 5's guard writes its decision
to a renewal-owned record instead of `payment_intents`.

**Ruling B — who may mark an external renewal.** `docs/security/rbac-matrix.md`
has **no row** for this capability, and its nearest row
(`Quote/open payment`) gives Operator `No`, contradicting AC10's "admin/operator"
wording. Task 7 is written for **`admin` only, with `operator` explicitly denied
and that denial tested**. If the ruling widens it, only the constant in
`RenewalMarkingPolicy` and one test change; the rest of Task 7 stands. Task 7
also adds the missing matrix row.

Per `AGENTS.md` §Infrastructure-agent execution, **both are human-sign-off
territory**. Do not start Task 5 or Task 7 without the ruling.

---

## File Structure

**Created — all new, no shared file is modified except the two noted:**

| File | Responsibility |
|---|---|
| `database/migrations/2026_08_12_100000_create_renewals_table.php` | `renewals` + the AC11 unique business key |
| `database/migrations/2026_08_12_100010_create_renewal_quotes_table.php` | `renewal_quotes` — tariff amount, source, effective time |
| `database/migrations/2026_08_12_100020_create_renewal_external_markings_table.php` | `renewal_external_markings` — AC10 evidence |
| `app/Domain/Renewal/RenewalStatus.php` | Closed list: `MENUNGGU_PEMBAYARAN`, `DIBAYAR`, `KEDALUWARSA` |
| `app/Domain/Renewal/RenewalSource.php` | Closed list: `online`, `external` |
| `app/Domain/Renewal/Models/Renewal.php` | Model, UUID PK |
| `app/Domain/Renewal/Models/RenewalQuote.php` | Model, UUID PK |
| `app/Domain/Renewal/Models/RenewalExternalMarking.php` | Model, UUID PK |
| `app/Domain/Renewal/Exceptions/DuplicateRenewalPeriodException.php` | AC11's typed failure |
| `app/Domain/Renewal/Actions/QuoteRenewal.php` | AC6/AC7 — builds a quote, never invents a fine |
| `app/Domain/Renewal/Actions/OpenRenewal.php` | Creates the `renewals` row; the only online write path |
| `app/Domain/Renewal/Actions/GuardRenewalPaymentOpening.php` | Renewal-shaped payment-opening guard (Task 5) |
| `app/Domain/Renewal/Actions/MarkExternalRenewal.php` | AC10 privileged write (Task 7) |
| `app/Domain/Renewal/RenewalMarkingPolicy.php` | AC10 role+scope authorization (Task 7) |
| `app/Livewire/Public/Renewal/RenewalFee.php` + `resources/views/livewire/public/renewal/fee.blade.php` | PUB-032, step 4 |
| `app/Livewire/Public/Renewal/RenewalPayment.php` + `.../payment.blade.php` | PUB-033, step 5 |
| `app/Livewire/Public/Renewal/RenewalConfirmation.php` + `.../confirmation.blade.php` | PUB-034, step 6 |

**Modified — shared files, deliberately minimal:**

| File | Change | Why it is unavoidable |
|---|---|---|
| `routes/web.php` | 3 route registrations | No other way to expose a screen |
| `app/Platform/Audit/SensitiveActions.php` | Append `RENEWAL_EXTERNAL_MARKING` | Closed list by design; append-only, so any L7/L9/L11 conflict is mechanical |
| `app/Domain/Renewal/RenewalJourneyStep.php` | Move `LAST_IMPLEMENTED` forward | Its documented purpose |
| `docs/security/rbac-matrix.md`, `docs/product/screen-inventory.md`, `.kiro/specs/renewal-and-grave-registry/tasks.md` | Doc updates | `AGENTS.md` §Documentation requires them |

**Explicitly NOT touched:** everything under `app/Platform/Payment/` — read-only
for this lane, per the coordinator's ruling and to avoid the L7 collision.

---

## Task 1: Schema and the AC11 duplicate guard

The highest-stakes task in the lane. The guard is a database constraint, not
application logic, because two concurrent requests must not both succeed.

**Files:**
- Create: the three migrations, `RenewalStatus.php`, `RenewalSource.php`, the three models, `DuplicateRenewalPeriodException.php`
- Test: `tests/Feature/Domain/Renewal/RenewalSchemaTest.php`

**Interfaces produced:**
- `Renewal` — `$fillable`: `grave_record_id`, `target_due_period`, `reference`, `status`, `source`, `settled_at`. UUID PK via `HasUuids`.
- `RenewalQuote` — `$fillable`: `renewal_id`, `amount_minor`, `currency`, `tariff_source`, `tariff_effective_at`, `tariff_source_updated_at`, `late_fine_minor`, `late_fine_basis`, `accepted_at`, `expires_at`. Also declares `amountAsMoney(): Money` (wraps `amount_minor`, used by Task 5's guard) and `isAcceptedAndUnexpired(): bool`.
- `RenewalExternalMarking` — `$fillable`: `renewal_id`, `marked_by_actor_ref`, `evidence_reference`, `reason`, `marked_at`.
- `DuplicateRenewalPeriodException::forGravePeriod(string $graveRecordId, string $period): self`

**Design decisions, locked:**

`target_due_period` is a `date` column holding **the due date this renewal
settles** — i.e. the grave record's `due_date` at the moment the renewal is
opened. A date is comparable, indexable, and unambiguous; a free-text "period"
would let `"2027"` and `"2027-01"` denote the same period and defeat the
constraint.

Both an online renewal and an admin external marking insert a `renewals` row;
`source` distinguishes them. That is what makes one unique index satisfy
`design.md`'s "External marking and online renewal share the same uniqueness
domain" — there is no second table to keep in sync.

- [ ] **Step 1: Write the failing test for the unique business key**

```php
public function test_a_second_renewal_for_the_same_grave_and_period_is_rejected_by_the_database(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

    Renewal::create([
        'grave_record_id' => $grave->id,
        'target_due_period' => '2027-03-01',
        'reference' => 'PPJ-0001',
        'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
        'source' => RenewalSource::ONLINE,
    ]);

    $this->expectException(QueryException::class);

    Renewal::create([
        'grave_record_id' => $grave->id,
        'target_due_period' => '2027-03-01',
        'reference' => 'PPJ-0002',
        'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
        'source' => RenewalSource::ONLINE,
    ]);
}
```

- [ ] **Step 2: Write the failing test proving external and online share the domain**

This is the assertion that would catch a two-table design regressing the
invariant. It must fail for the right reason before the constraint exists.

```php
public function test_an_external_marking_and_an_online_renewal_cannot_both_claim_one_period(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);

    Renewal::create([
        'grave_record_id' => $grave->id,
        'target_due_period' => '2027-03-01',
        'reference' => 'PPJ-0003',
        'status' => RenewalStatus::DIBAYAR,
        'source' => RenewalSource::EXTERNAL,
    ]);

    $this->expectException(QueryException::class);

    Renewal::create([
        'grave_record_id' => $grave->id,
        'target_due_period' => '2027-03-01',
        'reference' => 'PPJ-0004',
        'status' => RenewalStatus::MENUNGGU_PEMBAYARAN,
        'source' => RenewalSource::ONLINE,
    ]);
}
```

- [ ] **Step 3: Run both, verify they fail because the table does not exist**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter RenewalSchemaTest`
Expected: FAIL — no such table `renewals`. Not "assertion failed".

- [ ] **Step 4: Write the migrations**

`renewals` carries the constraint. Follow the house style in
`2026_08_08_100000_create_grave_records_table.php` — UUID PK, `restrictOnDelete`
so deleting a cemetery or grave cannot silently orphan a settlement record.

```php
Schema::create('renewals', function (Blueprint $table): void {
    $table->uuid('id')->primary();
    $table->foreignUuid('grave_record_id')->constrained('grave_records')->restrictOnDelete();
    $table->date('target_due_period');
    $table->string('reference')->unique();
    $table->string('status');
    $table->string('source');
    $table->timestamp('settled_at')->nullable();
    $table->timestamps();

    // AC11. The business key, enforced by the database so two concurrent
    // requests cannot both succeed. Application-level checking would be a
    // race, not a guard.
    $table->unique(['grave_record_id', 'target_due_period'], 'renewals_grave_period_unique');
});
```

- [ ] **Step 5: Run the tests, verify they now pass**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter RenewalSchemaTest`
Expected: PASS.

- [ ] **Step 6: Mutation-check the guard before trusting it**

A passing test proves nothing until it has been seen to fail for the right
reason. Temporarily drop `'renewals_grave_period_unique'` from the migration,
re-run, and confirm **both** tests fail. Restore it. Record the observed failure
output in the task report — a reviewer must be able to see the guard was
exercised, not assumed.

- [ ] **Step 7: Verify on real PostgreSQL 18**

SQLite and PostgreSQL differ on constraint behavior, and this lane's whole point
is a constraint. Run the same tests against a disposable PostgreSQL 18 and
confirm the violation surfaces as `SQLSTATE 23505`. If it does not, stop and
report — do not proceed on the SQLite result.

- [ ] **Step 8: Commit**

```bash
git add database/migrations app/Domain/Renewal tests/Feature/Domain/Renewal
git commit -m "feat(renewal): add renewals schema with the AC11 duplicate-period guard"
```

---

## Task 2: `QuoteRenewal` — tariff with source and effective time (AC6, AC7)

**Files:**
- Create: `app/Domain/Renewal/Actions/QuoteRenewal.php`
- Test: `tests/Feature/Domain/Renewal/QuoteRenewalTest.php`

**Interfaces:**
- Consumes: `RenewalQuote` (Task 1), `Money` (`App\Platform\FinancialLedger\Money`, integer minor units, `toMinorInt()`), `ModeResolver`.
- Produces: `QuoteRenewal::__invoke(GraveRecord $grave): RenewalQuote`

**The AC7 rule is a refusal, not a calculation.** `G-RATE-01`'s documented
closed behavior is literally "No invented fine"
([`assumptions-and-gates.md:26`](../../governance/assumptions-and-gates.md)).
When no written operator basis exists, `late_fine_minor` stays `null` and the UI
shows nothing — never a zero, which reads as "we checked and it is nothing",
and never a computed figure.

- [ ] **Step 1: Write the failing test that no fine is invented**

```php
public function test_no_late_fine_is_produced_without_a_written_basis(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => now()->subYears(3)]);

    $quote = app(QuoteRenewal::class)($grave);

    $this->assertNull($quote->late_fine_minor);
    $this->assertNull($quote->late_fine_basis);
}
```

Three years overdue is the case where a naive implementation would compute
something. That is exactly why the fixture is overdue.

- [ ] **Step 2: Write the failing test that source and effective time are mandatory**

```php
public function test_a_quote_always_carries_its_tariff_source_and_update_time(): void
{
    $quote = app(QuoteRenewal::class)(GraveRecord::factory()->create());

    $this->assertNotNull($quote->tariff_source);
    $this->assertNotSame('', $quote->tariff_source);
    $this->assertNotNull($quote->tariff_source_updated_at);
}
```

- [ ] **Step 3: Run, verify failure**

Run: `php -d memory_limit=512M vendor/bin/phpunit --filter QuoteRenewalTest`
Expected: FAIL — class `QuoteRenewal` not found.

- [ ] **Step 4: Implement `QuoteRenewal`**

Amounts are `Money` in integer minor units — no float may enter the money path
(Wave 0 ruling 0c). A quote with no attributable tariff source is not a quote:
throw rather than emit an unattributed figure.

- [ ] **Step 5: Run, verify pass**

- [ ] **Step 6: Commit**

```bash
git commit -m "feat(renewal): quote a renewal with attributed tariff source, never an invented fine"
```

---

## Task 3: `OpenRenewal` — the only online write path (AC11 at the seam)

**Files:**
- Create: `app/Domain/Renewal/Actions/OpenRenewal.php`
- Test: `tests/Feature/Domain/Renewal/OpenRenewalTest.php`

**Interfaces:**
- Consumes: Task 1 models, Task 2's `QuoteRenewal`.
- Produces: `OpenRenewal::__invoke(GraveRecord $grave): Renewal`, throwing `DuplicateRenewalPeriodException`.

- [ ] **Step 1: Write the failing test that the duplicate surfaces as a domain exception**

The database constraint is the guard; this Action translates it into something
the UI can render as design-system.md §6.6's informative state rather than a 500.

```php
public function test_opening_a_second_renewal_for_one_period_raises_a_domain_exception(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
    app(OpenRenewal::class)($grave);

    $this->expectException(DuplicateRenewalPeriodException::class);

    app(OpenRenewal::class)($grave);
}
```

- [ ] **Step 2: Write the failing test that no second row and no second quote are left behind**

```php
public function test_a_rejected_duplicate_leaves_exactly_one_renewal_and_one_quote(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => '2027-03-01']);
    app(OpenRenewal::class)($grave);

    try {
        app(OpenRenewal::class)($grave);
    } catch (DuplicateRenewalPeriodException) {
        // expected
    }

    $this->assertSame(1, Renewal::query()->count());
    $this->assertSame(1, RenewalQuote::query()->count());
}
```

The quote assertion is the one that matters: it fails if the Action writes the
quote outside the transaction that the constraint aborts.

- [ ] **Step 3: Run, verify failure**

- [ ] **Step 4: Implement `OpenRenewal`** — wrap the renewal and its quote in one
`DB::transaction()`, catch the unique violation, rethrow as
`DuplicateRenewalPeriodException`.

- [ ] **Step 5: Run, verify pass**

- [ ] **Step 6: Verify on real PostgreSQL 18** — the rollback behavior on
constraint violation is the thing SQLite is least trustworthy about.

- [ ] **Step 7: Commit**

```bash
git commit -m "feat(renewal): open a renewal atomically with its quote, rejecting duplicate periods"
```

---

## Task 4: Step 4 screen — PUB-032 fee (AC6, AC7)

**Files:**
- Create: `app/Livewire/Public/Renewal/RenewalFee.php`, `resources/views/livewire/public/renewal/fee.blade.php`
- Modify: `routes/web.php`, `RenewalJourneyStep.php` (`LAST_IMPLEMENTED` → `FEE`)
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalFeeTest.php`

Follow `GraveSearch` exactly: `#[Url]`-bound properties, server-resolved gates
every render, six-step `<x-mk.stepper>`, support link present in every state.
Route: `/perpanjangan/biaya`, name `perpanjangan.biaya`.

- [ ] **Step 1: Write the failing test that source and last-updated are always visible**

```php
public function test_the_fee_screen_always_shows_the_tariff_source_and_last_update(): void
{
    $grave = GraveRecord::factory()->create();

    Livewire::test(RenewalFee::class, ['makam' => $grave->id])
        ->assertSee('Sumber tarif')
        ->assertSee('Terakhir diperbarui');
}
```

- [ ] **Step 2: Write the failing test that no fine appears without a basis**

```php
public function test_no_late_fine_figure_is_rendered_when_there_is_no_written_basis(): void
{
    $grave = GraveRecord::factory()->create(['due_date' => now()->subYears(3)]);

    Livewire::test(RenewalFee::class, ['makam' => $grave->id])
        ->assertDontSee('Denda');
}
```

- [ ] **Step 3: Run, verify failure**
- [ ] **Step 4: Implement the component and view** — tokens only, no hardcoded
hex/px/ms, no Tailwind arbitrary values. Amount in `--font-mono`
`--font-weight-bold`; source and last-updated in `--text-sm` `--mk-text-muted`
per the spec's own primitives table.
- [ ] **Step 5: Run, verify pass**
- [ ] **Step 6: Run `ci/verify-docs.sh`** — it scans `resources/` and `app/` for
hardcoded design values. Expected: exit 0.
- [ ] **Step 7: Commit**

---

## Task 5: Step 5 screen and the renewal payment guard (AC8) — BLOCKED ON RULING A

Do not start without the coordinator's confirmation. Written for the manual
coordination path; the online path is denied honestly, never stubbed.

**Files:**
- Create: `app/Domain/Renewal/Actions/GuardRenewalPaymentOpening.php`, `app/Livewire/Public/Renewal/RenewalPayment.php`, `.../payment.blade.php`
- Modify: `routes/web.php`, `RenewalJourneyStep.php` (`LAST_IMPLEMENTED` → `PAYMENT`)
- Test: `tests/Feature/Domain/Renewal/GuardRenewalPaymentOpeningTest.php`, `tests/Feature/Livewire/Public/Renewal/RenewalPaymentTest.php`

The guard mirrors `GuardPaymentSession`'s shape — deny-by-default, evaluate every
condition rather than short-circuiting, one audited decision record — but lives
in `app/Domain/Renewal/` and writes to a renewal-owned record, because
`payment_intents` has no subject column.

Renewal-shaped conditions, replacing the order-specific ones:

| # | Condition | Real today? |
|---|---|---|
| 1 | `G-PAY-01` open (`ModeResolver::paymentMode()`) | yes |
| 2 | grave record exists and is published | yes |
| 3 | quote accepted and unexpired | yes — Task 2 |
| 4 | authorized opening | yes — L5 seam |
| 5 | amount equals quote total, integer minor units | yes — Task 2 |

- [ ] **Step 1: Write the failing test that an expired quote denies**

```php
public function test_an_expired_quote_denies_payment_opening(): void
{
    $quote = RenewalQuote::factory()->create(['expires_at' => now()->subDay(), 'accepted_at' => now()->subDays(2)]);

    $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, $quote->amountAsMoney());

    $this->assertFalse($result->isAllowed());
}
```

- [ ] **Step 2: Write the failing test that an amount mismatch denies**

```php
public function test_an_amount_that_does_not_equal_the_quote_total_denies(): void
{
    $quote = RenewalQuote::factory()->accepted()->create(['amount_minor' => 150_000_00]);

    $result = app(GuardRenewalPaymentOpening::class)($quote->renewal, new Money(149_999_00));

    $this->assertFalse($result->isAllowed());
}
```

- [ ] **Step 3: Write the failing test that the step is never removed when the gate is closed**

design-system.md §6.9: "Step 8 is never removed." The renewal analogue is step 5.

```php
public function test_a_closed_payment_gate_shows_the_manual_fallback_without_removing_the_step(): void
{
    $this->closeGate('G-PAY-01');

    Livewire::test(RenewalPayment::class, ['perpanjangan' => $renewal->id])
        ->assertSee('Pembayaran')
        ->assertSee('koordinasi manual');
}
```

- [ ] **Step 4: Run all three, verify failure**
- [ ] **Step 5: Implement the guard and screen.** The guard MUST NOT call
`PaymentSession::create()` — it throws by design, and catching it to simulate a
flow is the stub ruling 1b-L3-01 forbids. When every condition holds, the result
is "eligible; online session unavailable" and the screen renders manual
coordination.
- [ ] **Step 6: Run, verify pass**
- [ ] **Step 7: Record AC8's online half as BLOCKED** in the task report, with the
three code citations. Never `PASS`.
- [ ] **Step 8: Commit**

---

## Task 6: Step 6 screen — PUB-034 confirmation (AC9)

**Files:**
- Create: `app/Livewire/Public/Renewal/RenewalConfirmation.php`, `.../confirmation.blade.php`
- Modify: `routes/web.php`, `RenewalJourneyStep.php` (`LAST_IMPLEMENTED` → `CONFIRMATION`)
- Test: `tests/Feature/Livewire/Public/Renewal/RenewalConfirmationTest.php`

AC9: reference, status, invoice state, and resulting due date when available.
Success is **quiet** (§6.8) — no confetti, no exclamation marks.

- [ ] **Step 1: Write the failing test for all four AC9 elements**

```php
public function test_the_confirmation_shows_reference_status_invoice_state_and_resulting_due_date(): void
{
    $renewal = Renewal::factory()->create(['reference' => 'PPJ-0007', 'status' => RenewalStatus::MENUNGGU_PEMBAYARAN]);

    Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
        ->assertSee('PPJ-0007')
        ->assertSee('Menunggu pembayaran')
        ->assertSee('Tanggal jatuh tempo');
}
```

- [ ] **Step 2: Write the failing test that a refresh does not produce a second renewal**

§6.6: "Re-submitting a paid order shows the same confirmation, not a second order."

```php
public function test_reloading_the_confirmation_never_creates_a_second_renewal(): void
{
    $renewal = Renewal::factory()->create();

    Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id]);
    Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id]);

    $this->assertSame(1, Renewal::query()->count());
}
```

- [ ] **Step 3: Write the failing test that a due date is shown only when real**

AC9 says "when available". An unpaid renewal has not moved the due date, and
showing a projected one would be a fabricated figure.

```php
public function test_an_unsettled_renewal_does_not_claim_a_new_due_date(): void
{
    $renewal = Renewal::factory()->create(['status' => RenewalStatus::MENUNGGU_PEMBAYARAN, 'settled_at' => null]);

    Livewire::test(RenewalConfirmation::class, ['perpanjangan' => $renewal->id])
        ->assertDontSee('Jatuh tempo baru');
}
```

- [ ] **Step 4: Run, verify failure**
- [ ] **Step 5: Implement.** Reference in `--font-mono`, copyable.
- [ ] **Step 6: Run, verify pass**
- [ ] **Step 7: Commit**

---

## Task 7: External marking with evidence (AC10) — BLOCKED ON RULING B

Do not start without the coordinator's ruling on which role may mark. Written
for `admin` only, `operator` denied.

**Files:**
- Create: `app/Domain/Renewal/Actions/MarkExternalRenewal.php`, `app/Domain/Renewal/RenewalMarkingPolicy.php`
- Modify: `app/Platform/Audit/SensitiveActions.php` (append `RENEWAL_EXTERNAL_MARKING`), `docs/security/rbac-matrix.md` (add the missing row)
- Test: `tests/Feature/Domain/Renewal/MarkExternalRenewalTest.php`

Per the matrix's own closing paragraph, the authorizer requires **a role AND a
scope grant** — a role alone never grants access to a record. Gate `G-EXT-01`
governs the capability.

- [ ] **Step 1: Write the failing test that an unauthorized role is denied**

```php
public function test_an_operator_may_not_mark_an_external_renewal(): void
{
    $this->actingAsRole('operator');

    $this->expectException(AuthorizationException::class);

    app(MarkExternalRenewal::class)($grave, '2027-03-01', evidence: 'BUKTI-001', reason: 'Dibayar langsung di kantor TPU');
}
```

- [ ] **Step 2: Write the failing test that a role without a scope grant is denied**

```php
public function test_an_admin_without_a_scope_grant_for_the_cemetery_is_denied(): void
{
    $this->actingAsRole('admin'); // no scope assignment for this cemetery

    $this->expectException(AuthorizationException::class);

    app(MarkExternalRenewal::class)($graveInAnotherCemetery, '2027-03-01', evidence: 'BUKTI-002', reason: 'Dibayar langsung');
}
```

- [ ] **Step 3: Write the failing test that a blank reason is rejected**

`RENEWAL_EXTERNAL_MARKING` is on the sensitive list, so `Audit::record()` refuses
a blank reason — including Unicode-blank and malformed UTF-8, per the hotfix at
`0b9ce5e`. This test pins that the marking path actually reaches that check.

```php
public function test_a_unicode_blank_reason_is_rejected_and_writes_no_marking(): void
{
    $this->actingAsRole('admin');

    try {
        app(MarkExternalRenewal::class)($grave, '2027-03-01', evidence: 'BUKTI-003', reason: "\u{00A0}");
    } catch (AuditReasonRequiredException) {
        // expected
    }

    $this->assertSame(0, RenewalExternalMarking::query()->count());
    $this->assertSame(0, Renewal::query()->count());
}
```

- [ ] **Step 4: Write the failing test that a marking claims the period against online renewal**

```php
public function test_a_marked_external_renewal_blocks_a_later_online_renewal_for_that_period(): void
{
    $this->actingAsRole('admin');
    app(MarkExternalRenewal::class)($grave, '2027-03-01', evidence: 'BUKTI-004', reason: 'Dibayar di kantor TPU');

    $this->expectException(DuplicateRenewalPeriodException::class);

    app(OpenRenewal::class)($grave);
}
```

- [ ] **Step 5: Run all four, verify failure**
- [ ] **Step 6: Implement.** `Audit::wrap()` so the marking, its `renewals` row,
and the audit event commit or roll back together.
- [ ] **Step 7: Run, verify pass**
- [ ] **Step 8: Add the missing `rbac-matrix.md` row and update `screen-inventory.md` and the spec's `tasks.md`**
- [ ] **Step 9: Commit**

---

## Task 8: Review, fix wave, finish

- [ ] Task-scoped review after each task above, before the next starts.
- [ ] One whole-branch review as a unit; findings triaged Critical/Important/Minor.
- [ ] One bounded fix wave (max 5 rounds; escalate at round 4).
- [ ] `superpowers:finishing-a-development-branch` — push, open a PR against
  `docs/design-system-and-planning`. **Do not merge.** Payment and authorization
  changes require human sign-off per `AGENTS.md`.

---

## Verification

Every claim in the PR body must name the check that produced it.

- Hermetic PHPUnit suite (SQLite). Baseline at `0b9ce5e` is **1812 tests, 0
  failures, 2 errors, 59 skipped**; both errors are pre-existing and host-only
  (`EloquentGateRegistrySourceTest` and `HomePageRouteTest` simulate a query
  failure with `DROP TABLE ... CASCADE`, PostgreSQL syntax SQLite rejects). Any
  additional failure is this lane's.
- **Real PostgreSQL 18** for every schema and duplicate-guard claim, per Global
  Constraint 10.
- `ci/verify-docs.sh` after the change, for the hardcoded-design-value scan.
- CI on push — the authoritative build.

## NOT TESTED (this lane)

Recorded up front so it cannot be quietly claimed later:

- AC4 (< 500 ms at 100k records) — out of scope, unchanged.
- Browser, accessibility, and CLS verification — no harness exists in this repo.
