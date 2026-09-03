import { test, expect, devices, Locator, Page } from '@playwright/test';
import {
    startAtStep1,
    selectCity,
    selectCemeteryNoPackage,
    selectServiceType,
    continueFromDiscovery,
    CUSTOMER,
    DECEASED,
} from './e2e-booking-helpers';

/**
 * A filled native `<input type="date">` does not reliably hand focus to the
 * next element on a single Tab press across Chromium versions/OSes -- the
 * populated control's internal value/spinner UI can consume its own tab
 * stop(s) on the way out, and that internal count is not something this
 * suite should hard-code (verified by CI: one extra Tab was not enough,
 * confirming this needs to be robust to the exact count, not a guess at
 * it). This only matters for the two native date inputs in this form; every
 * other field hands off focus in exactly one Tab press.
 *
 * This still proves genuine keyboard-only reachability -- the test's own
 * documented claim -- it just does not assume a specific press count to get
 * there, which a real keyboard user would not be counting either.
 */
async function pressTabUntilFocused(page: Page, target: Locator, maxAttempts = 4): Promise<void> {
    for (let attempt = 0; attempt < maxAttempts; attempt++) {
        if (await target.evaluate((el) => el === document.activeElement).catch(() => false)) {
            return;
        }
        await page.keyboard.press('Tab');
    }
    await expect(target).toBeFocused();
}

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

        // wizard.blade.php's DISCOVERY city buttons: <x-mk.button variant="secondary" ...>
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
    test('the merged customer+deceased-data screen is fully reachable and completable by keyboard alone', async ({ page }) => {
        // Reaching Screen 2 by mouse click is fine -- this test's own claim
        // is about the Screen 2 form itself, not the whole wizard (per the
        // plan brief). Same real path e2e-booking-loading-states.spec.ts
        // uses. DISCOVERY's default mandatory services are already staged
        // (BookingWizard::mount() defaults `stagedServiceCodes` to
        // ServiceCode::BASIC_CODES), so no extra service pick is needed
        // before saving.
        await startAtStep1(page);
        await selectCity(page, 'Jakarta');
        await selectCemeteryNoPackage(page, 'TPS Jakarta 2');
        await selectServiceType(page, 'Makam Baru');
        await continueFromDiscovery(page);
        await expect(page.locator('#booking-step-2-heading')).toBeVisible();

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
        // stop between the checkbox and the deceased-data fields that follow
        // it in this same merged form.
        const privacyLink = page.getByRole('link', { name: 'Pemberitahuan Privasi', exact: true });
        const deceasedFullName = page.locator('#deceased-full-name');
        const deceasedDob = page.locator('#deceased-date-of-birth');
        const deceasedDod = page.locator('#deceased-date-of-death');
        const deceasedRelationship = page.locator('#deceased-relationship');
        const deceasedGender = page.locator('#deceased-gender');
        // Customer and deceased data are now ONE form on ONE screen
        // (`BookingWizard::saveStep2()`, replacing the old separate Step 6/
        // Step 7 forms), and this is the only screen rendered at a time
        // (`currentScreen()` guards are mutually exclusive @if blocks), so
        // there is exactly one "Kembali"/"Lanjutkan" pair on the page here
        // -- no cross-step collision to scope around any more.
        const back = page.getByRole('button', { name: 'Kembali', exact: true });
        const submit = page.getByRole('button', { name: 'Lanjutkan', exact: true });

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
        await expect(deceasedFullName).toBeFocused();
        await page.keyboard.type(DECEASED.fullName);

        // Native date inputs do not reliably accept `keyboard.type()` digit
        // sequences the same way across locales/engines -- focus is still
        // reached and asserted via Tab, but the value itself is set with
        // `.fill()` on the already-focused control, the same approach
        // `e2e-booking-helpers.ts` uses for these same fields. See
        // `pressTabUntilFocused()`'s own doc comment for why the Tab count
        // out of a FILLED date field is not hard-coded.
        await page.keyboard.press('Tab');
        await expect(deceasedDob).toBeFocused();
        await deceasedDob.fill(DECEASED.dob);
        await pressTabUntilFocused(page, deceasedDod);
        await expect(deceasedDod).toBeFocused();

        await deceasedDod.fill(DECEASED.dod);
        await pressTabUntilFocused(page, deceasedRelationship);
        await expect(deceasedRelationship).toBeFocused();

        await deceasedRelationship.selectOption(DECEASED.relationship);

        // Gender is optional (`BookingGender` — no `*` marker in
        // wizard.blade.php) — reached by keyboard but deliberately left
        // unset, proving the form is completable without it.
        await page.keyboard.press('Tab');
        await expect(deceasedGender).toBeFocused();

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
        await expect(page.locator('#booking-step-3-heading')).toBeVisible();
    });
});
