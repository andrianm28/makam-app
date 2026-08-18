import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

test('homepage renders and is accessible', async ({ page }) => {
    await page.goto('/');

    await expect(page).toHaveTitle(/Makam\.co\.id/);

    await expect(
        page.getByRole('heading', { name: 'Urus Pemakaman dengan Tenang, dalam Satu Platform' }),
    ).toBeVisible();

    const results = await new AxeBuilder({ page }).analyze();

    expect(results.violations).toEqual([]);
});
