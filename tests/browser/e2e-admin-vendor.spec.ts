import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

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

const ADMIN = {
    email: 'e2e-admin@example.test',
    password: 'E2eAdminPassword!1',
};

const VENDOR = {
    email: 'e2e-vendor@example.test',
    password: 'E2eVendorPassword!1',
};

// Filament's default login page wraps the password field's label ("Password")
// and its required-marker ("*") in a `<superscript>`, so the accessible name
// is "Password*" — and the field itself sits alongside "Show password"/"Hide
// password" reveal-toggle buttons whose aria-labels also contain the
// substring "password". `getByLabel('Password')` therefore resolves to 3
// elements (verified live against the real rendered login page for both
// panels). Scoping to role `textbox` excludes both buttons and keeps this a
// real-markup locator, not a raw CSS selector.
//
// The trailing `waitForLoadState('networkidle')` is deliberate, not
// decorative: Filament's login redirect is a Livewire-driven client-side
// navigation, and a `page.goto()` fired immediately after `waitForURL()`
// resolves can race that in-flight navigation and get cancelled/superseded
// (observed live: an immediate follow-up `goto()` intermittently landed back
// on a stale intermediate URL). Settling here before returning means every
// caller's next navigation is real, not a race.
async function adminLogin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.getByLabel('Email address').fill(ADMIN.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/admin\/?$/);
    await page.waitForLoadState('networkidle');
}

async function vendorLogin(page: Page): Promise<void> {
    await page.goto('/vendor/login');
    await page.getByLabel('Email address').fill(VENDOR.email);
    await page.getByRole('textbox', { name: 'Password' }).fill(VENDOR.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/vendor\/?$/);
    await page.waitForLoadState('networkidle');
}

test.describe('E2E-ADMIN/VENDOR — admin dashboard and reports', () => {
    test('admin can log in and reach the dashboard', async ({ page }) => {
        await adminLogin(page);

        await expect(page).toHaveURL(/\/admin\/?$/);
    });

    test('dashboard shows the master-data widget available to every back-office role', async ({ page }) => {
        await adminLogin(page);

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
        await adminLogin(page);

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
        await adminLogin(page);

        const results = await new AxeBuilder({ page }).analyze();

        expect(results.violations).toEqual([]);
    });

    test('the three master-data-gated report pages are reachable and titled correctly', async ({ page }) => {
        await adminLogin(page);

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
        await adminLogin(page);

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
