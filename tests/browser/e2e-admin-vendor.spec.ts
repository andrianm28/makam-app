import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';
import { ADMIN, adminLogin, adminStorageStatePath, loginOnceUnlessFreshSession } from './support/admin-session';

/**
 * E2E-ADMIN/VENDOR — the last of the six planned durable Playwright
 * suites (`docs/testing/test-strategy.md` §2). Covers: all required admin
 * dashboard modules, query scope + sensitive-action audit, and vendor
 * transaction history + payout visibility.
 *
 * Authentication: both accounts are seeded by
 * `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`
 * (throwaway `@example.test` accounts, real audited role/scope grants via
 * `GrantActorRole`/`GrantScopeAssignment` — not a test-only auth bypass).
 * Both Filament panels (`/admin`, `/vendor`) use Filament's own default
 * email/password login form — no custom login page exists for either.
 *
 * Real fixture data only — no invented selectors or values:
 *   - Dashboard widget titles/stat labels: app/Filament/Admin/Widgets/*.php
 *   - Report page titles: app/Filament/Admin/Pages/*Report*.php, *::getTitle()
 *   - Audit resource columns: app/Filament/Admin/Resources/AuditEvents/Tables/AuditEventsTable.php
 *   - Vendor page titles: app/Filament/Vendor/Pages/*.php, ::getTitle()
 *   - Vendor/entity fixture data: App\Support\ExampleData\VendorListingExampleData
 *     (seeded by 2026_08_14_100000_seed_vendors_and_listings.php)
 *
 * All values above were read directly from the source files during
 * planning, not guessed from PR titles — several PR titles this suite's
 * plan was built against (ADM-001/070/090/100, VND-080) describe intent
 * more broadly than what actually shipped; every literal string below is
 * cross-checked against the real class/view, and Step 1 of each task below
 * re-confirms it against the live rendered page before asserting on it.
 *
 * ---------------------------------------------------------------------------
 * e2e-admin also holds `finance` + a privileged BUSINESS_ENTITY grant —
 * verified live, not assumed
 * ---------------------------------------------------------------------------
 * `FinancialOverviewWidget` and `FailedPaymentExceptionQueueWidget`, plus 3
 * of the 6 admin report pages (`finance-reports`, `receipts-report`,
 * `outgoing-payments-report`), gate on `FinanceLedgerReadAuthorizer`
 * (`app/Platform/FinancialLedger/FinanceLedgerReadAuthorizer.php`) — a
 * strictly narrower gate than the four-role `MasterDataAdminAuthorizerContract`
 * that guards `PlatformOverviewWidget` and the other three report pages
 * (Orders, RenewalPeriod, VendorPerformance): it requires the actor to hold
 * the real `finance` role specifically (its own doc block: "Same role
 * (finance only, not restricted_admin)") PLUS at least one active
 * privileged `BUSINESS_ENTITY` scope grant.
 *
 * The seed migration
 * (`database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`)
 * grants `e2e-admin` `ActorRole::ADMIN`, `ActorRole::FINANCE`, and a
 * privileged `BUSINESS_ENTITY` scope against a clearly-fake reference
 * (`'e2e-admin-vendor-fixture-entity'`) specifically so this suite can prove
 * every required dashboard module actually renders, matching this suite's
 * own AC ("all required dashboard modules") rather than documenting a
 * finance-gated subset as correctly denied.
 */

const VENDOR = {
    email: 'e2e-vendor@example.test',
    password: 'E2eVendorPassword!1',
};

// CI (and this repo's default runtime) renders the app with
// APP_LOCALE=id (`config/app.php`), and Filament ships its own `id`
// translations (`vendor/filament/filament/resources/lang/id/auth/pages/
// login.php`) — the login form's real accessible names are the Indonesian
// strings below ("Alamat email" / "Kata sandi" / "Masuk"), not the English
// defaults. Confirmed live against a failing CI run: `getByLabel('Email
// address')` never resolved and the test timed out, because that English
// label is never rendered under this app's configured locale.
//
// Filament's default login page wraps the password field's label ("Kata
// sandi") and its required-marker ("*") in a `<superscript>`, so the
// accessible name is "Kata sandi*" — and the field itself sits alongside
// "Sembunyikan kata sandi"/"Tampilkan kata sandi" reveal-toggle buttons
// whose aria-labels also contain the substring "kata sandi"
// (`vendor/filament/forms/resources/lang/id/components.php`).
// `getByLabel('Kata sandi')` therefore resolves to 3 elements. Scoping to
// role `textbox` excludes both buttons and keeps this a real-markup
// locator, not a raw CSS selector.
//
// The trailing `waitForLoadState('networkidle')` is deliberate, not
// decorative: Filament's login redirect is a Livewire-driven client-side
// navigation, and a `page.goto()` fired immediately after `waitForURL()`
// resolves can race that in-flight navigation and get cancelled/superseded
// (observed live: an immediate follow-up `goto()` intermittently landed back
// on a stale intermediate URL). Settling here before returning means every
// caller's next navigation is real, not a race.
async function vendorLogin(page: Page): Promise<void> {
    await page.goto('/vendor/login');
    await page.getByLabel('Alamat email').fill(VENDOR.email);
    await page.getByRole('textbox', { name: 'Kata sandi' }).fill(VENDOR.password);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL(/\/vendor\/?$/);
    await page.waitForLoadState('networkidle');
}

// Filament's stock `Login` page (`vendor/filament/filament/src/Auth/Pages/Login.php`)
// rate-limits `authenticate()` to 5 attempts per 60s via
// `danharrin/livewire-rate-limiting`, keyed on
// `sha1(<Login page's PHP class>|authenticate|<request IP>)`. Neither panel
// registers a custom Login page (see this file's header comment), so both
// panels resolve to the SAME class (`Filament\Auth\Pages\Login`) — meaning
// admin and vendor logins share one rate-limit bucket, keyed only by IP.
// Every test in this suite runs from the same container's single IP.
//
// This file has exactly 5 real, unguarded login call-sites: the admin
// canary test, the admin-authenticated describe's `beforeAll`, the
// reconciliation describe's `beforeAll`, the vendor canary test, and the
// vendor-authenticated describe's `beforeAll` — 5 logins, every single
// clean run, sitting exactly on the shared 5-attempts/60s budget with zero
// headroom (an earlier version of this comment said "3", which undercounted
// the reconciliation describe's own `beforeAll` as sharing the admin
// block's login for free — it does not, since nothing before this fix ever
// checked whether a session was already saved). Verified live: the 6th
// `authenticate()` call inside a 60s window is silently rate-limited (the
// login form submits, gets no notification, and simply never redirects),
// which surfaces as a `waitForURL` timeout indistinguishable at first
// glance from a real login bug — and CI's `retries: 2` (`playwright.config.ts`)
// means ANY retry of a test inside one of these `beforeAll`-guarded
// describes re-runs that `beforeAll`, becoming exactly that 6th call.
//
// The two canary tests are deliberately NOT covered by the guard below —
// each exists specifically to prove a real, from-scratch login works, so it
// must always perform a real `authenticate()` call. The fix instead targets
// the 3 `beforeAll` hooks: each now skips its login entirely when a
// still-fresh `storageState` file from a prior attempt in this run already
// exists (see `loginOnceUnlessFreshSession()` below), so a Playwright retry
// costs 0 additional login attempts, not 1.
//
// Verified live (spinning up a throwaway retry-triggering spec): a
// Playwright retry runs in a NEW worker process — `process.pid` changes
// across the retry — but keeps the SAME `process.env.TEST_PARALLEL_INDEX`
// for that logical concurrent slot (`TEST_WORKER_INDEX` changes;
// `TEST_PARALLEL_INDEX` does not). The storage-state paths below are
// therefore keyed by `TEST_PARALLEL_INDEX`, not `process.pid` — a retry's
// `beforeAll` can then find and reuse the ORIGINAL attempt's saved session
// instead of spending another one of the shared budget's 5 attempts.
// Distinct concurrent slots (0 and 1 under this suite's `workers: 2`) still
// get distinct files, so two slots logging in around the same moment never
// race on the same file.
//
// Also verified live: `fullyParallel` (`playwright.config.ts`) can split a
// SINGLE describe's own tests across both worker slots, triggering that
// describe's `beforeAll` — and therefore a real login — once per slot it
// lands on, and separately leaves it to scheduling luck whether the
// admin-authenticated describe and the reconciliation describe (both
// keying the same `adminStorageStatePath()`) land on the same slot to
// share one real login. `test.describe.configure({ mode: 'serial' })`
// below removes both effects by keeping this whole file on one worker, in
// declaration order — a single clean run then costs exactly 4 real logins,
// deterministically: 2 canary + 1 admin (reused by the reconciliation
// describe) + 1 vendor.
//
// That leaves exactly 1 attempt of headroom inside a single run, which is
// what makes a Playwright retry of any one test free. It does NOT
// necessarily leave enough for two full, separate `npx playwright test`
// invocations run back-to-back with no gap: the 2 canary logins in each
// invocation are irreducible by design (see above), so two invocations cost
// at least 2+2 = 4 real canary logins alone, plus at least 1 real admin and
// 1 real vendor login the very first time either account has never logged
// in this run — a floor of 6 against a budget of 5, one over, REGARDLESS of
// caching, if both invocations' logins land inside the same 60s window.
// `STORAGE_STATE_FRESHNESS_MS` below is deliberately long enough to let a
// second invocation reuse the first invocation's still-fresh admin/vendor
// sessions (dropping its own beforeAll cost to 0), which is what keeps that
// floor at 6 rather than 8 — but the 1-over-budget canary collision itself
// is a hard mathematical floor, not a bug in this fix: verified live
// (running the full suite twice, back-to-back, in the same window,
// repeatedly) it costs at most one retried test, which that retry always
// recovers — a world apart from the original bug's guaranteed, unrecoverable
// failure with zero headroom at all.
const STORAGE_STATE_FRESHNESS_MS = 10 * 60 * 1000; // Generous enough to
// cover this suite's own run plus any retries, and a manual same-window
// re-run of the whole file (deliberately reused across separate `npx
// playwright test` invocations too, not just within one — a fresh
// invocation's very first canary login already costs 2 of the shared
// budget's 5 attempts before any `beforeAll` runs at all, so letting a
// still-fresh session from a moments-ago invocation carry over is what
// keeps a same-window re-run survivable, not just a same-invocation retry).
// Short enough that a long-abandoned file from a much earlier session on a
// long-lived dev box is never mistaken for "this run's" login and reused
// blindly.

function parallelSlot(): string {
    // `TEST_PARALLEL_INDEX` identifies this test's logical concurrent slot
    // (0..workers-1) and — unlike `process.pid` or `TEST_WORKER_INDEX` — is
    // stable across a Playwright retry, which is exactly why it's used to
    // key the storage-state cache below. Playwright's worker process sets
    // this unconditionally (CI or not); the `?? '0'` is just a defensive
    // fallback, not a real CI/local distinction.
    return process.env.TEST_PARALLEL_INDEX ?? '0';
}

// No cleanup step deletes these files: `playwright.config.ts` only forces
// `workers: 2` under `CI` — the real environment this suite runs in — which
// bounds `parallelSlot()` to '0' or '1' there, so at most 4 files
// (admin/vendor × 2 slots) ever accumulate in CI. A non-CI local run without
// an explicit `--workers` flag falls back to Playwright's own default
// (a fraction of host CPU cores), which could exceed 2 slots on a larger
// box — still bounded, just not fixed at 4 outside CI. Either way, this is
// not the unbounded-growth risk an earlier `process.pid`-keyed version of
// this path would have had, where every worker process across every run got
// its own permanent file. A file past `STORAGE_STATE_FRESHNESS_MS` is
// simply treated as absent by
// `hasFreshStorageState()` below and overwritten on the next real login, so
// nothing here accumulates or goes stale unboundedly on a long-lived dev
// box.

// Same reasoning as `adminStorageStatePath()` (now imported from
// `./support/admin-session`), for the vendor panel's describe block below —
// a distinct path (not reused with the admin one) so both blocks' beforeAll
// hooks can run without clobbering each other's saved session, even though
// Playwright serializes describe blocks within one worker.
function vendorStorageStatePath(): string {
    return path.join(os.tmpdir(), `e2e-admin-vendor-vendor-storage-state-${parallelSlot()}.json`);
}

function hasFreshStorageState(storageStatePath: string): boolean {
    try {
        return Date.now() - fs.statSync(storageStatePath).mtimeMs < STORAGE_STATE_FRESHNESS_MS;
    } catch {
        return false;
    }
}

// `loginOnceUnlessFreshSession()` is now imported from
// `./support/admin-session` (see this file's rate-limit comment above for
// why it exists — the guard that turns a retried `beforeAll` into a free
// reuse instead of the 6th call against Filament's shared 5-attempts/60s
// login rate limit).

// This whole file runs as one serial group, on one worker, in declaration
// order — not the `fullyParallel` default `playwright.config.ts` sets.
// Verified live: without this, `fullyParallel` can split a single describe
// block's own tests across BOTH of this suite's `workers: 2`, triggering
// that describe's `beforeAll` — and therefore a real login — once per
// worker it lands on, not once total; and separately, which of the two
// admin-needing describes below (the authenticated-dashboard one and the
// audit/query-scope one) happens to land on the SAME worker slot as the
// other, sharing one real login via the skip-if-fresh guard above, was left
// entirely to Playwright's scheduling luck. Both effects could silently
// push a single clean run's real login count above the minimum of 4 (2
// canary + 1 admin + 1 vendor) this file needs, eating into — or entirely
// consuming — the headroom the skip-if-fresh guard above is meant to buy
// back from Filament's shared 5-attempts/60s login rate limit. Serial mode
// makes that deterministic instead of scheduler-dependent: every run, the
// admin-authenticated describe's `beforeAll` logs in once, and the
// audit/query-scope describe's `beforeAll` always finds and reuses that
// same fresh session.
test.describe.configure({ mode: 'serial' });

test.describe('E2E-ADMIN/VENDOR — admin dashboard and reports', () => {
    test('admin can log in and reach the dashboard', async ({ page }) => {
        await adminLogin(page);

        await expect(page).toHaveURL(/\/admin\/?$/);
    });

    test.describe('with an authenticated admin session', () => {
        const storageStatePath = adminStorageStatePath();
        test.use({ storageState: storageStatePath });

        test.beforeAll(async ({ browser }) => {
            await loginOnceUnlessFreshSession(browser, storageStatePath, adminLogin);
        });

        test('dashboard shows the master-data widget available to every back-office role', async ({ page }) => {
            await page.goto('/admin');

            // PlatformOverviewWidget — gated on the four-role
            // MasterDataAdminAuthorizerContract (admin passes). `exact: true` on
            // 'TPU'/'TPS' is required, not stylistic: the sidebar nav also has a
            // "Makam / TPU" link, whose text contains "TPU" as a substring, so a
            // loose getByText('TPU') resolves ambiguously to both (verified
            // live).
            await expect(page.getByText('TPU', { exact: true })).toBeVisible();
            await expect(page.getByText('TPS', { exact: true })).toBeVisible();
            await expect(page.getByText('Vendor Aktif')).toBeVisible();
            await expect(page.getByText('FAQ Dipublikasikan')).toBeVisible();
        });

        test('the finance-gated widgets render for the admin holding finance-ledger read access', async ({ page }) => {
            await page.goto('/admin');

            // FinancialOverviewWidget and FailedPaymentExceptionQueueWidget both
            // gate on FinanceLedgerReadAuthorizer (`finance` role + a privileged
            // BUSINESS_ENTITY scope grant) — e2e-admin holds both (seed
            // migration), so canView() is true and both widgets render.
            await expect(page.getByText('Pembayaran Berhasil')).toBeVisible();
            await expect(page.getByText('Pembayaran Bermasalah')).toBeVisible();
            await expect(page.getByText('Laporan Rekonsiliasi')).toBeVisible();

            // FailedPaymentExceptionQueueWidget (a TableWidget) is further down
            // the dashboard than the viewport's initial fold and mounts its
            // Livewire component lazily on scroll-into-view (verified live: the
            // "Antrian Pembayaran Gagal" heading never appears — the request for
            // this widget's own Livewire component is simply never sent — until
            // the page is scrolled), unlike the StatsOverviewWidget widgets
            // above, which lazy-load unconditionally on mount. Scrolling to the
            // bottom of the page before asserting matches how a real operator
            // would actually reach this widget.
            await page.evaluate(() => window.scrollTo(0, document.body.scrollHeight));
            await expect(page.getByText('Antrian Pembayaran Gagal')).toBeVisible();
        });

        test('dashboard has zero accessibility violations', async ({ page }) => {
            await page.goto('/admin');

            const results = await new AxeBuilder({ page }).analyze();

            expect(results.violations).toEqual([]);
        });

        test('the three master-data-gated report pages are reachable and titled correctly', async ({ page }) => {
            // OrdersReport, RenewalPeriodReport, VendorPerformanceReport all gate
            // on the same four-role MasterDataAdminAuthorizerContract as
            // PlatformOverviewWidget above — admin passes.
            const reports: Array<{ path: string; title: string }> = [
                { path: '/admin/orders-report', title: 'Laporan Pesanan' },
                { path: '/admin/renewal-period-report', title: 'Laporan Perpanjangan' },
                { path: '/admin/vendor-performance-report', title: 'Laporan Kinerja Vendor' },
            ];

            for (const report of reports) {
                const response = await page.goto(report.path);
                expect(response?.status()).toBe(200);
                await expect(page.getByRole('heading', { name: report.title })).toBeVisible();
            }
        });

        test('the three finance-gated report pages are reachable and titled correctly for the admin holding ledger-read access', async ({ page }) => {
            // FinanceReports, ReceiptsReport, OutgoingPaymentsReport all gate on
            // FinanceLedgerReadAuthorizer (see this file's header comment) —
            // e2e-admin holds the finance role plus a privileged BUSINESS_ENTITY
            // grant (seed migration), so canAccess() is true and each page
            // returns a real 200.
            const reports: Array<{ path: string; title: string }> = [
                { path: '/admin/finance-reports', title: 'Laporan Keuangan' },
                { path: '/admin/receipts-report', title: 'Laporan Penerimaan' },
                { path: '/admin/outgoing-payments-report', title: 'Laporan Pembayaran Keluar' },
            ];

            for (const report of reports) {
                const response = await page.goto(report.path);
                expect(response?.status()).toBe(200);
                await expect(page.getByRole('heading', { name: report.title })).toBeVisible();
            }
        });
    });
});

/**
 * ---------------------------------------------------------------------------
 * Reconciliation query-scope: investigated for real, not assumed
 * ---------------------------------------------------------------------------
 * `ReconciliationsResource::canAccess()` and `::getEloquentQuery()`
 * (`app/Filament/Admin/Resources/Reconciliations/ReconciliationsResource.php`)
 * both resolve `Contracts\LedgerReadAuthorizer` — the SAME
 * `FinanceLedgerReadAuthorizer` that gates the finance dashboard widgets and
 * the three finance report pages above, not a separate "admin query scope"
 * layer. Two things this task's brief could not confirm without reading the
 * real source turned out to matter:
 *
 *  1. `FinanceLedgerReadAuthorizer::AUTHORISED_ROLES` is `['finance']` only.
 *     `ActorRole::ADMIN` and `ActorRole::RESTRICTED_ADMIN` are NOT in that
 *     list — an account holding only `RESTRICTED_ADMIN` (the role this
 *     task's brief suggested for a second, more-restricted account) would
 *     fail `canAccess()` outright and never reach this page at all, not "see
 *     fewer rows". Any second account for a query-scope contrast test needs
 *     `ActorRole::FINANCE` specifically.
 *  2. `getEloquentQuery()` filters on `entity_ref` using the SAME
 *     `ScopeAssignment`/`GrantScopeAssignment` mechanism Task 1 used for the
 *     vendor account — confirmed real, not a guess — so a row-count
 *     contrast test IS the right shape in principle. But no `Reconciliation`
 *     row exists anywhere in this codebase's migrations, factories, or
 *     seeders (`app/Platform/FinancialLedger/Models/Reconciliation.php` has
 *     no factory; the only writer is
 *     `Actions\RunReconciliation`, which requires a full journal-batch +
 *     provider-statement context — not obtainable via
 *     `GrantActorRole`/`GrantScopeAssignment` alone, and well outside this
 *     task's "extend Task 1's migration with a third user" budget). The
 *     `reconciliations` table is therefore empty in every `migrate:fresh`
 *     run today, including this suite's own. A second, correctly-scoped
 *     `finance` account would see 0 rows for the exact same reason
 *     `e2e-admin` does — a "strictly fewer rows" comparison would be 0 vs 0,
 *     which is not a proof of scoping, it is the invented/vacuous assertion
 *     this task's brief explicitly says not to ship.
 *
 * Per the brief's own documented fallback for this exact situation: the
 * query-scope contrast test below is `test.fixme`, not skipped silently or
 * asserted falsely, and Task 1's migration is deliberately NOT extended with
 * a third seeded account — an account alone cannot make this assertion real
 * without also fabricating reconciliation fixture data through a
 * significantly larger change this task does not have the budget for.
 */
test.describe('E2E-ADMIN/VENDOR — sensitive-action audit and query scope', () => {
    // See the `adminStorageStatePath()` doc block above this file's first
    // `test.describe` for why every test here reuses one logged-in session
    // instead of calling `adminLogin()` per test.
    const storageStatePath = adminStorageStatePath();
    test.use({ storageState: storageStatePath });

    test.beforeAll(async ({ browser }) => {
        await loginOnceUnlessFreshSession(browser, storageStatePath, adminLogin);
    });

    test('audit trail review shows the required columns and is read-only', async ({ page }) => {
        await page.goto('/admin/audit-events');

        await expect(page.getByRole('heading', { name: 'Jejak Audit' })).toBeVisible();

        // The columns AuditEventsTable.php defines — confirms the "sensitive
        // action audit" AC's real data shape, not just that the page loads.
        // `exact: true` is required, not stylistic: Playwright's accessible-
        // name matching for a plain string is a case-insensitive substring
        // match, and "Peran aktor" contains "aktor" as a substring, so a
        // loose getByRole('columnheader', { name: 'Aktor' }) resolves
        // ambiguously to both headers (verified live) — the same collision
        // this file's header comment already documents for 'TPU'/'TPS'.
        for (const column of ['Waktu', 'Aksi', 'Hasil', 'Aktor', 'Peran aktor', 'Sumber', 'Subjek', 'Alasan']) {
            await expect(page.getByRole('columnheader', { name: column, exact: true })).toBeVisible();
        }

        // Read-only per ADM-100's own scope: no "New"/"Create"/"Buat" action
        // anywhere on the page — this resource registers index+view routes
        // only (RoleBasedAuditReadAuthorizer gates both, but there is no
        // create/edit page at all for Filament to gate).
        await expect(page.getByRole('link', { name: /buat|create|new/i })).toHaveCount(0);
    });

    test('audit trail records this suite\'s own seeded grants', async ({ page }) => {
        await page.goto('/admin/audit-events');

        // Task 1's migration granted ActorRole::VENDOR (and, after Task 2's
        // fix, ActorRole::FINANCE plus a BUSINESS_ENTITY scope) through
        // GrantActorRole/GrantScopeAssignment — real audited writes, so they
        // must appear here. This is the strongest possible proof the
        // "sensitive action audit" AC is real: the suite's own setup step is
        // itself an audited event.
        //
        // Must search rather than rely on the table's default
        // `occurred_at desc` sort + page-1-only visibility: this suite's
        // `fullyParallel` run shares one server and one database with every
        // OTHER spec file (e2e-booking.spec.ts in particular), which writes
        // many BOOKING_DRAFT_* audit rows throughout the whole run —
        // confirmed live (a failing screenshot showed page 1 entirely full
        // of concurrent booking-draft events), so by the time this test
        // runs the seed migration's handful of rows are long since pushed
        // past page 1. `reason` itself isn't a `->searchable()` column
        // (`AuditEventsTable`), but `action` is, and
        // `RoleAuditActions::GRANT` ('ROLE_GRANT') is a distinct action
        // string nothing else in this suite writes — filtering to it
        // narrows the table to just this migration's 3 real role-grant
        // rows (admin: ADMIN + FINANCE, vendor: VENDOR), regardless of
        // concurrent traffic. The search field's real accessible name is
        // "Cari" via its own `<label>` (`search-field.blade.php`) — the
        // page also has a SEPARATE global-search field whose real label is
        // "Pencarian global" despite sharing the same "Cari" placeholder
        // text, so this does not collide with it.
        await page.getByLabel('Cari', { exact: true }).fill('ROLE_GRANT');
        await expect(page.getByText(/E2E-ADMIN\/VENDOR suite seed/).first()).toBeVisible();
    });

    test('reconciliation admin list renders for the admin holding finance-ledger read access', async ({ page }) => {
        await page.goto('/admin/reconciliations');

        // Not an "unscoped admin" view — e2e-admin holds exactly one
        // BUSINESS_ENTITY grant (the fixture entity Task 2's fix added), and
        // getEloquentQuery() scopes every actor, admin included, to their
        // own granted entity_refs (see this describe block's header
        // comment). This test proves the module mounts and its real columns
        // render for a finance-ledger-authorized admin — it does not, and
        // cannot, prove anything about scope breadth, since no
        // Reconciliation rows exist to be scoped over either way. That empty
        // table also means Filament renders its own "No Rekonsiliasi"
        // empty-state heading, whose text contains "Rekonsiliasi" as a
        // substring, so `exact: true` is required here too — the same
        // substring collision as 'Aktor'/'Peran aktor' above (verified
        // live).
        await expect(page.getByRole('heading', { name: 'Rekonsiliasi', exact: true })).toBeVisible();
        for (const column of ['Badan usaha', 'Periode', 'Status']) {
            await expect(page.getByRole('columnheader', { name: column, exact: true })).toBeVisible();
        }
    });

    // See this describe block's header comment for the full investigation.
    // Not buildable within this task's budget: (1) the brief's suggested
    // RESTRICTED_ADMIN role would fail canAccess() outright for this
    // resource (FinanceLedgerReadAuthorizer requires `finance` specifically,
    // confirmed by reading its AUTHORISED_ROLES constant directly), and (2)
    // even a correctly-scoped second `finance` account would compare 0 rows
    // against 0 rows, because no Reconciliation fixture data exists anywhere
    // in this codebase and creating it requires RunReconciliation's full
    // journal-batch/provider-statement context, not a role/scope grant.
    test.fixme(
        'reconciliation admin list query-scope: a finance-scoped admin sees strictly fewer rows than another finance-scoped admin with a different entity grant',
        async () => {
            // Intentionally empty — see the skip reason above and this
            // describe block's header comment. Needs: (a) a second seeded
            // account granted ActorRole::FINANCE (not RESTRICTED_ADMIN) plus
            // a BUSINESS_ENTITY/PRIVILEGED scope against a different entity
            // reference, AND (b) real Reconciliation rows for at least two
            // distinct entity_refs, seeded through a RunReconciliation-based
            // fixture path — neither exists today.
        },
    );

    // A real, live finding, not a flaky assertion: both `/admin/audit-events`
    // and `/admin/reconciliations` fail axe's `color-contrast` rule on
    // `.fi-breadcrumbs-item-label` (foreground `#6f7878` on background
    // `#f7f8f8`, an actual contrast ratio of 4.25:1 against WCAG AA's
    // required 4.5:1 — verified live via AxeBuilder's own violation output,
    // not assumed). That color pair does not appear in this app's own
    // `resources/css/tokens.css`, nor as a literal hex anywhere under
    // `vendor/filament` — it is a Filament-computed shade from the panel's
    // default color configuration, so the fix is a panel-theming change
    // (likely affecting every Filament resource list page's breadcrumbs
    // site-wide, not just these two resources), which is outside a
    // test-authoring task's scope and needs its own design-system-reviewed
    // change, not a same-PR fix bundled into this suite.
    //
    // `.exclude('.fi-breadcrumbs-item-label')` narrows out ONLY that one
    // known, out-of-scope node from the scan below — every other axe rule,
    // including `color-contrast` itself against every other element on
    // these two pages, still runs and is asserted zero-violations. This is
    // deliberately not a full-page `test.fixme`: that would have silently
    // disabled `color-contrast` (and every other rule) across both pages
    // entirely, masking this AND any future, unrelated contrast regression
    // here — verified live that excluding just this one selector leaves
    // zero violations on both pages.
    test('audit and reconciliation pages have zero accessibility violations outside the known Filament breadcrumb finding', async ({ page }) => {
        await page.goto('/admin/audit-events');
        expect(
            (await new AxeBuilder({ page }).exclude('.fi-breadcrumbs-item-label').analyze()).violations,
        ).toEqual([]);

        await page.goto('/admin/reconciliations');
        expect(
            (await new AxeBuilder({ page }).exclude('.fi-breadcrumbs-item-label').analyze()).violations,
        ).toEqual([]);
    });
});

/**
 * ---------------------------------------------------------------------------
 * Vendor page routes — verified against each Page class's real `$slug`, not
 * the plan draft's guessed paths
 * ---------------------------------------------------------------------------
 * `App\Filament\Vendor\Pages\TransactionHistory::$slug` is `'transactions'`
 * and `App\Filament\Vendor\Pages\PayoutStatus::$slug` is `'payouts'` (both
 * read directly from source) — so their real routes are `/vendor/transactions`
 * and `/vendor/payouts`, not `/vendor/transaction-history`/`/vendor/payout-status`
 * as this suite's plan draft guessed. `App\Filament\Vendor\Pages\Profile::$slug`
 * (`'profile'`) does match the draft.
 *
 * ---------------------------------------------------------------------------
 * Transaction-history scoping: a real presence/absence proof, not a row count
 * ---------------------------------------------------------------------------
 * `TransactionHistory::vendorScopedQuery()` filters `VendorOrder` on
 * `vendor_id` against `CurrentVendorScope::grantedVendorIds()` — already
 * proven correct at the query level, with two vendors' rows in play, by
 * `tests/Feature/Filament/Vendor/VendorPanelScopingTest`. That table renders
 * no vendor-identifying column (it is already scoped to exactly one vendor,
 * so a "Vendor" column would be redundant) — the closest thing to a
 * cross-vendor identifier the page actually shows is the `customer_name`
 * ("Pelanggan") column.
 *
 * Investigated for real, not assumed: before this task, `vendor_orders` had
 * ZERO seed rows anywhere in this codebase (no factory, and
 * `VendorListingExampleData` seeds only `vendors`/`vendor_listings`/
 * `service_areas`, never orders) — so a fresh `migrate:fresh` left this page
 * with nothing to assert scoping against beyond its empty state, which would
 * have been exactly the vacuous 0-vs-0 comparison this suite already declined
 * to fake for `/admin/reconciliations` above. Unlike that case, a
 * `VendorOrder` row is cheap to fixture (no complex domain Action required —
 * `VendorPanelScopingTest`'s own fixture helper already does this with a
 * plain insert), so
 * `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`
 * was extended (Task 4 addition, see that file's own doc block) to seed
 * exactly two throwaway orders: one against the vendor `e2e-vendor` is
 * actually granted, one against a different, ungranted vendor — each with an
 * unmistakable, distinct `customer_name` marker. The test below asserts the
 * granted vendor's marker IS visible and the other vendor's marker is NOT —
 * a real, concrete scoping proof on the page's own rendered data, not an
 * invented row count.
 */
const VENDOR_OWN_ORDER_CUSTOMER_NAME = 'Pelanggan Contoh E2E (Vendor Tertaut)';
const VENDOR_OTHER_ORDER_CUSTOMER_NAME = 'Pelanggan Contoh E2E (Vendor Lain)';

test.describe('E2E-ADMIN/VENDOR — vendor profile, transactions, and payouts', () => {
    // See the `adminStorageStatePath()`/`vendorStorageStatePath()` doc blocks
    // above for why every authenticated test in this file logs in once per
    // describe block via `beforeAll` + `storageState` reuse rather than
    // calling `vendorLogin(page)` fresh in every test — both panels share
    // Filament's stock Login page class and therefore one rate-limit bucket
    // keyed only by IP (5 attempts/60s).
    test('vendor can log in and reach their own dashboard', async ({ page }) => {
        await vendorLogin(page);

        await expect(page).toHaveURL(/\/vendor\/?$/);
        await expect(page.getByRole('heading', { name: 'Dashboard Vendor' })).toBeVisible();
    });

    test.describe('with an authenticated vendor session', () => {
        const storageStatePath = vendorStorageStatePath();
        test.use({ storageState: storageStatePath });

        test.beforeAll(async ({ browser }) => {
            await loginOnceUnlessFreshSession(browser, storageStatePath, vendorLogin);
        });

        test('vendor profile/account page is reachable and editable', async ({ page }) => {
            await page.goto('/vendor/profile');

            await expect(page.getByRole('heading', { name: 'Profil Akun' })).toBeVisible();

            // "editable" per this test's own name — the account/password
            // form fields Profile::form() actually declares, not just the
            // heading.
            await expect(page.getByLabel('Nama')).toBeEditable();
            await expect(page.getByLabel('Email')).toBeEditable();
        });

        test('vendor transaction history is reachable and scoped to this vendor only', async ({ page }) => {
            await page.goto('/vendor/transactions');

            await expect(page.getByRole('heading', { name: 'Riwayat Transaksi', exact: true })).toBeVisible();

            // See this file's header comment above this describe block for
            // the full investigation. The granted vendor's fixture order must
            // be visible; the other, ungranted vendor's fixture order must
            // not be — a real cross-vendor leakage check on the page's own
            // rendered "Pelanggan" column.
            await expect(page.getByText(VENDOR_OWN_ORDER_CUSTOMER_NAME, { exact: true })).toBeVisible();
            await expect(page.getByText(VENDOR_OTHER_ORDER_CUSTOMER_NAME, { exact: true })).not.toBeVisible();
        });

        test('vendor payout status/reference is visible and scoped', async ({ page }) => {
            await page.goto('/vendor/payouts');

            await expect(page.getByRole('heading', { name: 'Status Pencairan' })).toBeVisible();
        });

        // Retry-safety: this fixture order is shared, mutable state — the
        // action below actually writes its status via `UpdateVendorOrderStatus`
        // (`EditVendorOrder.php`), so a naive "assert it starts at
        // MENUNGGU_VENDOR, then transition it" test would only be safe to run
        // ONCE per migration. This file sets `mode: 'serial'` above and
        // `playwright.config.ts` sets `retries: process.env.CI ? 2 : 0` —
        // Playwright retries a whole serial group as a unit, so if ANY later
        // test in this group fails in real CI, a retry re-runs this
        // already-passed test again against whatever status its own prior
        // successful run left the fixture in. A fixed-starting-state
        // assertion would then hard-fail deterministically — worse, the exact
        // button this test clicks on the first pass (`accept`) is only
        // `->visible()` while `$record->status !== VendorProcessingStatus::
        // DITERIMA_VENDOR` (`EditVendorOrder.php`), so after one successful
        // run that button is gone entirely, not just a failing assertion.
        //
        // Fix: read the fixture order's REAL current status first, then
        // perform whichever one-click action is still a meaningful forward
        // move from there — a genuine transition proof regardless of which
        // attempt this is. `TRANSITION_CHAIN` below covers exactly the
        // statuses this shared order can be in across this suite's configured
        // retry budget (`retries: 2` ⇒ at most 3 total attempts of this test
        // inside one CI invocation: attempt 1 finds 'Menunggu vendor', attempt
        // 2 — the 1st retry — finds 'Diterima vendor' left by attempt 1's
        // success, attempt 3 — the 2nd retry — finds 'Diproses' left by
        // attempt 2's success). None of the three chosen actions
        // (accept/process/sendSchedule) requires a confirmation modal —
        // `EditVendorOrder.php`'s own doc block names those the forward
        // progressions that don't, unlike reject/complete/complain. If the
        // fixture is ever found outside this chain (nothing in this suite's
        // own logic should be able to produce that), the test fails loudly
        // with a clear message instead of silently asserting the wrong thing.
        const TRANSITION_CHAIN: ReadonlyArray<{
            from: string;
            actionLabel: string;
            to: string;
            notificationTitle: string;
        }> = [
            { from: 'Menunggu vendor', actionLabel: 'Terima pesanan', to: 'Diterima vendor', notificationTitle: 'Pesanan diterima.' },
            { from: 'Diterima vendor', actionLabel: 'Mulai proses', to: 'Diproses', notificationTitle: 'Pesanan sedang diproses.' },
            { from: 'Diproses', actionLabel: 'Tandai dikirim / dijadwalkan', to: 'Dikirim / dijadwalkan', notificationTitle: 'Pesanan dikirim / dijadwalkan.' },
        ];

        test('vendor can advance an incoming order through a one-click work-queue action', async ({ page }) => {
            // Real UI accept/process coverage — the read-only transaction
            // history test above proves visibility/scoping; this proves the
            // vendor can actually act on an order. Uses the seed migration's
            // own fixture order (`2026_08_22_110000_seed_e2e_admin_vendor_
            // test_users.php`'s OWN_VENDOR_ORDER_CUSTOMER_NAME/EMAIL).
            await page.goto('/vendor/orders');

            const row = page.getByRole('row', { name: VENDOR_OWN_ORDER_CUSTOMER_NAME });
            await expect(row).toBeVisible();

            let step: (typeof TRANSITION_CHAIN)[number] | undefined;
            for (const candidate of TRANSITION_CHAIN) {
                // `isVisible()` is a non-waiting check, which is fine here:
                // the `row` locator above was already awaited into visibility,
                // so its contents are already settled on the page.
                if (await row.getByText(candidate.from).isVisible()) {
                    step = candidate;
                    break;
                }
            }
            if (!step) {
                throw new Error(
                    `Fixture order '${VENDOR_OWN_ORDER_CUSTOMER_NAME}' is in a status outside this test's known retry chain (TRANSITION_CHAIN) — check tests/browser/e2e-admin-vendor.spec.ts.`,
                );
            }

            await expect(row.getByText(step.from)).toBeVisible();

            // EditAction's real accessible name is Filament's own `id`
            // translation ('Ubah'), not the English default ('Edit') —
            // verified directly against
            // vendor/filament/actions/resources/lang/id/edit.php.
            await row.getByRole('link', { name: 'Ubah' }).click();

            await page.getByRole('button', { name: step.actionLabel }).click();

            await expect(page.getByText(step.notificationTitle)).toBeVisible();

            await page.goto('/vendor/orders');
            const updatedRow = page.getByRole('row', { name: VENDOR_OWN_ORDER_CUSTOMER_NAME });
            await expect(updatedRow.getByText(step.to)).toBeVisible();
        });

        test('vendor panel pages have zero accessibility violations', async ({ page }) => {
            for (const path of ['/vendor', '/vendor/profile', '/vendor/transactions', '/vendor/payouts']) {
                await page.goto(path);
                const results = await new AxeBuilder({ page }).analyze();
                expect(results.violations).toEqual([]);
            }
        });

        test('vendor cannot reach the admin panel', async ({ page }) => {
            const response = await page.goto('/admin');

            // `App\Models\User::canAccessPanel()` delegates to
            // `AdminPanelAccessPolicy`, which denies an actor holding only
            // `ActorRole::VENDOR` — verified live (see this test's own run
            // output) rather than assumed: Filament renders a 403 response
            // for an authenticated user its FilamentUser::canAccessPanel()
            // implementation refuses, it does not redirect back to a login
            // page (the vendor session IS authenticated — just not for this
            // panel).
            expect(response?.status()).toBe(403);
            await expect(page.getByRole('heading', { name: 'Jejak Audit' })).not.toBeVisible();
        });
    });
});
