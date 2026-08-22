# Release Gates — v0.5 MVP

## A. Stakeholder scope acceptance

<!--
Closed against the 5 durable Playwright suites on trunk (tests/browser/e2e-home.spec.ts,
e2e-faq.spec.ts, e2e-renewal.spec.ts, e2e-booking.spec.ts, e2e-marketplace.spec.ts, plus
smoke.spec.ts) and, where a bullet does not require a browser suite, Feature-level tests —
each box below was checked or left open against the actual test source, not the file's
existence. Real, current, passing evidence: GitHub Actions run 32455851923 ("Merge pull
request #139 from andrianm28/test/e2e-marketplace-journey"), job "Browser + a11y smoke
test (Playwright)" — conclusion success, https://github.com/andrianm28/makam-app/actions/runs/32455851923
— on commit 34d5551, the current tip of docs/design-system-and-planning. `playwright test`
(package.json's `test:browser` script) runs every file under `tests/browser/` per
`testDir: './tests/browser'` in playwright.config.ts, so this one run covers all 6 spec
files together, not just smoke.spec.ts. The same commit's "PHP (validate, lint, analyse,
test)" job also passed in that run, covering the Feature-test evidence cited below.
E2E-ADMIN/VENDOR (browser-level) does not exist yet and is out of scope for this pass —
someone else is building it in parallel; no box below is checked on the assumption it
will land.
-->

- [x] Homepage displays four primary menus in exact order. — `tests/browser/e2e-home.spec.ts::'desktop navigation shows the four primary menu labels in order'` asserts the exact ordered array `['Pemesanan Makam', 'Layanan Pemakaman', 'Perpanjangan Makam', 'FAQ']` plus each link's href; `'mobile navigation exposes the same four menu labels behind the hamburger'` proves the same four are reachable on a 375×812 viewport. Both pass in the cited CI run.
- [x] Five launch regions are present. — `tests/browser/e2e-renewal.spec.ts::'city step lists all five MVP launch cities and TPU/TPS selection reaches a real cemetery'` asserts `await expect(cityButtons).toHaveCount(5)` against `LaunchCityCode::KNOWN_CODES`, plus a real click-through to a cemetery. Passes in the cited CI run.
- [ ] Booking Steps 1–9 pass desktop and mobile browser tests. — Desktop: fully covered, `tests/browser/e2e-booking.spec.ts`'s `'a visitor completes all 9 steps end to end with real fixture data'` walks Steps 1–9 with real fixture data and 2 in-flow axe scans, plus 3 more `describe` blocks for back-navigation/autosave/package-selection/service-type branches — all pass in the cited CI run. Mobile: **not tested at all**. `playwright.config.ts` defines exactly one project (`chromium`, `devices['Desktop Chrome']`) and `e2e-booking.spec.ts` contains zero `setViewportSize`/device-emulation calls (verified by grep across the file) — the suite never runs at a mobile viewport or with touch emulation. The only mobile-viewport test in the entire `tests/browser/` tree is `e2e-home.spec.ts`'s single 375×812 resize for the hamburger nav, which does not touch booking. Leaving unchecked until a real mobile run of the booking journey exists.
- [x] Exact service catalog is available. — `tests/browser/e2e-booking.spec.ts` Step 4 asserts real catalogue names/prices for `DOCUMENT_PROCESSING`/`GRAVE_DIGGING` (mandatory, disabled, "Wajib" ×2) and `AMBULANCE` (optional), and Step 5 asserts the computed total against real seeded prices — both pass in the cited CI run. Exhaustive coverage of every `ServiceCode::KNOWN_CODES` value (not just the 3 the E2E suite exercises) is `tests/Feature/Livewire/Public/Booking/BookingWizardStepsFourAndFiveTest.php::test_step_4_offers_every_basic_and_additional_service`, which iterates the enum itself rather than a hardcoded list — also passing in the same CI run's PHP job.
- [ ] Marketplace categories and vendor processing pass. — Categories/browse/product-detail/cart/checkout/manual-payment/order-tracking are real and pass: `tests/browser/e2e-marketplace.spec.ts` (8 `describe` blocks, all green in the cited CI run). Vendor-side processing (accept/process/status/evidence) and vendor transaction history are explicitly **not** covered by this suite — its own file header says so: "Vendor-side processing... and vendor transaction history are NOT covered here — they need an authenticated Filament vendor-panel session, a concern shared with the not-yet-built `E2E-ADMIN/VENDOR` suite." Feature-level vendor-processing coverage does exist (`tests/Feature/Filament/Vendor/VendorOrderStatusTransitionActionsTest.php`, named in `docs/domain/traceability-matrix.md`'s MKT-04/VENDOR-01 rows) but no *browser* test exercises it, and the bullet's own wording ("categories and vendor processing pass") reads as one combined claim spanning both halves. Leaving unchecked with the split made explicit rather than checking on the browse/checkout half alone.
- [x] Renewal six-step journey passes. — `tests/browser/e2e-renewal.spec.ts` (6 tests) asserts all six stepper labels in order (`Kota, TPU/TPS, Cari Makam, Biaya, Pembayaran, Konfirmasi`), walks city→cemetery→search, and asserts honest gate-closed/not-found states for every downstream step (fee/payment/confirmation) consistent with `G-DATA-01` being closed by default — all pass in the cited CI run.
- [x] FAQ six categories and customer-service CTA pass. — `tests/browser/e2e-faq.spec.ts::'all six FAQ categories are listed as filter chips'` asserts the exact 6-category array; `'the customer-service CTA is present on the FAQ index and on an article detail page'` asserts both CTA links resolve to `/bantuan`. Draft-leakage, search, category-scoping, and article-detail tests in the same file also pass. All green in the cited CI run.
- [x] Admin and vendor modules pass role-scoped tests. — Feature-level: `tests/Feature/Filament/BookingOrderResourceAccessTest.php` and `tests/Feature/Filament/Vendor/VendorPanelAccessTest.php` both assert genuine per-role admission/denial. Browser-level (closed 22 Aug 2026 by PR #142, `tests/browser/e2e-admin-vendor.spec.ts`, 51 tests, CI-green): admin login, dashboard widgets (master-data + finance-gated), all 6 report pages, read-only audit-trail review, reconciliation; vendor login, dashboard, profile, transaction history (with a real cross-vendor scoping proof), payout status; and a real denial check — a vendor account gets a genuine 403 on `/admin`. Both role families are now covered end-to-end, browser and Feature level. Separately, and unchanged by this update: `docs/domain/traceability-matrix.md` itself is still stale on ADM-090/ADM-100's status (flagged in the original pass) — a documentation-currency gap in a different file, not a test gap in this claim.
- [ ] Traceability contains no `Missing` or `Partial` item for stakeholder MVP. — Checked the literal claim against `docs/domain/traceability-matrix.md` directly: the file's status vocabulary (see its own "Status legend") is only `Specified` / `Specified (gated fallback)` / `Covered` — the strings `Missing` and `Partial` do not appear anywhere in the file, so the bullet is not literally falsifiable as worded. Substantively, though, section B ("Stakeholder Workflow MVP") still carries real, uncovered gaps within stakeholder-MVP scope: ADM-070 (payment/transaction/manual verification) is `Specified` with test evidence `—`; CARE-SUB-03/04/05/07 are `Specified` with evidence `—`; CARE-SUB-02 and CARE-SUB-06 are `Specified` with partial evidence; and REN-03's own evidence trail says outright "the renewal spec's **AC4 (< 500 ms at 100,000 records)**... is **NOT TESTED and not passing**." Separately (see the note above), the matrix's ADM-090/ADM-100 rows are themselves stale — they undercount what's actually built and tested. Leaving unchecked: the literal string search passes but the substance the bullet is checking for does not, and the traceability file needs a maintenance pass (owner: whoever maintains `docs/domain/traceability-matrix.md`) before this box can be honestly checked. Separately: PR #130 (merged 21 Aug 2026) fixed a real, distinct data-integrity bug in the CareSubscription/VendorFulfillment write paths (`subscriptions.customer_id`, `service_acceptances.customer_id`, `service_complaints.customer_id`, and `work_evidence.uploaded_by` were mistyped `uuid` instead of the bigint FK every other user-reference column in this codebase uses) — verified via a real migration round-trip against Postgres, FK-constraint-rejection tests, and two independent adversarial reviews; this is a schema-correctness fix, not new coverage for the CARE-SUB gaps named above, which remain exactly as described.

## B. UX and accessibility

- [ ] Loading, empty, error, pending, success, and support states reviewed. — Empty, error, pending, success, and support all have real, passing test evidence: empty (`e2e-marketplace.spec.ts` empty cart, `e2e-renewal.spec.ts` no-result search, `e2e-faq.spec.ts` empty category), error (`e2e-marketplace.spec.ts` checkout phone-length validation error, multiple honest 404/not-found states across all four content suites), pending (`e2e-booking.spec.ts` Step 9 "Menunggu diproses", never styled as success), success (`e2e-marketplace.spec.ts` "Pesanan diterima"), support (`/bantuan` CTA asserted in `e2e-home.spec.ts`, `e2e-faq.spec.ts`, `e2e-renewal.spec.ts`, `e2e-marketplace.spec.ts` is absent from checkout but present via the gate-closed banners). Loading state has **zero** test evidence anywhere in `tests/browser/` — grepped all 5 suites plus smoke.spec.ts for `loading`/`wire:loading`/`skeleton`, no matches. Leaving unchecked because this is a six-state compound claim and one of the six states is entirely untested.
- [x] Autosave/resume and browser back behavior pass. — `tests/browser/e2e-booking.spec.ts`'s dedicated `'E2E-BOOK — autosave and resume'` describe block (`'navigating away and back to the draft URL resumes at the saved step'`, `'an unknown draft id resets to a working blank wizard rather than exposing state'`) and the `'E2E-BOOK — progress indicator and back/forward navigation'` block's `'the stepper reflects the current step and back navigation preserves data'` (asserts a filled customer-form field survives a `Kembali` round trip, proving server-persisted autosave rather than client-only state). Both pass in the cited CI run.
- [ ] Keyboard navigation, focus, labels, and touch targets pass. — Labels: strongly evidenced — every suite locates form fields via `getByLabel()`/`getByRole()` almost exclusively (not CSS selectors), which only resolves if the accessible label is real, plus every test file runs `AxeBuilder` scans that include label-related WCAG rules and assert zero violations. Keyboard navigation, explicit focus-order, and touch-target sizing have **zero** dedicated test evidence — grepped all 5 suites plus smoke.spec.ts for `keyboard`/`\.press(`/`focus`/`touch`, the only hits are unrelated code comments (e.g. "TPS Jakarta 2... does not touch the wizard's Step 3"). Leaving unchecked — labels are covered, keyboard nav and touch targets are not.
- [ ] Responsive behavior passes agreed viewport matrix. — `docs/design/design-system.md` §1.5 defines a 7-breakpoint matrix (320/360/640/768/1024/1280/1536). `playwright.config.ts` defines exactly one project (`chromium`/Desktop Chrome, effectively one viewport), and the only viewport override anywhere in `tests/browser/` is `e2e-home.spec.ts`'s single ad hoc `setViewportSize({ width: 375, height: 812 })` for one mobile-nav test (375 is not even one of the 7 documented breakpoints). No suite runs against the documented matrix. Leaving unchecked.
- [x] Copy is empathetic and does not overpromise Urgent/payment availability. — `e2e-home.spec.ts::'homepage shows the Urgent gate explanatory state and the customer-service CTA'` asserts the honest "Ketersediaan Urgent Belum Dapat Dipastikan Otomatis" banner (never a false availability claim) with a support link. `e2e-booking.spec.ts` Step 8/9 asserts the manual-payment fallback and "Menunggu diproses"/"Belum dikirim" states never claim a payment or notification that hasn't actually happened. `e2e-marketplace.spec.ts::'online payment is either gate-closed, or fails with a named, honest error'` asserts the fixed, non-leaking denial copy. `e2e-renewal.spec.ts` asserts the gate-closed and no-result states are always three-part-honest (what's empty, why, what to do next), never a bare denial. All pass in the cited CI run.

## C. Payment

Either mode must pass:

### Online

- [ ] Shared payment/journal/reconciliation gate approved.
- [ ] Merchant, quote, amount, signature, replay, retry, and concurrency tests pass.
- [ ] No direct paid path.

### Manual fallback

- [ ] Instructions and reference are approved.
- [ ] Proof/verification and authorization pass.
- [ ] Pending state is truthful.
- [ ] Invoice only follows approved verification.

## D. Notifications

- [ ] Notification matrix implemented. — Investigated directly (22 Aug 2026): `App\Platform\Notification\Actions\DispatchNotification` exists as a real, built Action (with `RecordInAppNotification`, `NotificationDelivery`/`NotificationEvent`/`NotificationTemplate` models, and a `NotificationDeliveryWriteGuard`) but is never called from any `app/Domain/Booking` Action — grepped the whole `app/` tree for `DispatchNotification::class`, the only match outside its own guard class is the guard itself. This is not an untested feature; it is genuinely unwired. The booking wizard's own confirmation screen is honest about this: `e2e-booking.spec.ts`'s full-journey test already asserts the real "Belum dikirim" ("not yet sent") state renders on Step 9, matching `AGENTS.md`/`design-system.md` §6.8's rule against claiming a delivery that hasn't happened. Closing this box requires wiring `DispatchNotification` into the relevant Booking domain Actions (or an event listener) first — real engineering work, out of this UAT pass's scope; tracked as a Phase 2/3 item.
- [ ] Email baseline passes.
- [ ] WhatsApp enabled only with approved template/provider.
- [ ] Admin/operator/vendor recipient scope passes.
- [ ] Channel failure does not change business state.
- [ ] No sensitive attachment is sent.

## E. Marketplace/vendor

- [ ] All minimum products/categories seeded or configured.
- [ ] Single-vendor cart constraint is explicit.
- [ ] Vendor can accept/process/update/evidence.
- [x] Vendor transaction history and payout reference are scoped. — `tests/browser/e2e-admin-vendor.spec.ts::'vendor transaction history is reachable and scoped to this vendor only'` (PR #142, merged 22 Aug 2026) is a real cross-vendor leakage proof: the seed migration creates two fixture `vendor_orders` rows on two different vendors, and the test asserts the granted vendor's own order is visible on `/vendor/transactions` while the other vendor's order is not. `::'vendor payout status/reference is visible and scoped'` covers payout visibility. Both pass in CI.
- [ ] Customer order tracking passes.

## F. Renewal/data

- [ ] Search performance target passes.
- [ ] Empty/manual assistance behavior passes.
- [ ] Tariff source and last-updated display pass.
- [ ] External renewal marking and duplicate prevention pass. — Investigated directly (22 Aug 2026): Feature-level tests exist and pass: `tests/Feature/Domain/Renewal/MarkExternalRenewalTest.php` (11 tests covering role/scope authorization, duplicate-prevention constraint enforcement, audit logging, and renewal state transitions) and `tests/Feature/Filament/RenewalOrderResourceTest.php` (7 tests covering resource access control and action authorization). The admin-side UI is real and built: `App\Filament\Admin\Resources\RenewalOrders\RenewalOrderResource` (list + view pages) with `Actions\RecordExternalRenewalPaymentAction`, backed by the domain's `Actions\MarkExternalRenewal` (which shares the same `renewals_grave_period_unique` duplicate-prevention constraint as `OpenRenewal` for the online path). However: no browser-level (Playwright) test exercises the UI, and no seed migration or fixture path anywhere creates a real `renewals` row. This fixture gap blocks browser coverage — documented in `e2e-renewal.spec.ts` itself ("No fixture ever creates a `renewals` row... the only writer, `OpenRenewal`, is unreachable from the public UI while `G-DATA-01` stays closed"). Closing this box requires engineering work out of scope for this pass: a gated seed migration constructing a fixture renewal through a real Action (per this codebase's "no raw Eloquent bypass" convention), plus a corresponding browser test.

## G. Security/operations

- [ ] No unresolved critical/high security issue without formal acceptance.
- [ ] Authorization, audit, upload, migration, backup/restore, and rollback tests pass.
- [ ] Support contacts, hours, incident owner, and escalation are configured.

## H. Technical production-readiness

- [ ] Runtime/package versions match `technology-baseline.md` and lockfiles.
- [ ] Horizon supervisors, queue priorities, long-wait alerts, and graceful restart pass.
- [ ] Transactional outbox loss/duplicate/replay tests pass.
- [ ] FIN-DEC decisions required by the activated money path are approved.
- [ ] Balanced journal, refund/payable/payout, and reconciliation tests pass for enabled features.
- [ ] Managed PostgreSQL backup/PITR configured and restore evidence is current.
- [ ] CI/CD immutable build, expand/contract migration, smoke test, and rollback rehearsal pass.
- [ ] Pulse, error tracking, uptime, DB/Redis metrics, and correlation IDs are configured and access-controlled.
- [ ] Upload quarantine and malware-scanner fail-closed behavior pass.
- [ ] Privileged MFA, session revocation, and recent re-authentication pass.
- [ ] Performance/capacity profiles pass or exceptions are formally accepted.


## I. Combined development/staging host acceptance

- [ ] Host is Ubuntu 22.04 LTS with current security updates, firewall, key-only SSH, and restricted access.
- [ ] PHP 8.5/Laravel 13/PostgreSQL 18/Redis 8.2 come from pinned images, not host-default packages.
- [ ] Development and staging have different APP keys, database users, Redis/Horizon prefixes, queues, cookies, storage, and provider credentials.
- [ ] No production data or credentials exist on the host.
- [ ] Staging normal Horizon pool is capped at two processes; development/batch workers run on demand.
- [ ] Remote staging backup and restore procedure passes.
- [ ] Memory, swap, disk, OOM, PostgreSQL, Redis, queue, and container monitoring is active.
- [ ] Restricted staging upload remains fail-closed without a real scanner.
- [ ] Dev/staging domains are access-restricted and `noindex`.
- [ ] The host is not recorded as production capacity/PITR/HA evidence.
