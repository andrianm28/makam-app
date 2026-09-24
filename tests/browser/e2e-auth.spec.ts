import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-AUTH — FFI-clone-whole-frontend ticket 04. No dedicated browser-level
 * spec covered `/masuk` or `/daftar` before this restyle; the parent spec's
 * Testing Decisions call this out as a real gap for a form-shell visual
 * change (focus order and error-message association are a genuine risk
 * area here). Closes it with a full axe scan plus real keyboard-only
 * reachability on the login form, matching the axe-core pattern already
 * established in e2e-home.spec.ts and the keyboard/focus pattern already
 * established in e2e-a11y-interaction.spec.ts.
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

    // getByLabel only succeeds if the rendered <label> genuinely associates
    // with its <input> (via `for`/`id` or wrapping) — a coincidental text
    // match elsewhere on the page would not satisfy this locator.
    const email = page.getByLabel('Email', { exact: true });
    const password = page.getByLabel('Kata Sandi', { exact: true });
    const remember = page.getByLabel('Ingat saya', { exact: true });
    const submit = page.getByRole('button', { name: 'Masuk', exact: true });

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
