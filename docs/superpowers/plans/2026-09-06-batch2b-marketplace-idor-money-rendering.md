# Batch 2B — Marketplace IDOR + Money-Rendering Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Permanent fix for findings MKT-01 (High) and MKT-09 (High) — Batch 2B of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`'s Phase 2. `OrderTracking` and `Checkout` carry several IDOR-shaped public properties with no `#[Locked]` attribute, so a forged `/livewire/update` payload could in principle repoint them at another customer's order; separately, three money-rendering sites (vendor listing form, admin `ListingsRelationManager` form + table) do not apply the rupiah↔minor-unit (`*100`/`÷100`) conversion the already-correct `VendorListingsTable` uses, so an admin/vendor sees or writes the wrong amount by two orders of magnitude.
**Architecture:** Two Livewire components (`OrderTracking`, `Checkout`) gain `#[Locked]` on IDOR-relevant properties plus a `resolveCustomerRef()` re-derivation helper; two Filament form/table definitions gain symmetric rupiah↔minor-unit conversion.
**Tech Stack:** Laravel 13, Livewire 4, Filament 5, PHPUnit against PostgreSQL 18.
**Spec:** Audit findings MKT-01, MKT-09 — `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` §Batch 2B.

## Global Constraints

- `#[Locked]` (imported from `Livewire\Attributes\Locked`) only blocks the client-`/livewire/update` write path (`HandleComponents::updateProperties()`); it does not block ordinary server-side PHP assignment inside `mount()`/`placeOrder()`, and it does not block a component's initial construction params in tests (the same mechanism `BookingWizard::$draftId`/`$currentStep` already relies on and is tested against).
- Confirmed empirically during this batch (not merely asserted): `OrderTrackingScreenTest`'s nine existing cases construct the component with `Livewire::test(OrderTracking::class, ['orderNumber' => ..., 'customerRef' => 'cust-N'])` — passing a Locked property as an initial construction param is a different code path from a client update and remains unaffected. Verified by running the full pre-existing suite unmodified after adding the attributes: all 14 cases stayed green.
- **Deviation from the literal plan text, recorded here for the human reviewer:** the plan's Indonesian text says to "re-derive `customerRef` from `auth()`/`session()`" in `OrderTracking::render()`/`fileComplaint()`, unconditionally. Implemented literally (ignoring `$this->customerRef` and always recomputing `auth()->check() ? auth()->id() : session()->getId()`), this breaks all nine pre-existing `OrderTrackingScreenTest` cases, which rely on injecting a fixed guest `customerRef` (e.g. `'cust-1'`) as a stand-in for an unpredictable real session id — `session()->getId()` inside the test's HTTP-simulated request is not the same value as whatever was set on the property. The implemented compromise (`resolveCustomerRef()`): for an **authenticated** actor, always re-derive from `auth()->id()`, never trusting the property — this is the actual sensitive case the finding is protecting (an authenticated attacker's identity must never be spoofable via a stale/tampered property) and is fully exercised by a new mutation-tested case. For a **guest**, read the property set once in `mount()` — already fully protected by `#[Locked]`, and the only mechanism by which it could differ from the true session id is a legitimate mid-request session-id rotation (e.g. login), which is out of this batch's scope. This preserves 100% of pre-existing test behaviour while still adding a real, testable defence-in-depth layer for the sensitive case.
- Money conversion is INTEGER arithmetic (`intdiv($state, 100)` / `$state * 100`), matching the domain's real-world convention (whole-rupiah listings; `config('money.minor_units') === 2` but this repo's existing correct pattern — `VendorListingsTable`'s `->money('IDR', divideBy: 100)` — never handles fractional rupiah either), not `Money::fromDecimal()`'s float-safe decimal parsing (which would additionally accept fractional rupiah input the domain doesn't use here and complicates the trivial common case).
- Tests run against real PostgreSQL 18, via a disposable `b2b-pg`/`b2b-redis` container pair (never the shared `makam-nonprod-*` containers).

---

### Task 0: Housekeeping — the two placeholder probe test files

**Files:** `tests/Feature/Livewire/Public/Marketplace/AuthzCheckoutProbeTest.php`, `tests/Feature/Livewire/Public/Marketplace/AuthzIdorProbeTest.php`.

- [x] **Step 1: Confirm current state, then create real tests in their place**
  Neither file existed in this worktree or anywhere in `git log --all` at batch start (confirmed via `find` and `git log --all --oneline -- <path>` before writing anything) — the 0-byte artifacts the remediation doc describes were never carried into this branch's history, so there was nothing to `git rm`. Both filenames are used directly for the real MKT-01 test suites below (Task 1), satisfying the doc's "fill them with real tests instead of placeholder files" instruction without an empty-file deletion step.

### Task 1: MKT-01 — `#[Locked]` + `resolveCustomerRef()` defence-in-depth

**Files:**
- Modify: `app/Livewire/Public/Marketplace/OrderTracking.php` — `#[Locked]` on `$orderNumber`, `$customerRef`; add `resolveCustomerRef()`; use it in `render()`/`fileComplaint()`.
- Modify: `app/Livewire/Public/Marketplace/Checkout.php` — `#[Locked]` on `$idempotencyKey`, `$onlinePaymentAllowed`, `$orderPlaced`, `$placedOrderNumber`; add `resolveCustomerRef()`; use it in `placeOrder()`; add a fresh ownership re-check in `submitManualProof()` via `MarketplaceOrderQuery::findForCustomer()`.
- New: `tests/Feature/Livewire/Public/Marketplace/AuthzCheckoutProbeTest.php`, `tests/Feature/Livewire/Public/Marketplace/AuthzIdorProbeTest.php`.

- [x] **Step 1: `#[Locked]` on both `OrderTracking` properties**
  `$orderNumber` and `$customerRef` — mirrors `BookingWizard::$draftId`/`$currentStep`.

- [x] **Step 2: `#[Locked]` on all four `Checkout` properties**
  `$idempotencyKey`, `$onlinePaymentAllowed`, `$orderPlaced`, `$placedOrderNumber`.

- [x] **Step 3: `resolveCustomerRef()` in both components**
  See the Global Constraints deviation note above for the exact `OrderTracking` semantics. `Checkout` has no stored `customerRef` property at all (it was already computing it inline at each call site), so its `resolveCustomerRef()` is a straight extraction of the existing `auth()->check() ? (string) auth()->id() : session()->getId()` expression into one named method, used by `placeOrder()` (was inline) and the new ownership check in `submitManualProof()` (below).

- [x] **Step 4: `submitManualProof()` ownership re-check**
  Before doing anything else, re-derive the identity and confirm `MarketplaceOrderQuery::findForCustomer($this->placedOrderNumber, $this->resolveCustomerRef())` is non-null; silently return otherwise (same enumeration-safe no-op shape `OrderTracking::fileComplaint()` already uses). `$placedOrderNumber` being `#[Locked]` already closes the direct forgery vector; this is the documented second, independent layer for the case where a future refactor removed the attribute.

- [x] **Step 5: Tests, including a mutation-testing pass**
  9 cases across the two new files: 7 straight `#[Locked]` rejection probes (one per property) using `Livewire::test(...)->set(...)` — the exact `HandleComponents::updateProperties()` path a real forged `/livewire/update` request goes through, not bare PHP property assignment; 1 case proving a forged `customerRef` cannot be used to file a complaint on a foreign order even before the exception is caught; 1 case proving an authenticated attacker cannot file a complaint via a stale/seeded `customerRef` property (the `resolveCustomerRef()` auth-priority defence); 1 case proving `submitManualProof()`'s ownership re-check refuses a component instance seeded (bypassing `#[Locked]`) with a stranger's `placedOrderNumber`.
  **Mutation-tested**: temporarily stripped every `#[Locked]` attribute from both files (`sed -i '/#\[Locked\]/d'`) and reran the 9 new cases — 7 failed exactly as expected (the two ownership-re-check cases, which don't depend on `#[Locked]` at all, stayed green, confirming they test the independent second layer correctly). Restored both files from a pre-mutation backup and reran clean.

### Task 2: MKT-09 — symmetric rupiah↔minor-unit conversion

**Files:**
- Modify: `app/Filament/Vendor/Resources/VendorListings/Schemas/VendorListingForm.php` (`price_minor` field, was raw minor-unit input labelled "dalam sen").
- Modify: `app/Filament/Admin/Resources/Vendors/RelationManagers/ListingsRelationManager.php` — form's `price_minor` field (labelled "Harga (Rp)" but wrote raw minor-unit) and table's `price_minor` column (`->numeric(decimalPlaces: 0)->prefix('Rp ')` on the raw minor-unit value, e.g. rendering "Rp 15000000" for a real Rp 150.000 listing).
- Modify (existing test, now needs updated expected values): `tests/Feature/Filament/VendorResourceTest.php`.
- New: `tests/Feature/Filament/Vendor/VendorListingFormMoneyTest.php`, `tests/Feature/Filament/Admin/Vendors/ListingsRelationManagerMoneyTest.php`.

- [x] **Step 1: Vendor-panel form** — `formatStateUsing`/`dehydrateStateUsing` pair (`intdiv($state, 100)` / `$state * 100`), relabel to "Harga (Rp)" with a `->prefix('Rp')`, matching `VendorListingsTable`'s already-correct display.

- [x] **Step 2: Admin `ListingsRelationManager` form** — same conversion pair on its `price_minor` field.

- [x] **Step 3: Admin `ListingsRelationManager` table** — replace the raw `->numeric(decimalPlaces: 0)->prefix('Rp ')` with `->money('IDR', divideBy: 100)`, the exact already-correct pattern from `VendorListingsTable`.

- [x] **Step 4: Update the one pre-existing test this touches**
  `VendorResourceTest::test_listings_relation_manager_creates_a_listing_with_real_closed_list_values` submits `'price_minor' => '750000'` through the now-converting form and previously asserted the stored value equalled `750000` unconverted; updated to assert `75_000_000` (the correct `*100` result) with a comment explaining why.

- [x] **Step 5: New symmetry tests, including a mutation-testing pass**
  `VendorListingFormMoneyTest` (vendor panel): create round-trips Rp 150.000 input to `price_minor = 15_000_000`; edit shows the stored `15_000_000` back as `150_000` via `assertFormSet()` and re-saves it unchanged, confirming the round trip. `ListingsRelationManagerMoneyTest` (admin): the same create/edit round trip through the relation manager's table actions, plus a table-rendering assertion computing the expected string via `Number::currency(150_000, 'IDR', app()->getLocale())` (rather than hardcoding a locale-dependent literal) and asserting the raw minor-unit digits never appear.
  **Mutation-tested**: temporarily reverted both fields' `formatStateUsing`/`dehydrateStateUsing` to identity (`(int) $state`) and the table column back to the raw `->numeric()->prefix('Rp ')`, via backed-up file copies. All 6 exposed test cases failed exactly as expected (including the money-string assertion correctly failing to find `"Rp 150.000,00"` in the raw-digit rendering, and `VendorResourceTest`'s existing case failing `750000 !== 75000000`). Restored both files from the pre-mutation backups and reran clean.

## After all tasks: whole-branch verification

```bash
IMAGE=149ac33766fb   # ghcr.io/andrianm88/makam-app, most recently created local tag
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" vendor/bin/pint --test
docker run --rm --user 1000:1000 -v "$(pwd)":/var/www/html -w /var/www/html \
  --entrypoint php "$IMAGE" -d memory_limit=2G vendor/bin/phpstan analyse --no-progress
bash ci/verify-docs.sh

docker run -d --name b2b-pg -e POSTGRES_USER=testuser -e POSTGRES_PASSWORD=testpass -e POSTGRES_DB=testdb -p 55442:5432 postgres:18
docker run -d --name b2b-redis -p 56439:6379 redis:8.2-alpine
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:RKxTuGlM4MNUB65volwGUsTfCiDumShAS0GGdu5zXn4= \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=55442 -e DB_DATABASE=testdb \
  -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=56439 \
  -e CACHE_STORE=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e MAIL_MAILER=array \
  -v "$(pwd)":/var/www/html -w /var/www/html "$IMAGE" \
  php -d memory_limit=1G vendor/bin/phpunit \
  tests/Feature/Livewire/Public/Marketplace/ \
  tests/Feature/Filament/VendorResourceTest.php \
  tests/Feature/Filament/Vendor/ \
  tests/Feature/Filament/Admin/Vendors/
docker rm -f b2b-pg b2b-redis
```

Result (this pass): see the final PR/report for the actual pint/phpstan/verify-docs/test output — filled in after running, never claimed ahead of the run.
