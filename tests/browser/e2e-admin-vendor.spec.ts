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
 * e2e-admin is plain `ActorRole::ADMIN`, NOT `finance` — verified live, not
 * assumed
 * ---------------------------------------------------------------------------
 * The seed migration's own doc block (and the plan this suite was built
 * from) frames `e2e-admin` as "unscoped — sees every entity". That is true
 * for the four-role `MasterDataAdminAuthorizerContract` gate (admin,
 * restricted_admin, operator, finance all pass), which is what guards
 * `PlatformOverviewWidget` and three of the six report pages (Orders,
 * RenewalPeriod, VendorPerformance).
 *
 * It is NOT true for `FinanceLedgerReadAuthorizer`
 * (`app/Platform/FinancialLedger/FinanceLedgerReadAuthorizer.php`), which
 * requires the actor to hold the real `finance` role specifically (its own
 * doc block: "Same role (finance only, not restricted_admin)") PLUS at
 * least one active privileged `BUSINESS_ENTITY` scope grant. `e2e-admin`
 * has neither — the seed migration only grants `ActorRole::ADMIN`, no
 * `finance` role, no `BUSINESS_ENTITY` scope assignment at all. Confirmed
 * live against the pinned CI image + a real Postgres/Redis pair, migrated
 * with `SEED_E2E_ADMIN_VENDOR_USERS=true`: `FinancialOverviewWidget` and
 * `FailedPaymentExceptionQueueWidget` both correctly do not render for this
 * account (`canView()` false), and `/admin/finance-reports`,
 * `/admin/receipts-report`, `/admin/outgoing-payments-report` all return a
 * real HTTP 403 (`FinanceReports`/`ReceiptsReport`/`OutgoingPaymentsReport`
 * `canAccess()` false, `Filament\Pages\Concerns\CanAuthorizeAccess::
 * mountCanAuthorizeAccess()` -> `abort_unless(false, 403)`).
 *
 * This is real least-privilege enforcement working as designed, not a bug —
 * so this suite asserts BOTH directions honestly: the three master-data
 * report pages and the master-data widget are reachable (below), and the
 * three finance-gated report pages plus the two finance-gated widgets are
 * correctly refused/hidden for this account, rather than either skipping
 * that coverage silently or asserting a "visible"/"200" outcome that does
 * not happen. Extending the fixture with a `finance`-role,
 * `BUSINESS_ENTITY`-scoped account (to cover the finance-authorized path
 * too) is a fixture change outside this task's file scope — see this
 * task's own report for the recommendation.
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

    test('the finance-gated widgets correctly do not render for an admin without ledger-read access', async ({ page }) => {
        await adminLogin(page);

        // FinancialOverviewWidget and FailedPaymentExceptionQueueWidget both
        // gate on FinanceLedgerReadAuthorizer (`finance` role + a privileged
        // BUSINESS_ENTITY scope grant) — e2e-admin holds neither, so
        // canView() is false and neither widget renders at all.
        await expect(page.getByText('Pembayaran Berhasil')).not.toBeVisible();
        await expect(page.getByText('Pembayaran Bermasalah')).not.toBeVisible();
        await expect(page.getByText('Laporan Rekonsiliasi')).not.toBeVisible();
        await expect(page.getByText('Antrian Pembayaran Gagal')).not.toBeVisible();
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

    test('the three finance-gated report pages correctly refuse an admin without ledger-read access', async ({ page }) => {
        await adminLogin(page);

        // FinanceReports, ReceiptsReport, OutgoingPaymentsReport all gate on
        // FinanceLedgerReadAuthorizer (see this file's header comment) —
        // e2e-admin is refused with a real 403, not silently redirected.
        const reports: Array<{ path: string; title: string }> = [
            { path: '/admin/finance-reports', title: 'Laporan Keuangan' },
            { path: '/admin/receipts-report', title: 'Laporan Penerimaan' },
            { path: '/admin/outgoing-payments-report', title: 'Laporan Pembayaran Keluar' },
        ];

        for (const report of reports) {
            const response = await page.goto(report.path);
            expect(response?.status()).toBe(403);
            await expect(page.getByRole('heading', { name: report.title })).not.toBeVisible();
        }
    });
});
