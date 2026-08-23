import { expect, test } from '@playwright/test';
import { adminStorageStatePath, adminLogin, loginOnceUnlessFreshSession } from './support/admin-session';

/**
 * Browser-level proof that `RenewalOrderResource`'s List/View admin pages
 * correctly render an externally-marked renewal — the real gap
 * `tests/browser/e2e-renewal.spec.ts` names in its own comment ("No
 * fixture ever creates a `renewals` row").  The fixture is seeded by
 * `database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php`
 * via the real `MarkExternalRenewal` Action (gated on
 * `SEED_E2E_EXTERNAL_RENEWAL=true`) — that migration's own fixture admin
 * (`e2e-renewal-admin@example.test`, with the PRIVILEGED cemetery grant
 * `MarkExternalRenewal` requires) is a server-side actor only, unrelated
 * to this file's browser login.
 *
 * Reuses `e2e-admin-vendor.spec.ts`'s admin session (via `./support/
 * admin-session`) rather than logging in its own account — see that
 * module's doc block for why: Filament's login rate limit is a single
 * shared 5-attempts/60s IP-keyed bucket across the whole Playwright run,
 * and `RenewalOrderResource`'s access gate (`MasterDataAdminAuthorizer`)
 * is role-only, so the already-seeded `e2e-admin` account can view this
 * resource without needing any renewal-specific grant.
 *
 * Scope note (see this suite's plan, Task 2): `MarkExternalRenewal` has no
 * Filament UI entry point in this codebase — this suite proves the
 * RESOURCE renders an externally-marked row correctly, not that the
 * marking action itself is triggerable from the UI (it isn't).
 * `MarkExternalRenewalTest.php` (Feature-level) remains the authority for
 * the action's own authorization/duplicate-prevention behavior.
 */
test.describe.configure({ mode: 'serial' });

test.describe('E2E-RENEWAL-EXTERNAL — admin resource renders an externally-marked renewal', () => {
    test.beforeAll(async ({ browser }) => {
        await loginOnceUnlessFreshSession(browser, adminStorageStatePath(), adminLogin);
    });

    test.use({ storageState: adminStorageStatePath() });

    test('the renewal list shows the externally-marked fixture row and navigating to it reaches the view page', async ({ page }) => {
        await page.goto('/admin/renewal-orders');

        const row = page.getByRole('row', { name: /Dibayar/ }).first();
        await expect(row).toBeVisible();
        await expect(row.getByText('external')).toBeVisible();

        await row.getByRole('link', { name: 'Lihat' }).click();
        await expect(page).toHaveURL(/\/admin\/renewal-orders\/[^/]+$/);
    });

    test('the renewal view page shows the real external-marking evidence', async ({ page }) => {
        await page.goto('/admin/renewal-orders');
        await page.getByRole('row', { name: /Dibayar/ }).first().getByRole('link', { name: 'Lihat' }).click();
        await expect(page).toHaveURL(/\/admin\/renewal-orders\/[^/]+$/);

        await expect(page.getByText('Dibayar', { exact: true })).toBeVisible();
        await expect(page.getByText('external')).toBeVisible();
        await expect(
            page.getByText('Kuitansi pembayaran tunai kantor TPU, No. E2E-RENEWAL-0001'),
        ).toBeVisible();
        await expect(
            page.getByText('E2E-renewal browser-suite seed — pembayaran eksternal contoh, bukan data nyata.'),
        ).toBeVisible();
    });
});
