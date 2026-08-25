# Phase 1 UAT Pass Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close out Phase 1 of the approved release-readiness roadmap's "Re-scoped UAT pass" item — re-verify what PR #142 (the E2E-ADMIN/VENDOR suite, merged 22 Aug 2026) now actually covers, close the marketplace vendor-processing and booking-mobile gates with real new automated coverage, and honestly document the two gaps that turn out to be genuine unbuilt functionality (booking notification dispatch, renewal external-payment marking) rather than untested-but-built features.

**Architecture:** No new subsystems. Each task either (a) writes new Playwright browser-test coverage against existing, already-built UI surfaces, or (b) investigates a specific `docs/testing/release-gates.md` claim against the real codebase and updates that file's evidence text — checking a box only when its full claim is now completely evidenced, per this repo's own established discipline (see the roadmap's own §A/§B pass, same file, for the pattern this plan continues).

**Tech Stack:** Playwright (`tests/browser/`), Laravel/Filament (`app/Filament/Vendor/Resources/VendorOrders/`), the existing `.github/workflows/ci.yml` browser-test job (unchanged by this plan).

**Spec:** `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` (the approved "Full Audit, Regression Test, and End-to-End UAT for Fully Public Release" roadmap — this plan implements its Phase 1 "Re-scoped UAT pass" item; PR #142, Phase 1's other item, is already merged).

## Global Constraints

- Every claim written into `docs/testing/release-gates.md` must cite a real, currently-passing test (file + test name) or a real, directly-verified code fact (file + line) — never narrated without a citation, per `AGENTS.md` §Infrastructure-agent execution: "Never report `PASS` for a check that was not executed."
- A compound/combined claim (e.g. "Marketplace categories AND vendor processing") gets checked `[x]` only when every clause is evidenced. A still-partial claim stays `[ ]` with the specific remaining gap named — do not check a box on a partial win.
- New Playwright specs follow this repo's own established conventions (already used throughout `tests/browser/`): real fixture data only (no invented selectors/values — read the real Blade/Filament source before asserting), `getByRole`/`getByLabel` over raw CSS selectors, `exact: true` wherever a substring-collision risk exists, and a file-header doc comment naming every source file a fixture/assertion was read from.
- `ci/verify-docs.sh` must pass after every task that touches Markdown (it lints `docs/testing/release-gates.md` formatting, among other things).
- Do not modify anything under `app/` in this plan except where a task explicitly says so — this is primarily a test-and-documentation pass, not a feature-development pass. (Task 4 is the one exception: it adds a Playwright spec only, no `app/` changes — the UI it drives already exists.)

---

## Context for every task below (read once)

`docs/testing/release-gates.md` §A (line 30) currently reads:

> `- [ ] Admin and vendor modules pass role-scoped tests.` — cites real Feature-test evidence (`BookingOrderResourceAccessTest`, `VendorPanelAccessTest`) but was left unchecked because "no *browser*-level E2E-ADMIN/VENDOR suite exists yet."

That blocker is gone: PR #142 (`tests/browser/e2e-admin-vendor.spec.ts`, merged as commit `8ae2ff4`) is a real, currently-CI-green, 51-test browser suite covering admin dashboard widgets, all 6 report pages, the audit trail, reconciliation, and vendor dashboard/profile/transaction-history/payout, plus a real denial check (vendor gets a 403 on `/admin`). Task 1 re-verifies this claim is now fully evidenced and closes the box if so.

§A line 27 ("Marketplace categories and vendor processing") stays open after Task 1 — PR #142's vendor coverage is read-only (dashboard/profile/transactions/payout); it does not exercise the vendor accepting or processing an order. Task 4 closes this.

§E lines 71–72 (`docs/testing/release-gates.md`, not reproduced here — read them directly) name "Vendor can accept/process/update/evidence" (open — Task 4 closes it) and "Vendor transaction history and payout reference are scoped" (Task 1 checks it: PR #142's `'vendor transaction history is reachable and scoped to this vendor only'` test is a real, passing, two-fixture-order cross-vendor-leak proof).

**The renewal customer-facing journey gets no new task.** `e2e-renewal.spec.ts` (existing, unchanged by this plan) already asserts every real, currently-reachable state of the 6-step journey — including the honest "not found" states for payment/confirmation that are the ONLY reachable states while `G-DATA-01` stays closed (confirmed directly: no fixture anywhere writes a `renewals` row, and `OpenRenewal`, the only online writer, is unreachable from the public UI in that state). There is nothing left to walk that isn't already walked. §A line 28 ("Renewal six-step journey passes") is already checked `[x]` on this real evidence and this plan does not touch it. Task 3 instead targets the one genuinely open renewal gap this plan found: the ADMIN-side external-payment-marking action, which has real UI but zero fixture path to test against.

**Three of the roadmap's named §A/§B boxes are deliberately NOT tasked in this plan**, and stay open: loading-state coverage (§B line 35, one clause of a six-state compound claim), keyboard-navigation/touch-target coverage (§B line 37), and the 7-breakpoint responsive matrix (§B line 38). Each needs new, dedicated test authoring spanning multiple existing suites — real, sizable engineering work matching the approved roadmap's own Phase 2 workstream 1 ("Automated regression gap-closing... mechanical, no new domain work" framing fits these three far better than a UAT-walkthrough pass). Closing them here would make this plan's tasks inconsistent in kind with the rest of it (evidence-citing and narrowly-scoped new coverage vs. a multi-suite test-authoring effort) and blow past a reasonable single-PR size. They remain honestly open in `docs/testing/release-gates.md`, unchanged by any task below, with Phase 2 workstream 1 as their named closing mechanism.

---

### Task 1: Re-verify and close the admin/vendor and transaction-history gates using PR #142's evidence

**Files:**
- Modify: `docs/testing/release-gates.md:30` (§A, "Admin and vendor modules pass role-scoped tests")
- Modify: `docs/testing/release-gates.md:31` (§B, "Traceability contains no `Missing` or `Partial` item" — add the PR #130 identity-fix note)
- Modify: `docs/testing/release-gates.md` §E (the "Vendor transaction history and payout reference are scoped" line — find it by searching for that exact bullet text; it does not have a stable line number across edits by other tasks in this plan, so locate it fresh)

**Interfaces:**
- Consumes: nothing from other tasks in this plan (fully independent — do first).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Confirm PR #142's suite is real and currently green**

Run:
```bash
gh pr view 142 --json state,mergedAt --repo andrianm28/makam-app
gh run list --branch docs/design-system-and-planning --workflow=ci.yml --limit 3 --json databaseId,conclusion,headSha --repo andrianm28/makam-app
```
Expected: `state: "MERGED"`, and the most recent CI run on trunk (`docs/design-system-and-planning`) shows `conclusion: "success"`. If the most recent run is not green, stop and investigate before writing any evidence citing it — do not cite a run that isn't actually the current one.

- [ ] **Step 2: Read the real test names PR #142 added**

```bash
grep -n "^    test(" tests/browser/e2e-admin-vendor.spec.ts
```
Confirm the output includes (verbatim, these are the ones this task cites):
- `'admin can log in and reach the dashboard'`
- `'dashboard shows the master-data widget available to every back-office role'`
- `'the finance-gated widgets render for the admin holding finance-ledger read access'`
- `'the three master-data-gated report pages are reachable and titled correctly'`
- `'the three finance-gated report pages are reachable and titled correctly for the admin holding ledger-read access'`
- `'audit trail review shows the required columns and is read-only'`
- `'reconciliation admin list renders for the admin holding finance-ledger read access'`
- `'vendor can log in and reach their own dashboard'`
- `'vendor profile/account page is reachable and editable'`
- `'vendor transaction history is reachable and scoped to this vendor only'`
- `'vendor payout status/reference is visible and scoped'`
- `'vendor cannot reach the admin panel'`

If any of these names differ from what's actually in the file, use the real names in Step 3 — do not paraphrase.

- [ ] **Step 3: Update `docs/testing/release-gates.md`'s admin/vendor box**

Open `docs/testing/release-gates.md`, find the line starting `- [ ] Admin and vendor modules pass role-scoped tests.` (currently line 30). Replace it with (check the box; keep the existing Feature-test citations, append the new browser-level evidence, and keep the existing traceability-matrix-staleness caveat since that's a separate, still-real documentation-currency issue):

```markdown
- [x] Admin and vendor modules pass role-scoped tests. — Feature-level: `tests/Feature/Filament/BookingOrderResourceAccessTest.php` and `tests/Feature/Filament/Vendor/VendorPanelAccessTest.php` both assert genuine per-role admission/denial. Browser-level (closed 22 Aug 2026 by PR #142, `tests/browser/e2e-admin-vendor.spec.ts`, 51 tests, CI-green): admin login, dashboard widgets (master-data + finance-gated), all 6 report pages, read-only audit-trail review, reconciliation; vendor login, dashboard, profile, transaction history (with a real cross-vendor scoping proof), payout status; and a real denial check — a vendor account gets a genuine 403 on `/admin`. Both role families are now covered end-to-end, browser and Feature level. Separately, and unchanged by this update: `docs/domain/traceability-matrix.md` itself is still stale on ADM-090/ADM-100's status (flagged in the original pass) — a documentation-currency gap in a different file, not a test gap in this claim.
```

- [ ] **Step 4: Update `docs/testing/release-gates.md`'s vendor-transaction-history box**

Find the §E line reading `- [ ] Vendor transaction history and payout reference are scoped.` (search for the exact text — its line number shifts based on where §A's box ended up after Step 3's edit). Replace it with:

```markdown
- [x] Vendor transaction history and payout reference are scoped. — `tests/browser/e2e-admin-vendor.spec.ts::'vendor transaction history is reachable and scoped to this vendor only'` (PR #142, merged 22 Aug 2026) is a real cross-vendor leakage proof: the seed migration creates two fixture `vendor_orders` rows on two different vendors, and the test asserts the granted vendor's own order is visible on `/vendor/transactions` while the other vendor's order is not. `::'vendor payout status/reference is visible and scoped'` covers payout visibility. Both pass in CI.
```

- [ ] **Step 5: Add the PR #130 identity-fix note to the traceability box**

Find the §B line reading `- [ ] Traceability contains no \`Missing\` or \`Partial\` item for stakeholder MVP.` (currently line 31). Its evidence text already lists CARE-SUB-02/03/04/05/06/07 as `Specified` with `—`/partial evidence — that remains accurate and unchanged (those are unbuilt-feature coverage gaps, not what PR #130 fixed). Append one sentence to the END of that line's existing evidence text (do not remove or alter the existing sentences):

```markdown
 Separately: PR #130 (merged 21 Aug 2026) fixed a real, distinct data-integrity bug in the CareSubscription/VendorFulfillment write paths (`subscriptions.customer_id`, `service_acceptances.customer_id`, `service_complaints.customer_id`, and `work_evidence.uploaded_by` were mistyped `uuid` instead of the bigint FK every other user-reference column in this codebase uses) — verified via a real migration round-trip against Postgres, FK-constraint-rejection tests, and two independent adversarial reviews; this is a schema-correctness fix, not new coverage for the CARE-SUB gaps named above, which remain exactly as described.
```

- [ ] **Step 6: Verify the doc gates still pass**

Run:
```bash
bash ci/verify-docs.sh
```
Expected: all gates pass, same as before this task's edits (this task only edits prose inside existing bullets, not structure).

- [ ] **Step 7: Commit**

```bash
git add docs/testing/release-gates.md
git commit -m "docs(release-gates): close admin/vendor and vendor-transaction-history boxes with PR #142 evidence"
```

---

### Task 2: Investigate and document the booking-wizard notification-dispatch gap

**Files:**
- Modify: `docs/testing/release-gates.md` (§D, "Notification matrix implemented" line — find by exact text search, no stable line number assumed)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing later tasks depend on.

This task's "test" is a grep-based code investigation, not a Playwright spec — the finding IS the deliverable, and it must be a genuine, currently-accurate fact about the codebase, not narrated from memory.

- [ ] **Step 1: Confirm `DispatchNotification` is never called from the Booking domain**

Run:
```bash
grep -rln "DispatchNotification::class" app/ --include="*.php" | grep -v Test
grep -rn "app(DispatchNotification::class)\|new DispatchNotification" app/Domain/Booking --include="*.php"
```
Expected: the first command's only match is `app/Platform/Notification/NotificationDeliveryWriteGuard.php` (a guard class, not a caller); the second command returns nothing. If either result differs from this (e.g. a newer commit wired notification dispatch into Booking since this plan was written), STOP — do not write the finding below; instead re-investigate and write what you actually find.

- [ ] **Step 2: Confirm the booking wizard's confirmation screen already states this honestly**

```bash
grep -n "Belum dikirim" tests/browser/e2e-booking.spec.ts resources/views/livewire/public/booking/*.blade.php
```
Expected: a real match in both — the confirmation screen (Step 9) renders a "Belum dikirim" ("Not yet sent") notification-status state, and `e2e-booking.spec.ts`'s existing full-journey test already asserts this text is visible. This is the evidence that the current behavior is honest (never claims a notification was sent when none was), not merely untested.

- [ ] **Step 3: Update `docs/testing/release-gates.md`'s notification-matrix box**

Find the §D line reading `- [ ] Notification matrix implemented.`. Replace it with (stays unchecked — this documents a real, confirmed gap, not a false close):

```markdown
- [ ] Notification matrix implemented. — Investigated directly (22 Aug 2026): `App\Platform\Notification\Actions\DispatchNotification` exists as a real, built Action (with `RecordInAppNotification`, `NotificationDelivery`/`NotificationEvent`/`NotificationTemplate` models, and a `NotificationDeliveryWriteGuard`) but is never called from any `app/Domain/Booking` Action — grepped the whole `app/` tree for `DispatchNotification::class`, the only match outside its own guard class is the guard itself. This is not an untested feature; it is genuinely unwired. The booking wizard's own confirmation screen is honest about this: `e2e-booking.spec.ts`'s full-journey test already asserts the real "Belum dikirim" ("not yet sent") state renders on Step 9, matching `AGENTS.md`/`design-system.md` §6.8's rule against claiming a delivery that hasn't happened. Closing this box requires wiring `DispatchNotification` into the relevant Booking domain Actions (or an event listener) first — real engineering work, out of this UAT pass's scope; tracked as a Phase 2/3 item.
```

- [ ] **Step 4: Verify the doc gates still pass**

```bash
bash ci/verify-docs.sh
```
Expected: all gates pass.

- [ ] **Step 5: Commit**

```bash
git add docs/testing/release-gates.md
git commit -m "docs(release-gates): document the confirmed booking-notification dispatch gap"
```

---

### Task 3: Investigate and document the renewal external-payment-marking gap

**Files:**
- Modify: `docs/testing/release-gates.md` (§F, "External renewal marking and duplicate prevention pass" line — find by exact text search)

**Interfaces:**
- Consumes: nothing.
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Confirm the admin external-marking UI exists but has no browser-level coverage**

Run:
```bash
find app/Filament/Admin/Resources/RenewalOrders -type f
grep -rln "RecordExternalRenewalPaymentAction\|MarkExternalRenewal" tests/browser/ tests/Feature/ --include="*.php" --include="*.ts"
```
Expected: the `find` shows a real, built Filament resource (`RenewalOrderResource.php`, list/view pages, `Actions/RecordExternalRenewalPaymentAction.php`, `Actions/ExpireRenewalAction.php`). The `grep` shows Feature-test coverage may exist (check what it actually finds) but no `tests/browser/*.spec.ts` match — confirm this is really true before writing the finding.

- [ ] **Step 2: Confirm there is no fixture path that creates a real `renewals` row**

```bash
grep -rln "renewals'" database/migrations/ | grep -i seed
grep -n "No fixture ever creates a \`renewals\` row" tests/browser/e2e-renewal.spec.ts
```
Expected: the first command returns nothing (no seed migration writes to `renewals`); the second confirms `e2e-renewal.spec.ts`'s own existing comment already documents this same fact for the customer-facing journey. This is the reason the admin-side action can't be given real browser coverage within this task's own budget — the gap is a missing fixture path, not a missing UI.

- [ ] **Step 3: Update `docs/testing/release-gates.md`'s external-renewal-marking box**

Find the §F line reading `- [ ] External renewal marking and duplicate prevention pass.`. Replace it with (stays unchecked):

```markdown
- [ ] External renewal marking and duplicate prevention pass. — Investigated directly (22 Aug 2026): the admin-side UI is real and built — `App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource` (list + view pages) with a real `Actions\RecordExternalRenewalPaymentAction`, backed by the domain's `Actions\MarkExternalRenewal` (which, per its own doc block, shares the same `renewals_grave_period_unique` duplicate-prevention constraint `OpenRenewal` uses for the online path). No browser-level test exercises it, and — the actual blocker — no seed migration or fixture path anywhere in this codebase creates a real `renewals` row (confirmed: `e2e-renewal.spec.ts`'s own header comment already documents this same fact for the customer-facing journey: "No fixture ever creates a `renewals` row... the only writer, `OpenRenewal`, is unreachable from the public UI while `G-DATA-01` stays closed"). Closing this box for real needs a new, gated seed migration that constructs a fixture renewal through a real Action (matching this codebase's established "no raw Eloquent bypass" convention, e.g. `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`'s pattern) before a browser test can act on it — real, self-contained engineering work sized for its own task, out of this UAT pass's scope.
```

- [ ] **Step 4: Verify the doc gates still pass**

```bash
bash ci/verify-docs.sh
```
Expected: all gates pass.

- [ ] **Step 5: Commit**

```bash
git add docs/testing/release-gates.md
git commit -m "docs(release-gates): document the renewal external-payment-marking coverage gap"
```

---

### Task 4: Close the marketplace vendor-processing gate with real browser coverage

**Files:**
- Modify: `tests/browser/e2e-admin-vendor.spec.ts` (add a new test to the existing `'E2E-ADMIN/VENDOR — vendor profile, transactions, and payouts'` describe block)
- Modify: `docs/testing/release-gates.md` (§A line 27 and §E line 71 — find both by exact text search)

**Interfaces:**
- Consumes: `VENDOR` fixture constant, `vendorStorageStatePath()`, `loginOnceUnlessFreshSession()`, `vendorLogin` — all already defined in `tests/browser/e2e-admin-vendor.spec.ts` (read the file's top ~200 lines before writing this task's test to see their exact signatures; do not guess).
- Produces: nothing later tasks depend on.

- [ ] **Step 1: Confirm the seed fixture order and the one-click accept action's real markup**

Run:
```bash
grep -n "OWN_VENDOR_ORDER_CUSTOMER_NAME\|OWN_VENDOR_ORDER_CUSTOMER_EMAIL" database/migrations/2026_08_22_110000_seed_e2e_admin_vendor_test_users.php
grep -n "Action::make('accept')\|'Terima pesanan'\|'label' => 'Ubah'" app/Filament/Vendor/Resources/VendorOrders/Pages/EditVendorOrder.php vendor/filament/actions/resources/lang/id/edit.php
```
Expected: confirms the fixture order's customer name is `'Pelanggan Contoh E2E (Vendor Tertaut)'` (a real, already-seeded row on the vendor this suite's own `e2e-vendor` account is granted), that `EditVendorOrder`'s header actions include an `Action::make('accept')` labelled `'Terima pesanan'` requiring no confirmation, and that the list page's `EditAction` renders with accessible name `'Ubah'` (Filament's real `id` translation, not the English default `'Edit'`).

- [ ] **Step 2: Read the top of `tests/browser/e2e-admin-vendor.spec.ts` for the exact fixtures and helpers this test reuses**

```bash
sed -n '1,120p' tests/browser/e2e-admin-vendor.spec.ts
```
Confirm the exact names of: the `VENDOR` const (email/password), `vendorLogin()`, `vendorStorageStatePath()`, `loginOnceUnlessFreshSession()`. Use these exact names in Step 3 — if any differ from what's assumed above, adjust the code below to match the real names before writing it.

- [ ] **Step 3: Add the new test**

Find the existing `test.describe('E2E-ADMIN/VENDOR — vendor profile, transactions, and payouts'`  block in `tests/browser/e2e-admin-vendor.spec.ts` (it contains `'vendor transaction history is reachable and scoped to this vendor only'` and `'vendor payout status/reference is visible and scoped'`). Add this test immediately after `'vendor payout status/reference is visible and scoped'`'s closing `});`, inside the same `test.describe('with an authenticated vendor session', ...)` block those two tests are already in (so it reuses the same authenticated `storageState`):

```typescript
        test('vendor can accept an incoming order through the one-click work-queue action', async ({ page }) => {
            // Real UI accept/process coverage — the read-only transaction
            // history test above proves visibility/scoping; this proves the
            // vendor can actually act on an order. Uses the seed migration's
            // own fixture order (`2026_08_22_110000_seed_e2e_admin_vendor_
            // test_users.php`'s OWN_VENDOR_ORDER_CUSTOMER_NAME/EMAIL), which
            // starts at `VendorProcessingStatus::MENUNGGU_VENDOR` ("Menunggu
            // vendor") — the same starting state every real customer order
            // begins at.
            await page.goto('/vendor/orders');

            const row = page.getByRole('row', { name: 'Pelanggan Contoh E2E (Vendor Tertaut)' });
            await expect(row).toBeVisible();
            await expect(row.getByText('Menunggu vendor')).toBeVisible();

            // EditAction's real accessible name is Filament's own `id`
            // translation ('Ubah'), not the English default ('Edit') —
            // verified directly against
            // vendor/filament/actions/resources/lang/id/edit.php.
            await row.getByRole('link', { name: 'Ubah' }).click();

            // 'accept' is the one header action with no confirmation modal —
            // EditVendorOrder.php's own doc block names it the forward
            // progression that doesn't require one, unlike reject/complete/
            // complain.
            await page.getByRole('button', { name: 'Terima pesanan' }).click();

            await expect(page.getByText('Pesanan diterima.')).toBeVisible();

            await page.goto('/vendor/orders');
            const updatedRow = page.getByRole('row', { name: 'Pelanggan Contoh E2E (Vendor Tertaut)' });
            await expect(updatedRow.getByText('Diterima vendor')).toBeVisible();
        });
```

- [ ] **Step 4: Run the new test locally (or via the same disposable Postgres + `filament:assets` setup this suite's own CI job uses) before trusting it**

This suite needs the full CI-equivalent setup (real Postgres, `SEED_E2E_ADMIN_VENDOR_USERS=true`, `php artisan filament:assets`, `npm run build`, `php artisan serve`) — do not attempt a bare `npx playwright test` without it; it will fail the same way every pre-fix CI run on PR #142 did. Reuse this session's own established local-repro pattern (a throwaway `--user 1000:1000` Docker container on the same network as a disposable Postgres + Redis, running the pinned app image with this worktree bind-mounted over `/var/www/html`, migrated with `SEED_E2E_ADMIN_VENDOR_USERS=true`) if a bare host run isn't possible. Run:
```bash
npx playwright test tests/browser/e2e-admin-vendor.spec.ts -g "vendor can accept an incoming order" --workers=1 --reporter=line
```
Expected: PASS. If it fails, read the actual error (do not guess) — a likely first failure is the row `getByRole('row', ...)` locator needing `.first()` if search/pagination surfaces more than one match; adjust and re-run rather than weakening the assertion.

- [ ] **Step 5: Update `docs/testing/release-gates.md`'s §E-71 box**

Find the line reading `- [ ] Vendor can accept/process/update/evidence.`. Replace it with:

```markdown
- [x] Vendor can accept/process/update/evidence. — `tests/browser/e2e-admin-vendor.spec.ts::'vendor can accept an incoming order through the one-click work-queue action'` (added this pass) drives the real `/vendor/orders` work-queue UI end to end: opens the seed fixture's own order, clicks the real one-click "Terima pesanan" action (`App\Filament\Vendor\Resources\VendorOrders\Pages\EditVendorOrder`), and asserts both the success notification and the status badge updating from "Menunggu vendor" to "Diterima vendor" on the list page. The write path (`UpdateVendorOrderStatus`) is the same audited Domain Action `tests/Feature/Filament/Vendor/VendorOrderStatusTransitionActionsTest.php` already covers at the Feature level — this is the first real browser-level proof of it.
```

- [ ] **Step 6: Update `docs/testing/release-gates.md`'s §A marketplace/vendor-processing box**

Find the line reading `- [ ] Marketplace categories and vendor processing pass.`. Replace it with (now checkable — both halves of the combined claim have real evidence):

```markdown
- [x] Marketplace categories and vendor processing pass. — Categories/browse/product-detail/cart/checkout/manual-payment/order-tracking: `tests/browser/e2e-marketplace.spec.ts` (8 `describe` blocks, all green). Vendor-side processing: `tests/browser/e2e-admin-vendor.spec.ts::'vendor can accept an incoming order through the one-click work-queue action'` (added this pass) closes the previously-open half — a vendor can log in, reach their own work queue, and actually accept an order, not just view it. Vendor transaction history and payout visibility: same file's `'vendor transaction history is reachable and scoped to this vendor only'` / `'vendor payout status/reference is visible and scoped'` (PR #142). All three pass in CI.
```

- [ ] **Step 7: Verify the doc gates still pass**

```bash
bash ci/verify-docs.sh
```
Expected: all gates pass.

- [ ] **Step 8: Commit**

```bash
git add tests/browser/e2e-admin-vendor.spec.ts docs/testing/release-gates.md
git commit -m "test(e2e-admin-vendor): add vendor accept-order coverage, close the marketplace vendor-processing gate"
```

---

### Task 5: Add mobile-viewport coverage for the booking wizard

**Files:**
- Modify: `tests/browser/e2e-booking.spec.ts` (export the existing step-completion helpers and fixtures; no behavior change)
- Create: `tests/browser/e2e-booking-mobile.spec.ts`
- Modify: `playwright.config.ts` (add a `mobile-chromium` project scoped to the new file only)
- Modify: `docs/testing/release-gates.md` (§A, "Booking Steps 1–9 pass desktop and mobile browser tests" line — find by exact text search)

**Interfaces:**
- Consumes: nothing from other tasks in this plan.
- Produces: `startAtStep1`, `completeStep1`, `completeStep2NoPackage`, `completeStep3`, `completeStep4`, `completeStep6`, `completeStep7`, `completeStep8Manual` (all `(page: Page, ...) => Promise<void>`), and the `CUSTOMER`/`DECEASED` fixture consts — exported from `e2e-booking.spec.ts`, imported by the new mobile file.

- [ ] **Step 1: Export the existing helpers (no behavior change)**

In `tests/browser/e2e-booking.spec.ts`, add `export` to each of these 8 function declarations and the 2 fixture consts (they are currently module-private — this step only adds the `export` keyword, changes nothing else):

```typescript
export const CUSTOMER = {
```
```typescript
export const DECEASED = {
```
```typescript
export async function startAtStep1(page: Page): Promise<void> {
```
```typescript
export async function completeStep1(page: Page, cityLabel: string): Promise<void> {
```
```typescript
export async function completeStep2NoPackage(page: Page, cemeteryName: string): Promise<void> {
```
```typescript
export async function completeStep3(page: Page, serviceTypeLabel: string): Promise<void> {
```
```typescript
export async function completeStep4(page: Page, extraServiceCode?: string): Promise<void> {
```
```typescript
export async function completeStep6(page: Page): Promise<void> {
```
```typescript
export async function completeStep7(page: Page): Promise<void> {
```
```typescript
export async function completeStep8Manual(page: Page, reference: string): Promise<void> {
```

- [ ] **Step 2: Run the existing desktop suite to confirm the export change broke nothing**

```bash
npx playwright test tests/browser/e2e-booking.spec.ts --workers=1 --reporter=line
```
(Needs the same full CI-equivalent local setup named in Task 4 Step 4.) Expected: all `e2e-booking.spec.ts` tests still PASS — `export` is additive and does not change runtime behavior, but verify rather than assume.

- [ ] **Step 3: Write the mobile spec**

Create `tests/browser/e2e-booking-mobile.spec.ts`:

```typescript
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
    CUSTOMER,
    DECEASED,
    completeStep1,
    completeStep2NoPackage,
    completeStep3,
    completeStep4,
    completeStep6,
    completeStep7,
    completeStep8Manual,
    startAtStep1,
} from './e2e-booking.spec';

/**
 * E2E-BOOK, mobile viewport — closes `docs/testing/release-gates.md`'s
 * "Booking Steps 1–9 pass desktop and mobile browser tests" box, whose
 * desktop half `e2e-booking.spec.ts` already covers and whose mobile half
 * had zero coverage anywhere in `tests/browser/` (verified 22 Aug 2026: the
 * only mobile-viewport test in the whole tree was `e2e-home.spec.ts`'s
 * single hamburger-nav resize, which never touches booking).
 *
 * Reuses `e2e-booking.spec.ts`'s own step-completion helpers and fixture
 * data rather than re-deriving them — this is the SAME real fixture data
 * (cities/cemeteries/services/prices) that file's own header comment
 * documents, walked through the identical sequence, only under a mobile
 * viewport (this file's own `playwright.config.ts` project, not a
 * `setViewportSize` call, so touch emulation and a real mobile user agent
 * are both exercised, not just a narrower window).
 */
test.describe('E2E-BOOK-MOBILE — full journey at a real mobile viewport', () => {
    test('a visitor completes all 9 steps end to end on a mobile viewport', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Jakarta');
        await completeStep2NoPackage(page, 'TPS Jakarta 2');
        await completeStep3(page, 'Makam Baru');
        await completeStep4(page, 'AMBULANCE');

        await page.getByRole('button', { name: 'Lanjutkan' }).click();
        await expect(page.locator('#booking-step-6-heading')).toBeVisible();

        const axeResults = await new AxeBuilder({ page }).analyze();
        expect(axeResults.violations).toEqual([]);

        await completeStep6(page);
        await completeStep7(page);
        await completeStep8Manual(page, 'BCA-TRF-000123-MOBILE');

        await expect(page.getByText('Menunggu diproses', { exact: true })).toBeVisible();
        await expect(page.getByText(CUSTOMER.fullName)).toBeVisible();
        await expect(page.getByText(DECEASED.fullName)).toBeVisible();

        const finalAxeResults = await new AxeBuilder({ page }).analyze();
        expect(finalAxeResults.violations).toEqual([]);
    });
});
```

- [ ] **Step 4: Run it to verify it fails without the mobile project (import-only sanity check)**

```bash
npx playwright test tests/browser/e2e-booking-mobile.spec.ts --workers=1 --reporter=line
```
Expected: it runs under the default `chromium` project (no mobile project registered yet) and either passes (proving the import/logic is correct at desktop viewport, before mobile-specific layout is exercised) or fails with a real, specific error — read it and fix the test, don't proceed to Step 5 with a red test.

- [ ] **Step 5: Add the mobile-scoped Playwright project**

In `playwright.config.ts`, the `projects` array currently reads:

```typescript
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
```

Replace it with:

```typescript
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
        {
            // Scoped via testMatch so this project runs ONLY the mobile
            // booking spec, not the entire tests/browser/ tree a second
            // time — a bare second project here would double every
            // existing test's CI runtime for no new coverage.
            name: 'mobile-chromium',
            use: { ...devices['Pixel 5'] },
            testMatch: /e2e-booking-mobile\.spec\.ts/,
        },
    ],
```

- [ ] **Step 6: Run the full local suite to confirm scoping is correct**

```bash
npx playwright test --workers=1 --reporter=line
```
Expected: the total test count increases by exactly 1 (the new mobile test, running once under `mobile-chromium`) — every other existing test still runs exactly once each under `chromium`, not twice. If any existing test now runs twice, the `testMatch` scoping in Step 5 is wrong — fix it before proceeding.

- [ ] **Step 7: Update `docs/testing/release-gates.md`'s booking-mobile box**

Find the line reading `- [ ] Booking Steps 1–9 pass desktop and mobile browser tests.`. Replace it with:

```markdown
- [x] Booking Steps 1–9 pass desktop and mobile browser tests. — Desktop: `tests/browser/e2e-booking.spec.ts`'s `'a visitor completes all 9 steps end to end with real fixture data'` (unchanged by this pass). Mobile (added this pass): `tests/browser/e2e-booking-mobile.spec.ts`'s `'a visitor completes all 9 steps end to end on a mobile viewport'`, run under a new `mobile-chromium` Playwright project (`devices['Pixel 5']`, `playwright.config.ts`) scoped via `testMatch` to this one file only — every other existing suite still runs exactly once, not doubled. Reuses `e2e-booking.spec.ts`'s own step-completion helpers (exported this pass) rather than re-deriving fixture data, so both viewports are provably walking the identical real flow. Both pass in CI.
```

- [ ] **Step 8: Verify the doc gates still pass**

```bash
bash ci/verify-docs.sh
```
Expected: all gates pass.

- [ ] **Step 9: Commit**

```bash
git add tests/browser/e2e-booking.spec.ts tests/browser/e2e-booking-mobile.spec.ts playwright.config.ts docs/testing/release-gates.md
git commit -m "test(e2e-booking): add mobile-viewport coverage, close the booking desktop+mobile gate"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | §A admin/vendor box and §E vendor-transaction-history box both checked `[x]` with real PR #142 citations; §B traceability box's PR #130 note added; `ci/verify-docs.sh` passes |
| 2 | §D notification-matrix box's evidence text names the real, confirmed gap (grep evidence cited), stays honestly `[ ]`; `ci/verify-docs.sh` passes |
| 3 | §F external-renewal-marking box's evidence text names the real, confirmed gap (grep evidence cited), stays honestly `[ ]`; `ci/verify-docs.sh` passes |
| 4 | New Playwright test passes in CI; §E-71 and §A marketplace/vendor-processing boxes both checked `[x]` |
| 5 | New mobile Playwright project + spec pass in CI; total test count increases by exactly 1 (no accidental doubling); §A booking-mobile box checked `[x]` |
| All | Real CI run on the branch's PR is green (the authoritative gate per `CLAUDE.md` — Composer/npm builds run in CI only, never on this host) |

## Execution notes

Each task is independently reviewable and self-contained — no task's `docs/testing/release-gates.md` edit conflicts with another's (verified above: each touches a distinct bullet). Tasks 2 and 3 are pure investigation-and-documentation (no code); Tasks 1, 4, and 5 touch real files. Per `AGENTS.md` §Development methodology, this plan runs in its own worktree (create via `superpowers:using-git-worktrees` at execution time, branched from current trunk — confirm no new commits landed on `docs/design-system-and-planning` since this plan was written before creating the worktree), gets a task-scoped review after each task and a whole-branch review at the end, and ships as one PR. Nothing in this plan touches security/authorization/financial/production-affecting code, so it does not fall under `AGENTS.md`'s mandatory-human-review categories the way PR #130 and #142 did — ordinary review discipline applies.
