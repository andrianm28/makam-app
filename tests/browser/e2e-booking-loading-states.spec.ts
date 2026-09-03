import { test, expect } from '@playwright/test';
import {
    CUSTOMER,
    DECEASED,
    startAtStep1,
    selectCity,
    selectCemeteryNoPackage,
    selectServiceType,
    continueFromDiscovery,
} from './e2e-booking-helpers';

/**
 * §B-39 ("Loading, empty, error, pending, success, and support states
 * reviewed"). Every other sub-state already has coverage elsewhere in this
 * suite (empty/error/pending/success states are exercised across
 * e2e-booking.spec.ts, e2e-renewal.spec.ts, e2e-marketplace.spec.ts). This
 * file closes the one genuinely missing sub-state: the booking wizard's
 * `wire:loading` markup on the merged customer+deceased-data screen
 * ("saveStep2"), which real markup exists for (wizard.blade.php) but had no
 * test asserting it actually appears and disables the submit button.
 *
 * Previously this covered two separate saves ("saveStep6"/"saveStep7", the
 * old customer-data and deceased-data steps). The wizard-step-reduction
 * merge collapsed both into ONE form and ONE save
 * (`BookingWizard::saveStep2()`) with a single `wire:loading
 * wire:target="saveStep2"` indicator, so there is now exactly one loading
 * state to assert here, not two.
 */
test.describe('E2E-BOOK — loading states', () => {
    test('the merged customer+deceased-data screen shows a loading indicator and disables the submit button while saving', async ({ page }) => {
        await startAtStep1(page);
        await selectCity(page, 'Jakarta');
        await selectCemeteryNoPackage(page, 'TPS Jakarta 2');
        await selectServiceType(page, 'Makam Baru');
        await continueFromDiscovery(page);

        await expect(page.locator('#booking-step-2-heading')).toBeVisible();
        await page.locator('#customer-full-name').fill(CUSTOMER.fullName);
        await page.locator('#customer-mobile').fill(CUSTOMER.mobile);
        await page.locator('#customer-email').fill(CUSTOMER.email);
        await page.locator('#customer-address').fill(CUSTOMER.address);
        await page.locator('#customer-relationship').selectOption(CUSTOMER.relationship);
        await page.locator('#customer-contact-channel').selectOption(CUSTOMER.contactChannel);
        await page.locator('#privacy-notice-accepted').check();
        await page.locator('#deceased-full-name').fill(DECEASED.fullName);
        await page.locator('#deceased-date-of-birth').fill(DECEASED.dob);
        await page.locator('#deceased-date-of-death').fill(DECEASED.dod);
        await page.locator('#deceased-relationship').selectOption(DECEASED.relationship);

        // Only one "Lanjutkan" control exists on this screen (unlike the old
        // nine-step model's progressive reveal, `currentScreen()` guards are
        // mutually exclusive @if blocks, so no other screen's controls are
        // in the DOM at the same time) -- no scoping needed to disambiguate.
        const submit = page.getByRole('button', { name: 'Lanjutkan', exact: true });

        // Race the click against the loading assertions -- a local server
        // responds fast enough that a sequential `await` chain can miss the
        // loading window entirely. Playwright's `toBeVisible()`/`toBeDisabled()`
        // retry internally, but only if the assertion is already in flight
        // before the state comes and goes.
        await Promise.all([
            expect(page.getByRole('status').filter({ hasText: 'Menyimpan data pemesan dan data almarhum' })).toBeVisible(),
            expect(submit).toBeDisabled(),
            submit.click(),
        ]);

        await expect(page.locator('#booking-step-3-heading')).toBeVisible();
    });
});
