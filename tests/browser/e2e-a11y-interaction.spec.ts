import { test, expect, devices } from '@playwright/test';
import { startAtStep1, completeStep1, completeStep2NoPackage, completeStep3, completeStep4, CUSTOMER } from './e2e-booking-helpers';

/**
 * §B-41 ("Keyboard navigation, focus, labels, and touch targets pass"). The
 * "labels" third of this compound claim already has real evidence elsewhere
 * (form <label for> coverage across the booking/renewal specs and axe scans).
 * This file closes the other two: keyboard reachability/focus order/focus
 * styling on a real multi-field form, and the 44px touch-target floor
 * (design-system.md §7.3, `--mk-touch-min`) on real interactive elements.
 *
 * Deliberately different elements/pages from e2e-home.spec.ts's own mobile
 * nav test (the hamburger button and mobile menu links) -- that test never
 * asserts a bounding box, so it provides no touch-target-size evidence of
 * its own. This file's two touch-target checks (the homepage hero CTA and
 * the booking wizard's Step 1 city buttons) are picked to not duplicate it.
 */
test.describe('E2E-A11Y — touch targets', () => {
    // Spreading the whole `devices['Pixel 5']` descriptor here (as the plan
    // brief's illustrative code showed) fails at collection time --
    // `defaultBrowserType` cannot be set inside a `describe` block, only at
    // the top level or in playwright.config.ts ("forces a new worker",
    // confirmed by actually running this file). Pick only the viewport/UA/
    // touch fields instead, which is exactly the brief's other allowed
    // option ("or set viewport directly").
    const { defaultBrowserType: _defaultBrowserType, ...pixel5 } = devices['Pixel 5'];
    test.use({ ...pixel5 });

    test('primary homepage CTA meets the 44px minimum touch target', async ({ page }) => {
        await page.goto('/');

        // resources/views/livewire/public/home-page.blade.php's hero section:
        // <x-mk.button variant="primary" size="lg" href="/pemesanan-makam">Pesan Makam</x-mk.button>
        const cta = page.getByRole('link', { name: 'Pesan Makam', exact: true });
        const box = await cta.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.width).toBeGreaterThanOrEqual(44);
    });

    test('booking wizard Step 1 city selection button meets the 44px minimum touch target', async ({ page }) => {
        await startAtStep1(page);

        // wizard.blade.php's Step 1 city buttons: <x-mk.button variant="secondary" ...>
        // — no `size` prop, so this exercises the md/44px default exactly at
        // the floor rather than the hero CTA's lg/52px, which is a
        // meaningfully different case.
        const cityButton = page.getByRole('button', { name: 'Jakarta', exact: true });
        const box = await cityButton.boundingBox();
        expect(box).not.toBeNull();
        expect(box!.height).toBeGreaterThanOrEqual(44);
        expect(box!.width).toBeGreaterThanOrEqual(44);
    });
});

test.describe('E2E-A11Y — keyboard navigation', () => {
    test('the customer-data step is fully reachable and completable by keyboard alone', async ({ page }) => {
        // Reaching Step 6 by mouse click is fine -- this test's own claim is
        // about the Step 6 form itself, not the whole wizard (per the plan
        // brief). Same real path e2e-booking-loading-states.spec.ts uses.
        await startAtStep1(page);
        await completeStep1(page, 'Jakarta');
        await completeStep2NoPackage(page, 'TPS Jakarta 2');
        await completeStep3(page, 'Makam Baru');
        await completeStep4(page);
        await page.getByRole('button', { name: 'Lanjut ke Data Pemesan' }).click();
        await expect(page.locator('#booking-step-6-heading')).toBeVisible();

        const fullName = page.locator('#customer-full-name');
        const mobile = page.locator('#customer-mobile');
        const email = page.locator('#customer-email');
        const address = page.locator('#customer-address');
        const relationship = page.locator('#customer-relationship');
        const contactChannel = page.locator('#customer-contact-channel');
        const privacy = page.locator('#privacy-notice-accepted');
        // The checkbox's own <label> wraps a real <a> link ("Pemberitahuan
        // Privasi") AFTER the <input> in DOM order -- confirmed by reading
        // wizard.blade.php directly, not assumed -- so it is a genuine Tab
        // stop between the checkbox and the "Kembali"/"Lanjutkan" buttons.
        const privacyLink = page.getByRole('link', { name: 'Pemberitahuan Privasi', exact: true });
        // Progressive reveal keeps Step 5's own completed section (and its
        // own "Kembali" button) visible alongside Step 6 here, so an
        // unscoped `getByRole('button', { name: 'Kembali' })` now resolves
        // to 2 elements. Scope to Step 6's own `<section aria-labelledby=
        // "booking-step-6-heading">` (real heading text confirmed in
        // wizard.blade.php) so this targets the step under test, not
        // whichever "Kembali" happens to be first in the DOM.
        const step6Section = page.getByLabel('Langkah 6 — Data Pemesan');
        const back = step6Section.getByRole('button', { name: 'Kembali', exact: true });
        const submit = step6Section.getByRole('button', { name: 'Lanjutkan', exact: true });

        await fullName.focus();
        await expect(fullName).toBeFocused();

        // Real focus-visible styling check for a representative field.
        // wizard.blade.php's $mkControl/$mkControlIdle apply `focus:ring-2
        // focus:ring-offset-1 focus:ring-primary-600` (a box-shadow-based
        // ring, not the `outline` property) -- confirmed by reading the
        // Blade source, not the brief's illustrative `focus-visible:ring-*`
        // shape. Assert the actual computed box-shadow is present rather
        // than guessing a class name that isn't the real one here.
        const fullNameBoxShadow = await fullName.evaluate((el) => getComputedStyle(el).boxShadow);
        expect(fullNameBoxShadow).not.toBe('none');

        await page.keyboard.type(CUSTOMER.fullName);

        await page.keyboard.press('Tab');
        await expect(mobile).toBeFocused();
        await page.keyboard.type(CUSTOMER.mobile);

        await page.keyboard.press('Tab');
        await expect(email).toBeFocused();
        await page.keyboard.type(CUSTOMER.email);

        await page.keyboard.press('Tab');
        await expect(address).toBeFocused();
        await page.keyboard.type(CUSTOMER.address);

        await page.keyboard.press('Tab');
        await expect(relationship).toBeFocused();
        await relationship.selectOption(CUSTOMER.relationship);

        await page.keyboard.press('Tab');
        await expect(contactChannel).toBeFocused();
        await contactChannel.selectOption(CUSTOMER.contactChannel);

        await page.keyboard.press('Tab');
        await expect(privacy).toBeFocused();
        await page.keyboard.press('Space');
        await expect(privacy).toBeChecked();

        await page.keyboard.press('Tab');
        await expect(privacyLink).toBeFocused();

        await page.keyboard.press('Tab');
        await expect(back).toBeFocused();

        await page.keyboard.press('Tab');
        await expect(submit).toBeFocused();

        // <x-mk.button>'s own base classes genuinely use the
        // `focus-visible:` prefix (unlike the hand-written form fields
        // above), so this one legitimately matches the brief's illustrative
        // `focus-visible:ring-*` shape -- confirmed against
        // resources/views/components/mk/button.blade.php.
        await expect(submit).toHaveClass(/focus-visible:ring-primary-600/);

        await page.keyboard.press('Enter');
        await expect(page.locator('#booking-step-7-heading')).toBeVisible();
    });
});
