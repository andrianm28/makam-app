import { expect, test, type Page } from '@playwright/test';

/**
 * Browser-level proof that `RenewalOrderResource`'s List/View admin pages
 * correctly render an externally-marked renewal — the real gap
 * `tests/browser/e2e-renewal.spec.ts` names in its own comment ("No
 * fixture ever creates a `renewals` row"). The fixture is seeded by
 * `database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php`
 * via the real `MarkExternalRenewal` Action (gated on
 * `SEED_E2E_EXTERNAL_RENEWAL=true`, the same CI-only opt-in pattern
 * `e2e-admin-vendor.spec.ts`'s own fixture uses).
 *
 * Login flow mirrors `e2e-admin-vendor.spec.ts::adminLogin()` exactly —
 * see that file's own comment block for why the label/button text is
 * Indonesian ('Alamat email' / 'Kata sandi' / 'Masuk'), not English.
 *
 * Scope note (see this suite's plan, Task 2): `MarkExternalRenewal` has no
 * Filament UI entry point in this codebase — this suite proves the
 * RESOURCE renders an externally-marked row correctly, not that the
 * marking action itself is triggerable from the UI (it isn't).
 * `MarkExternalRenewalTest.php` (Feature-level) remains the authority for
 * the action's own authorization/duplicate-prevention behavior.
 */
async function adminLogin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.getByLabel('Alamat email').fill('e2e-renewal-admin@example.test');
    await page.getByRole('textbox', { name: 'Kata sandi' }).fill('E2eRenewalAdminPassword!1');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL(/\/admin\/?$/);
    await page.waitForLoadState('networkidle');
}

test.describe('E2E-RENEWAL-EXTERNAL — admin resource renders an externally-marked renewal', () => {
    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the renewal list shows the externally-marked fixture row', async ({ page }) => {
        await page.goto('/admin/renewal-orders');

        const row = page.getByRole('row', { name: /Dibayar/ }).first();
        await expect(row).toBeVisible();
        await expect(row.getByText('external')).toBeVisible();
    });

    test('the renewal view page shows the real external-marking evidence', async ({ page }) => {
        await page.goto('/admin/renewal-orders');
        await page.getByRole('row', { name: /Dibayar/ }).first().click();

        await expect(page.getByText('Dibayar')).toBeVisible();
        await expect(page.getByText('external')).toBeVisible();
        await expect(
            page.getByText('Kuitansi pembayaran tunai kantor TPU, No. E2E-RENEWAL-0001'),
        ).toBeVisible();
        await expect(
            page.getByText('E2E-renewal browser-suite seed — pembayaran eksternal contoh, bukan data nyata.'),
        ).toBeVisible();
    });
});
