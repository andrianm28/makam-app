# Release Gates Phase 1 Closeout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 6 of the 30 currently-open boxes in `docs/testing/release-gates.md` that are genuinely closeable through bounded engineering work on this host — real test coverage, one small UI-building task, one dependency fix, one doc correction. Every other open box is explicitly named as out of scope, with the real reason it can't close this pass (needs live-host access, external procurement, a business/legal decision, or is large enough to need its own future plan).

**Architecture:** No new subsystems. Five tasks add test coverage or fix a real gap using patterns and infrastructure that already exist in this codebase (Playwright suites, the `RecordExternalRenewalPaymentAction`/domain-Action wiring pattern, the existing CI dependency-audit job). One task is a documentation-only correction. Each task closes exactly one release-gates.md box with real evidence, following this repo's own evidence-citation discipline (cite real test names, never overclaim, a box stays unchecked if only part of its claim is proven).

**Tech Stack:** Laravel 13 / PHP 8.5, Filament 5, Livewire 4, Playwright (TypeScript), PostgreSQL 18, Redis 8.2.

**Spec:** No separate spec document — this plan's scope was researched and user-approved this session (user chose "close remaining release-gates.md boxes" via `AskUserQuestion` after a status review of the current 30/60 gate). The plan's own Context section below is the authoritative scope record.

## Context

`docs/testing/release-gates.md` currently has 30 checked boxes and 30 open ones. The 30 open boxes span wildly different categories:

- **Genuinely engineering-closeable now** (this plan's scope): real test-coverage gaps where the underlying feature already exists and works, one small well-precedented UI gap, one real dependency vulnerability with a fix available, one stale documentation cross-reference.
- **Needs live production/staging host access this session cannot perform**: OS/firewall/SSH verification (I-109), `.env.dev`/`.env.stg` live value diffing (I-111), scanning the live host for stray production data (I-112), Horizon operational rehearsal (H-95), CI/CD rollback rehearsal (H-100), live monitoring setup (H-101, I-115), staging backup destination decision + execution (I-114, needs a human decision on where backups live plus real key generation).
- **Needs external procurement or a real product/business decision, not engineering**: `G-PAY-01` production activation (C-51, a deliberate audited human action per ADR-0033/0036), FIN-DEC approvals (H-97), a real malware scanner (H-102, blocked on OQ-7), the WhatsApp channel (D-66, needs an approved BSP/provider), Pulse/error-tracking/uptime tooling (H-101, real procurement), full-scale load-testing Profiles B/C/D (H-104, needs an isolated environment per `performance-and-capacity.md` §9, explicitly already deferred to Phase 3).
- **Too large for a bounded task in this pass, needs its own future plan**: the remaining ~11 unproduced notification-matrix rows (D-64) — investigated this session; only 6 of 17 rows have producers, and auditing/building the rest needs real per-row domain investigation, not a single afternoon's task. CARE-SUB-02–07 evidence gaps (A-35's substantive half) are a real recurring-care-subscriptions feature-coverage effort, not a bounded fix. `G-89`'s backup/restore and rollback overlaps already-tracked, already-accepted gaps (ADR-0035 item 2) or the same live-rehearsal blocker as H-100.
- **Already honestly correct as unchecked, not a todo**: I-117 (dev/staging noindex — mixed state, correctly described, not a gap), I-118 (the box whose claim inverted this session — correctly stays unchecked because the underlying no-PITR/no-HA facts are real, not because something is missing to build).

This plan's 6 tasks, each closing exactly one box:

1. **B-39** (loading state test coverage) — the booking wizard already has real `wire:loading` markup with `role="status"` spinners and disabled-during-request buttons (verified directly: `resources/views/livewire/public/booking/wizard.blade.php` lines 118, 599-601, 768-770, 1004-1006, 1016-1018+). Zero test asserts it. Add Playwright coverage.
2. **B-41** (keyboard nav / focus / touch targets) — labels are already covered; keyboard tab-order and 44px touch-target sizing (`docs/design/design-system.md` §7.3, the `h-11`/`min-h-11` token) have zero test evidence. Add Playwright coverage.
3. **B-42** (7-breakpoint responsive matrix) — `docs/design/design-system.md` §1.5 names 7 breakpoints (320/360/640/768/1024/1280/1536); nothing tests at those exact widths (`mobile-chromium`'s Pixel 5 project is 393px, not one of the 7). Add one lightweight spec asserting real breakpoint-triggered layout behavior at each of the 7 widths — not 7 new Playwright *projects* (would multiply full-suite CI runtime), a single spec using `page.setViewportSize()` per width, matching the technique already used once ad hoc in `e2e-home.spec.ts`.
4. **G-88** (npm audit gate) — investigated: the baseline is genuinely NOT clean. CI's own "Dependency audit" job (run `32675198044`, job `97282009260`, 23 Aug 2026) reports one real high-severity finding: `nanoid <3.3.18` (currently resolved at 3.3.16, a transitive dependency), fix available via `npm audit fix`. Pin it, remove the `|| true` swallow, verify via CI (not locally — no npm build on this host).
5. **F-84** (external renewal marking UI entry point) — `App\Domain\Renewal\Actions\MarkExternalRenewal` exists and is fully tested at the Feature level but has no Filament UI entry point anywhere (confirmed via `grep -rln "MarkExternalRenewal\b" app/` — only the test calls it). Unlike its sibling `RecordExternalRenewalPaymentAction` (which acts on an *existing* Renewal), `MarkExternalRenewal` *creates* a new Renewal from a `GraveRecord` + target period — so its UI entry point is a header action on `ListRenewalOrders`, with a searchable grave-record picker, not a per-row action. Build it following the exact same pattern (`Filament\Actions\Action`, re-authentication guard, audit-wrapped domain Action call) `RecordExternalRenewalPaymentAction` already establishes.
6. **A-35 bundle** (traceability matrix correction) — `docs/domain/traceability-matrix.md` rows ADM-090 (Reports) and ADM-100 (Audit-review surface) still say `Specified`/"unbuilt", but both are real, built, and browser-tested (`tests/browser/e2e-admin-vendor.spec.ts`, PR #142 — "all 6 report pages, read-only audit-trail review" per `release-gates.md`'s own already-checked §A box). Correct both rows to `Covered` with real citations. This does NOT touch the CARE-SUB-02–07 gaps named in the same release-gates.md box — those stay open, named explicitly as future work, not silently dropped.

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- No application build runs on this host (`CLAUDE.md`: "Do not run `npm run build` or a full `composer install` here; verify by pushing and checking the CI result instead"). Every task that needs npm/Playwright execution runs it against the real pinned container image + Postgres/Redis via Docker (the established pattern this whole session), never a bare `npm run build`.
- `docs/testing/release-gates.md`'s evidence-citation discipline: cite real test names, never overclaim, a box only gets checked when its full literal claim is evidenced — a box may stay unchecked with corrected/updated evidence if only part of its claim is proven.
- `AGENTS.md` §Observability: never place restricted data in logs/Pulse/Horizon tags/error trackers.
- No AWS; no production-affecting/security/authorization/financial/DNS/firewall config changes without human review. Task 5 (F-84) touches an authorization-adjacent write path (`MarkExternalRenewal` requires the `RenewalMarkingPolicy`'s admin-only + cemetery-scope grant, and money-adjacent re-authentication) — follow the exact existing pattern in `RecordExternalRenewalPaymentAction`, do not weaken or bypass either check.
- Each task independently closes exactly one named release-gates.md box with real, run evidence (never an unexecuted claim) — matching this repo's Task Right-Sizing convention.
- Do not touch any of the boxes named in this plan's Context section as out-of-scope. Do not silently resolve OQ-7 (malware scanner) or `G-PAY-01` anywhere.

---

### Task 1: Booking wizard loading-state Playwright coverage (closes §B-39)

**Files:**
- Create: `tests/browser/e2e-booking-loading-states.spec.ts`
- Modify: `docs/testing/release-gates.md` (§B-39 box only)

**Interfaces:**
- Consumes: the existing booking-wizard fixture/helper functions in `tests/browser/e2e-booking-helpers.ts` (already extracted this session for `e2e-booking-mobile.spec.ts` — reuse its exported helpers rather than re-deriving fixture data).
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Read the real current wizard loading markup**

Read `resources/views/livewire/public/booking/wizard.blade.php` in full. Confirm the exact `wire:loading`/`wire:target` pairs and their visible text (already found this session at lines ~118, ~599-601 "Menyimpan data pemesan…", ~768-770 "Menyimpan data almarhum…", ~1004-1006 "Menyimpan pilihan pembayaran…", ~1016-1018) — line numbers may have shifted; use the real file as truth.

- [ ] **Step 2: Read the existing helpers file**

Read `tests/browser/e2e-booking-helpers.ts` in full to find the exported step-completion helpers (e.g. a function that fills and submits Step 1 through Step 6) so this new spec reuses them instead of duplicating fixture-filling logic.

- [ ] **Step 3: Write the loading-state test**

Add a new spec file. Structure (adapt selectors to the real markup read in Step 1 — this is the shape, not verbatim-copyable code, since the real button/step DOM structure must be confirmed against the file):

```typescript
import { test, expect } from '@playwright/test';
import { completeStepsThroughCustomerData } from './e2e-booking-helpers';
// import whatever else e2e-booking-helpers.ts actually exports for reaching
// Step 6 (customer data) and Step 7 (deceased data) — read the real file
// first, this import list must match its real exports.

test.describe('E2E-BOOK — loading states', () => {
  test('the customer-data step shows a loading indicator and disables the submit button while saving', async ({ page }) => {
    await completeStepsThroughCustomerData(page); // reach Step 6, fields filled
    const submit = page.getByRole('button', { name: 'Lanjutkan' });
    // Fire the submit and the loading UI in parallel — the loading state is
    // often gone by the time a sequential `await` chain checks for it, since
    // a local server responds in milliseconds. Race the click against the
    // assertions instead of awaiting the click alone first.
    await Promise.all([
      expect(page.getByRole('status').filter({ hasText: 'Menyimpan data pemesan' })).toBeVisible(),
      expect(submit).toBeDisabled(),
      submit.click(),
    ]);
  });

  test('the deceased-data step shows a loading indicator and disables the submit button while saving', async ({ page }) => {
    // same shape, Step 7, "Menyimpan data almarhum…"
  });
});
```

The exact race-condition handling (asserting the loading state appears at all, given a fast local server) is the real risk in this test — Playwright's `expect(...).toBeVisible()` retries internally, but only if the assertion starts before the state has already come and gone. If a straightforward `Promise.all` above proves flaky in a real run (Step 5), fall back to intercepting/delaying the network response first (`page.route()` with an added delay on the Livewire update request) so the loading window is wide enough to assert reliably — do not skip or weaken the assertion, fix the race.

- [ ] **Step 4: Run the new spec against the real stack**

This host cannot run Playwright directly (no npm/browser toolchain — `CLAUDE.md`'s host-build restriction). Run it the same way this session's other new browser tests were verified: a real disposable Postgres 18 + Redis 8.2 + the pinned app image via Docker, `php artisan serve`, then `npx playwright test tests/browser/e2e-booking-loading-states.spec.ts` from a container/environment that has the npm toolchain (mirror whatever mechanism produced this session's other "confirmed locally, CI-pending" citations — if no such mechanism is available in this dispatch's environment, report `BLOCKED` with exactly what's missing rather than claiming an unexecuted PASS).

- [ ] **Step 5: Update `release-gates.md`'s §B-39 box**

Read the box's current full text first. Rewrite it to cite the new test by name and describe what was actually run (local-only confirmed, or CI-confirmed, whichever is真 true) — follow the exact evidence-citation style already used throughout this file (e.g. the pattern in the adjacent, already-checked §B-40 box). Check the box (`[ ]` → `[x]`) only if the new test genuinely passed a real run: this is a single-state compound claim (unlike §B-41/§B-42's multi-part claims), so a real passing test closes it fully.

- [ ] **Step 6: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add tests/browser/e2e-booking-loading-states.spec.ts docs/testing/release-gates.md
git commit -m "test(booking): add loading-state coverage for the wizard's Livewire steps"
```

---

### Task 2: Keyboard navigation, focus, and touch-target Playwright coverage (closes §B-41)

**Files:**
- Create: `tests/browser/e2e-a11y-interaction.spec.ts`
- Modify: `docs/testing/release-gates.md` (§B-41 box only)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Confirm the real 44px touch-target token and focus-ring convention**

Read `docs/design/design-system.md` §7.3 ("Touch targets") and its `--mk-focus-ring` section (search for `focus-visible` — found this session around line 988, "2 px `primary-600` ring at 2 px offset... `focus-visible` only"). These are the two real, documented rules this task tests against — do not invent a different threshold.

- [ ] **Step 2: Write the touch-target test**

Pick 2 real pages with primary CTAs on a mobile viewport (reuse the existing `mobile-chromium` Pixel 5 project, or set viewport directly): the homepage's primary CTA and the booking wizard's Step 1 city buttons (already covered structurally by `e2e-home.spec.ts`'s mobile nav test — read that file first to avoid duplicating exactly what it already covers, and pick DIFFERENT elements/pages if it already touches touch-target sizing incidentally). For each target element, assert its rendered bounding box meets the 44px floor:

```typescript
import { test, expect, devices } from '@playwright/test';

test.use({ ...devices['Pixel 5'] });

test.describe('E2E-A11Y — touch targets', () => {
  test('primary homepage CTA meets the 44px minimum touch target', async ({ page }) => {
    await page.goto('/');
    const cta = page.getByRole('link', { name: /* real primary CTA accessible name, confirm by reading resources/views/livewire/public/home */ });
    const box = await cta.boundingBox();
    expect(box).not.toBeNull();
    expect(box!.height).toBeGreaterThanOrEqual(44);
    expect(box!.width).toBeGreaterThanOrEqual(44);
  });

  // a second, different element/page — do not duplicate e2e-home.spec.ts's
  // existing mobile-nav coverage
});
```

- [ ] **Step 3: Write the keyboard-navigation test**

Pick one real, multi-field form (the booking wizard's Step 6 customer-data form is a good target — real labeled fields, a real submit button). Assert Tab reaches every interactive field in visual order and the submit button is keyboard-activatable:

```typescript
test.describe('E2E-A11Y — keyboard navigation', () => {
  test('the customer-data step is fully reachable and completable by keyboard alone', async ({ page }) => {
    // navigate to Step 6 via the existing e2e-booking-helpers.ts flow (mouse
    // clicks are fine to REACH the step; this test's own claim is about the
    // step's own form, not the whole wizard)
    // Then: press Tab repeatedly, assert focus lands on each real field in
    // order (page.locator(':focus') after each Tab, or evaluate
    // document.activeElement — read the real field order from the Blade
    // file, do not assume), fill via keyboard (page.keyboard.type), and
    // confirm the submit button can be reached and activated with Enter/Space
    // rather than a page.click().
  });
});
```

Assert the focused element also carries the real focus-visible styling (either check the computed `outline`/`box-shadow` isn't `none`, or — simpler and less brittle — assert the element matches the `focus-visible:ring-*` utility class already present in its own markup, confirmed by reading the real Blade source first).

- [ ] **Step 4: Run the new spec against the real stack**

Same execution approach as Task 1 Step 4 — a real disposable Postgres + Redis + the pinned app image, `npx playwright test tests/browser/e2e-a11y-interaction.spec.ts`. Report `BLOCKED` with specifics if the toolchain genuinely isn't available in this dispatch's environment rather than claiming an unexecuted PASS.

- [ ] **Step 5: Update `release-gates.md`'s §B-41 box**

Read the current text first. This is a 3-part compound claim (labels / keyboard+focus / touch targets) where labels are ALREADY evidenced. Update the box to state all 3 parts now have real evidence, citing the new spec by name alongside the existing label evidence already cited. Check the box only if all 3 parts are genuinely evidenced by a real passing run — if either the keyboard or touch-target test can't be run in this dispatch's environment, leave it unchecked and say exactly which part is still missing, per this repo's own no-overclaiming discipline.

- [ ] **Step 6: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add tests/browser/e2e-a11y-interaction.spec.ts docs/testing/release-gates.md
git commit -m "test(a11y): add keyboard-navigation and touch-target coverage"
```

---

### Task 3: 7-breakpoint responsive matrix coverage (closes §B-42)

**Files:**
- Create: `tests/browser/e2e-responsive-breakpoints.spec.ts`
- Modify: `docs/testing/release-gates.md` (§B-42 box only)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Confirm the 7 real documented breakpoints**

Read `docs/design/design-system.md` §1.5 in full. Confirm the exact 7 widths (found this session: 320/360/640/768/1024/1280/1536) and what layout behavior the design system says changes at each (e.g. nav collapses to hamburger below a named breakpoint, a grid goes from N to M columns) — do not invent behavior the design system doesn't actually specify.

- [ ] **Step 2: Write ONE spec asserting real breakpoint-triggered behavior at each width**

Do NOT add 7 new Playwright *projects* (each project re-runs setup/teardown and would multiply CI runtime for the whole suite). Use a single spec file with `page.setViewportSize()` per width, matching the technique `e2e-home.spec.ts` already uses once ad hoc (375×812) — read that file's existing mobile-nav test first as the established pattern to follow, then extend it to the 7 real documented widths:

```typescript
import { test, expect } from '@playwright/test';

const BREAKPOINTS = [320, 360, 640, 768, 1024, 1280, 1536];

test.describe('E2E-RESPONSIVE — documented breakpoint matrix', () => {
  for (const width of BREAKPOINTS) {
    test(`homepage navigation renders correctly at ${width}px`, async ({ page }) => {
      await page.setViewportSize({ width, height: 900 });
      await page.goto('/');
      // Assert real, width-dependent behavior confirmed in Step 1 — e.g.
      // below the documented nav-collapse breakpoint the hamburger trigger
      // is visible and the full inline menu is not, and vice versa above
      // it. Read the real nav Blade/CSS to confirm the actual breakpoint
      // class (e.g. a `md:` or `lg:` Tailwind prefix) rather than assuming
      // which of the 7 widths is the real cutover.
    });
  }
});
```

If the real design system does not specify a concrete behavior change at every one of the 7 widths (some breakpoints may only affect spacing/typography, not a structural layout change worth a distinct assertion), it's legitimate for some of the 7 sub-tests to assert a narrower thing (e.g. "content does not overflow horizontally at this width" via a scrollWidth/clientWidth comparison) rather than all 7 asserting the identical nav-collapse claim — note in the task report which widths got which kind of assertion and why, rather than forcing an artificial identical claim at every width.

- [ ] **Step 3: Run the new spec against the real stack**

Same execution approach as Task 1 Step 4.

- [ ] **Step 4: Update `release-gates.md`'s §B-42 box**

Read the current text first. This box's literal claim is "Responsive behavior passes agreed viewport matrix" against the 7-breakpoint matrix specifically. If all 7 widths now have real, passing assertions, check the box and cite the new spec by name, explicitly noting this is now real coverage AT the documented breakpoints (distinguishing it from `mobile-chromium`'s Pixel 5 project, which remains a separate, narrower thing — a full-suite mobile run at one non-matrix width, not this matrix itself). If only some widths got real behavioral assertions (per Step 2's note), be precise about which, and use judgment on whether that's enough to check the box — a partial claim can still close a box if the box's real substance is now covered, per this repo's own precedent (e.g. §F's search-performance box closed on a smaller-than-100k dataset with an explicit scale note); if genuinely unsure, leave it unchecked with the corrected, narrower evidence and say why.

- [ ] **Step 5: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add tests/browser/e2e-responsive-breakpoints.spec.ts docs/testing/release-gates.md
git commit -m "test(responsive): add coverage at the documented 7-breakpoint matrix"
```

---

### Task 4: Fix the real nanoid vulnerability and enforce the npm audit gate (closes §G-88)

**Files:**
- Modify: `package.json`
- Modify: `package-lock.json`
- Modify: `.github/workflows/ci.yml`
- Modify: `docs/testing/release-gates.md` (§G-88 box only)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks (independent).

**Real, verified finding this session**: CI's "Dependency audit" job (run `32675198044`, job `97282009260`, 23 Aug 2026) reports exactly one high-severity finding — `nanoid <3.3.18` (currently resolved at `3.3.16` via `package-lock.json`, a transitive dependency — not a direct one, confirmed via `grep -n "nanoid" package-lock.json`), GHSA-2v37-7h3g-55p8, "custom generators can loop indefinitely when size is zero", fix available via `npm audit fix`. `.github/workflows/ci.yml`'s dependency-audit job currently runs `npm audit --audit-level=high || true`, swallowing this finding rather than gating on it.

- [ ] **Step 1: Read the real current `package.json`/`package-lock.json`/`ci.yml` dependency-audit step**

Confirm nanoid's real resolved version and which direct dependency pulls it in (read `package-lock.json`'s `"dependencies"` block for whatever package lists `nanoid` as its own dependency — this session found it at line ~1782 with `"nanoid": "^3.3.16"`, but confirm which parent package that line belongs to by reading the surrounding JSON object, not just the isolated grep hit).

- [ ] **Step 2: Pin nanoid to a safe version**

Add an `overrides` field to `package.json` (npm's mechanism for forcing a transitive dependency's version without waiting for the parent package to bump it) — do not attempt to run `npm install`/`npm audit fix` on this host, since builds only run in CI:

```json
{
  "overrides": {
    "nanoid": "^3.3.18"
  }
}
```

Adjust the exact key placement to match `package.json`'s real existing structure (read it first — this is illustrative of the mechanism, not necessarily the exact final diff if the file's real shape differs, e.g. if an `overrides` key already exists for something else, merge into it rather than creating a duplicate top-level key).

- [ ] **Step 3: Update `package-lock.json` to match**

`package-lock.json` cannot be regenerated by running `npm install` on this host (no npm build here). Manually update the `nanoid` entries' `"version"` field to `3.3.18` (or later) and their `"resolved"`/`"integrity"` fields to match the real published `3.3.18` package metadata (available from `https://registry.npmjs.org/nanoid` — check what this session's WebFetch tool or equivalent can retrieve, or if genuinely unable to fetch the real integrity hash from this environment, report this as a concrete blocker in the task report rather than fabricating an integrity hash — an invented hash would make `npm ci` fail in CI with an integrity mismatch, which is worse than leaving the box open). If the lockfile edit genuinely cannot be done correctly and safely from this environment, mark this step `BLOCKED` and explain exactly what's missing — do not guess at a lockfile hash.

- [ ] **Step 4: Remove the `|| true` swallow in CI**

Read `.github/workflows/ci.yml`'s real current dependency-audit job. Change `npm audit --audit-level=high || true # TODO: fail once the baseline is clean` to a genuinely gating `npm audit --audit-level=high` (drop both the `|| true` and the now-resolved TODO comment).

- [ ] **Step 5: Verify via CI, not locally**

This fix cannot be verified on this host (no npm toolchain). Commit and note explicitly in the task report that verification is CI-only — the next real CI run against this branch is the actual proof, and the task report must say `NOT TESTED locally, CI-pending` rather than claiming a local PASS it cannot have performed.

- [ ] **Step 6: Update `release-gates.md`'s §G-88 box**

Read the current text first. This box's real claim is narrower than the whole file's box text implies — re-read it and match its exact literal wording. State the real fix (nanoid pinned, CI gate now enforcing) and be explicit that verification is CI-pending, not yet a confirmed passing run — do NOT check this box until a real CI run on this branch/PR actually confirms the "Dependency audit" job passes with the gate enforcing; if this task's own dispatch cannot see that CI result (likely, since the branch won't be pushed yet), leave the box unchecked with the corrected evidence and say so, matching this session's established "cite what's real, don't claim what's not yet observed" discipline (e.g. this exact pattern is already used in several already-checked boxes in this file for "confirmed locally... CI confirmation still pending").

- [ ] **Step 7: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add package.json package-lock.json .github/workflows/ci.yml docs/testing/release-gates.md
git commit -m "fix(deps): pin nanoid past GHSA-2v37-7h3g-55p8, enforce the npm audit CI gate"
```

---

### Task 5: External renewal marking — Filament UI entry point (closes §F-84)

**Files:**
- Create: `app/Filament/Admin/Resources/RenewalOrders/Actions/MarkExternalRenewalAction.php`
- Modify: `app/Filament/Admin/Resources/RenewalOrders/Pages/ListRenewalOrders.php`
- Create: `tests/Feature/Filament/MarkExternalRenewalActionTest.php`
- Modify: `docs/testing/release-gates.md` (§F-84 box only)

**Interfaces:**
- Consumes: `App\Domain\Renewal\Actions\MarkExternalRenewal` (real, existing — signature: `__invoke(GraveRecord $grave, string $targetDuePeriod, string $evidence, string $reason): void`, throws `AuthorizationException` and `DuplicateRenewalPeriodException`). `App\Domain\Renewal\RenewalMarkingPolicy` (real, existing — admin-only + cemetery-scope grant, same policy `MarkExternalRenewal` itself calls internally). The exact pattern in `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php` (re-authentication guard, `Filament\Actions\Action`, `Notification::make()` success/failure handling) — read this file in full before writing the new one, it is the template to follow, not just a reference.
- Produces: nothing consumed by later tasks (independent).

- [ ] **Step 1: Read the real files this task depends on, in full**

Read, in this order: `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php` (the pattern template), `app/Domain/Renewal/Actions/MarkExternalRenewal.php` (the domain Action this wires to — already read this session, confirm current signature hasn't changed), `app/Domain/Renewal/RenewalMarkingPolicy.php` (the authorization policy), `app/Filament/Admin/Resources/RenewalOrders/Pages/ListRenewalOrders.php` (where the new header action attaches — unlike `RecordExternalRenewalPaymentAction`, which is a per-row action on `ViewRenewalOrder` because it acts on an existing Renewal, `MarkExternalRenewal` *creates* a new Renewal from a `GraveRecord`, so its entry point is a header action on the LIST page, not a per-row action on an existing renewal), `app/Domain/GraveRegistry/Models/GraveRecord.php` (confirm the real fillable/searchable fields — `deceased_name` confirmed this session, verify current `$fillable` list directly).

- [ ] **Step 2: Write the Filament Action**

Follow `RecordExternalRenewalPaymentAction`'s exact structure (re-authentication guard, `->authorize()` gate, `Audit`-wrapped domain call, success/failure `Notification::make()`), adapted for the create-not-update shape:

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The `ListRenewalOrders` header action that creates a NEW renewal record via
 * `App\Domain\Renewal\Actions\MarkExternalRenewal` — distinct in shape from
 * the sibling `RecordExternalRenewalPaymentAction`, which acts on an
 * EXISTING renewal. This one starts from a grave-record search, since no
 * renewal row exists yet for the external period being recorded.
 *
 * [Adapt the doc block's specific enforcement-layer description to match
 * what Step 1's read of RecordExternalRenewalPaymentAction actually says —
 * do not invent a different enforcement description than what that sibling
 * file documents, since both must describe the same real two-layer guard.]
 */
final class MarkExternalRenewalAction
{
    public static function make(): Action
    {
        return Action::make('mark_external_renewal')
            ->label('Tandai Perpanjangan Eksternal')
            ->color('warning')
            ->icon(Heroicon::OutlinedBanknotes)
            ->requiresConfirmation()
            ->modalHeading('Catat perpanjangan eksternal')
            ->modalDescription('Perpanjangan ditandai dibayar di luar platform dan dicatat di audit.')
            ->schema([
                Select::make('grave_record_id')
                    ->label('Makam')
                    ->searchable()
                    ->getSearchResultsUsing(
                        fn (string $search): array => GraveRecord::query()
                            ->where('deceased_name', 'ilike', "%{$search}%")
                            ->limit(20)
                            ->pluck('deceased_name', 'id')
                            ->all()
                    )
                    ->getOptionLabelUsing(
                        fn ($value): ?string => GraveRecord::find($value)?->deceased_name
                    )
                    ->required(),

                TextInput::make('target_due_period')
                    ->label('Periode')
                    ->required(),
                    // Confirm the real expected format for targetDuePeriod
                    // by reading MarkExternalRenewalTest.php's real fixture
                    // values in Step 1 — do not guess the format here.

                Textarea::make('evidence')->label('Bukti')->rows(2)->required(),
                Textarea::make('reason')->label('Alasan')->rows(2)->required(),
            ])
            ->authorize(fn (): bool => self::authorized())
            ->action(function (array $data): void {
                $actor = app(ActorContext::class);

                try {
                    app(ReauthenticationGuard::class)->assertFresh($actor);
                } catch (ReauthenticationRequiredException) {
                    Notification::make()
                        ->warning()
                        ->title('Perlu verifikasi ulang')
                        ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                        ->send();

                    session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'money_action');
                    redirect()->route(PasswordReauthentication::ROUTE_NAME);

                    return;
                }

                $grave = GraveRecord::findOrFail((int) $data['grave_record_id']);

                try {
                    app(MarkExternalRenewal::class)(
                        $grave,
                        (string) $data['target_due_period'],
                        (string) $data['evidence'],
                        (string) $data['reason'],
                    );
                    Notification::make()->success()->title('Perpanjangan eksternal dicatat.')->send();
                } catch (DuplicateRenewalPeriodException $exception) {
                    Notification::make()->danger()->title('Periode ini sudah tercatat')->body($exception->getMessage())->send();
                } catch (AuthorizationException|\Throwable $exception) {
                    Notification::make()->danger()->title('Gagal mencatat perpanjangan')->body($exception->getMessage())->send();
                }
            });
    }

    private static function authorized(): bool
    {
        // Mirror RecordExternalRenewalPaymentAction::authorized()'s real
        // shape from Step 1's read — RenewalMarkingPolicy needs a GraveRecord
        // to check the cemetery-scope grant against, which a header action
        // (no bound record yet) doesn't have. If the real policy's
        // ->allows() signature genuinely requires a grave up front, this
        // gate may need to be coarser here (e.g. "does this actor hold ANY
        // admin cemetery-scope grant") with the real, precise per-grave
        // check happening inside ->action() when MarkExternalRenewal itself
        // calls the policy — confirm this by reading RenewalMarkingPolicy's
        // real signature in Step 1 and resolve this ambiguity explicitly in
        // the task report rather than silently picking one without saying so.
        return true; // placeholder shape — Step 1's real policy read decides this
    }
}
```

The `authorized()` method above is deliberately left as an open question for the implementer to resolve against the REAL `RenewalMarkingPolicy` signature (read in Step 1) — do not ship the placeholder `return true`. If the policy needs a specific `GraveRecord` to evaluate the grant and none is bound yet at header-action-visibility time, the correct resolution is almost certainly: gate `->visible()`/`->authorize()` on a coarser "does this actor hold admin role" check (matching how `RenewalMarkingPolicy` denies non-admin roles outright before it even reaches the cemetery-scope check — confirm this two-part structure by reading the real policy file), and let the real, precise per-grave enforcement happen where it already correctly lives: inside `MarkExternalRenewal::__invoke()` itself, which the `->action()` closure already calls and already correctly catches `AuthorizationException` from. State this reasoning explicitly in the task report.

- [ ] **Step 3: Wire it into `ListRenewalOrders`**

Read the real current `ListRenewalOrders.php` header-actions array and add `MarkExternalRenewalAction::make()` to it, following whatever pattern the page already uses for its existing header actions (if any exist — read the file to confirm).

- [ ] **Step 4: Write the Feature test**

Follow `MarkExternalRenewalTest.php`'s existing conventions (read it first for the real factory/fixture setup this codebase already uses for `GraveRecord`, admin actors, and cemetery-scope grants). Cover: an authorized admin with the right cemetery-scope grant can complete the action and a real `Renewal` row with `source = RenewalSource::EXTERNAL` is created; a non-admin/wrong-scope actor is denied; a stale (not-recently-authenticated) admin is redirected to re-authentication rather than the action silently succeeding; a duplicate `(grave_record_id, target_due_period)` pair surfaces the same `DuplicateRenewalPeriodException` the online path raises, not a raw database error.

```php
<?php

declare(strict_types=1);

// namespace/use block — follow MarkExternalRenewalTest.php's real imports

final class MarkExternalRenewalActionTest extends TestCase
{
    // real test methods per the coverage list above — read
    // MarkExternalRenewalTest.php and RecordExternalRenewalPaymentAction's
    // own sibling Filament-level test (if one exists — check for a
    // RecordExternalRenewalPaymentActionTest.php or equivalent to follow
    // its exact Filament-action-testing pattern, e.g. Livewire::test(...)
    // ->callAction(...)) for the real testing convention this codebase uses
    // for Filament Actions specifically, not just Domain Actions.
}
```

- [ ] **Step 5: Run the new test against the real stack**

```bash
php artisan test tests/Feature/Filament/MarkExternalRenewalActionTest.php
```

Against real Postgres 18 (matching this session's established verification bar) — report the real pass/fail output, never an unexecuted claim.

- [ ] **Step 6: Update `release-gates.md`'s §F-84 box**

Read the current text first. This box's remaining gap was specifically "no Filament UI entry point anywhere" — that's now closed. Cite the new Action class and Feature test by name. This does not need a browser-level Playwright proof to close (the box's own prior text already distinguished "browser-level rendering coverage" — which `e2e-renewal-external.spec.ts` from a prior pass already covers for the READ side — from "the marking ACTION itself," which this task closes at the Feature/Filament-action level, matching how other money-adjacent admin actions in this same file, e.g. §C's manual-payment verification box, are closed on Feature-level evidence). Check the box if the new test genuinely passes a real run.

- [ ] **Step 7: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add app/Filament/Admin/Resources/RenewalOrders/Actions/MarkExternalRenewalAction.php app/Filament/Admin/Resources/RenewalOrders/Pages/ListRenewalOrders.php tests/Feature/Filament/MarkExternalRenewalActionTest.php docs/testing/release-gates.md
git commit -m "feat(renewal): add the missing Filament UI entry point for external renewal marking"
```

---

### Task 6: Correct traceability-matrix.md's stale ADM-090/ADM-100 rows (closes the A-35 doc-currency half)

**Files:**
- Modify: `docs/domain/traceability-matrix.md`
- Modify: `docs/testing/release-gates.md` (§A-35 box only — the doc-currency sentence, not the CARE-SUB substantive gaps)

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing.

- [ ] **Step 1: Read the real current state of both rows and their real evidence**

Read `docs/domain/traceability-matrix.md` rows ADM-090 (line ~162) and ADM-100 (line ~163) in full, plus the surrounding prose (lines ~70-77, ~398) that currently says both "remain unbuilt and are not claimed." Read `docs/testing/release-gates.md`'s already-CHECKED §A box (the "Admin and vendor modules pass role-scoped tests" box) in full — it already cites the real evidence: `tests/browser/e2e-admin-vendor.spec.ts` (PR #142, 18 tests) covering "all 6 report pages" and "read-only audit-trail review" at the browser level. Confirm this evidence directly by reading `tests/browser/e2e-admin-vendor.spec.ts` yourself (do not just trust the release-gates.md citation) — find the actual test names covering reports and audit-trail review.

- [ ] **Step 2: Update the two traceability-matrix.md rows**

Change ADM-090 and ADM-100's `Specified` status to `Covered`, with the real test names from Step 1 in the evidence column (follow the table's exact existing column structure — read a few already-`Covered` rows first to match the citation style). Do not touch any other row.

- [ ] **Step 3: Update the two prose references**

The two "remain unbuilt and are not claimed" sentences (v0.10/v0.11 notes, lines ~30/~32, and the "Scope of the claim" paragraph at line ~398) need a dated correction — follow this repo's own established convention for these version-note sections (append a new dated note rather than silently rewriting the old ones, matching the "keep the v0.10 note above verbatim" pattern already used in this exact file). Add a new note (e.g. "v0.12 — 24 Aug 2026") stating ADM-090/ADM-100 are now `Covered` per Step 2, citing the real evidence, and explicitly stating this does NOT change anything about the CARE-SUB-02–07 gaps `release-gates.md`'s §A-35 box separately names (those remain open, unaffected by this correction).

- [ ] **Step 4: Update `release-gates.md`'s §A-35 box**

Read the current full text first. This box has two real, distinct halves: (a) a documentation-currency claim about ADM-090/ADM-100 being stale — closed by this task, and (b) substantive CARE-SUB-02–07 evidence gaps — NOT touched by this task, remain open. Update the box's text to reflect that half (a) is now corrected, while being explicit that half (b) is unchanged and still keeps this box open overall (the box's literal claim — "Traceability contains no Missing or Partial item for stakeholder MVP" — was already not literally checkable per the box's own existing reasoning; this task does not change whether the box gets checked, only removes one of the two things making it honestly stay open). Do not check this box — the CARE-SUB gaps alone are sufficient reason it stays open, and this task does not address them.

- [ ] **Step 5: Run doc gates and commit**

```bash
bash ci/verify-docs.sh
git add docs/domain/traceability-matrix.md docs/testing/release-gates.md
git commit -m "docs(traceability): correct ADM-090/ADM-100's stale unbuilt status to Covered"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | New Playwright spec proves the wizard's loading state renders and disables the submit button during a real save, `ci/verify-docs.sh` passes, §B-39 evidence updated honestly (checked only if a real run passed) |
| 2 | New spec proves keyboard reachability + activation on a real form AND 44px touch targets on 2 real elements, §B-41 evidence updated per-part (may stay unchecked if only some parts ran) |
| 3 | New spec asserts real behavior at all 7 documented breakpoints (not just the existing 393px mobile project), §B-42 evidence updated |
| 4 | nanoid pinned past the real GHSA-2v37-7h3g-55p8 finding, CI's npm audit gate genuinely enforces (no `\|\| true`), §G-88 box states CI-pending verification honestly (not checked until a real CI run confirms) |
| 5 | `MarkExternalRenewalAction` exists, wired into `ListRenewalOrders`, Feature test passes against real Postgres proving authorized-success/denied/stale-reauth/duplicate-collision, §F-84 checked with real citation |
| 6 | ADM-090/ADM-100 corrected to `Covered` with real evidence, §A-35's doc-currency half corrected while its CARE-SUB substantive half stays explicitly open |

Final whole-branch review checks: does any task's release-gates.md edit accidentally touch a box outside its own scope (all 6 tasks touch the same file — verify each diff's hunk boundaries stay inside its own named box)? Does Task 4's lockfile edit risk breaking `npm ci` in CI (a fabricated integrity hash would be worse than an open box — the final review should specifically re-check Task 4's lockfile diff against the real published package metadata if reachable)? Does Task 5's `authorized()` resolution (the one deliberately-left-open design question in this brief) land on a defensible answer, consistent with `RenewalMarkingPolicy`'s real enforcement shape?

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution. All 6 tasks are file-independent except that all 6 touch `docs/testing/release-gates.md` in different boxes — dispatch sequentially (already this session's default), never in parallel, so each task's release-gates.md edit lands on top of the previous one cleanly.
