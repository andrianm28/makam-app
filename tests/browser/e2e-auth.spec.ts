import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-AUTH — FFI-clone-whole-frontend ticket 04. `e2e-akun.spec.ts` already
 * drives `/masuk` and `/daftar` (to reach `/akun`), but only for that
 * end-to-end purpose — no spec covered real axe-core scanning or
 * keyboard-only tab-order/focus-visible verification on either page
 * before this restyle, which the parent spec's Testing Decisions call out
 * as a real gap for a form-shell visual change (focus order and
 * error-message association are a genuine risk area here). Closes that
 * gap with a full axe scan plus real keyboard-only reachability on the
 * login form, matching the axe-core pattern already established in
 * e2e-home.spec.ts and the keyboard/focus pattern already established in
 * e2e-a11y-interaction.spec.ts.
 */
test('the login page has no axe-core violations', async ({ page }) => {
    await page.goto('/masuk');
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('the register page has no axe-core violations', async ({ page }) => {
    await page.goto('/daftar');
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('the login form is fully reachable by keyboard with correct label association and sensible focus order', async ({ page }) => {
    await page.goto('/masuk');

    // Email and password are required fields: <x-mk.field>'s own label
    // markup (field.blade.php) renders a visible "*" plus an sr-only
    // "(wajib diisi)" span for any required field, so their real
    // accessible name is "Email * (wajib diisi)" / "Kata Sandi *
    // (wajib diisi)" — getByLabel('Email', { exact: true }) can never
    // match that. Locate by id instead (field.blade.php defaults `id`
    // to the field's `name` when no explicit `id` prop is passed, which
    // is the case here), and verify the label association directly via
    // `for`/`id` rather than through getByLabel's accessible-name match.
    const email = page.locator('#email');
    const password = page.locator('#password');
    // "Ingat saya" (remember) is not a required field, so its label has
    // no extra marker — its accessible name genuinely is "Ingat saya",
    // and getByLabel works correctly here.
    const remember = page.getByLabel('Ingat saya', { exact: true });
    const submit = page.getByRole('button', { name: 'Masuk', exact: true });

    await expect(page.locator('label[for="email"]')).toContainText('Email');
    await expect(page.locator('label[for="password"]')).toContainText('Kata Sandi');

    await email.focus();
    await expect(email).toBeFocused();

    const emailBoxShadow = await email.evaluate((el) => getComputedStyle(el).boxShadow);
    expect(emailBoxShadow).not.toBe('none');

    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(remember).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(submit).toBeFocused();
});
