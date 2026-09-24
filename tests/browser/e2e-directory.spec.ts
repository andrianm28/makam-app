import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-DIRECTORY — FFI-clone-whole-frontend ticket 02. No dedicated
 * browser-level spec covered `/pemakaman` (listing) or `/pemakaman/{slug}`
 * (detail) before this restyle; the parent spec's Testing Decisions call
 * this out as a real gap for a visual-language change. Closes it with a
 * real axe scan on both pages, matching the pattern already established in
 * e2e-faq.spec.ts and e2e-home.spec.ts.
 */
const EXAMPLE_SLUG = 'tpu-jakarta-1';

test('the cemetery directory listing renders the restyled card grid and is accessible', async ({ page }) => {
    await page.goto('/pemakaman');

    await expect(page.getByRole('heading', { level: 1, name: 'Direktori TPU dan TPS' })).toBeVisible();

    const list = page.getByRole('list', { name: 'Daftar TPU dan TPS' });
    await expect(list.getByRole('link')).not.toHaveCount(0);

    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('a cemetery detail page renders the hero/info-blocks structure and is accessible', async ({ page }) => {
    await page.goto(`/pemakaman/${EXAMPLE_SLUG}`);

    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Alamat' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Fasilitas' })).toBeVisible();
    await expect(page.getByRole('heading', { name: 'Kisaran biaya' })).toBeVisible();

    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});
