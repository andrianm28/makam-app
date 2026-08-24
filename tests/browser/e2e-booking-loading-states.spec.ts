import { test, expect } from '@playwright/test';
import {
    CUSTOMER,
    DECEASED,
    startAtStep1,
    completeStep1,
    completeStep2NoPackage,
    completeStep3,
    completeStep4,
    completeStep6,
} from './e2e-booking-helpers';

/**
 * §B-39 ("Loading, empty, error, pending, success, and support states
 * reviewed"). Every other sub-state already has coverage elsewhere in this
 * suite (empty/error/pending/success states are exercised across
 * e2e-booking.spec.ts, e2e-renewal.spec.ts, e2e-marketplace.spec.ts). This
 * file closes the one genuinely missing sub-state: the booking wizard's
 * `wire:loading` markup on Step 6 ("saveStep6") and Step 7 ("saveStep7"),
 * which real markup exists for (wizard.blade.php) but had no test asserting
 * it actually appears and disables the submit button.
 */
test.describe('E2E-BOOK — loading states', () => {
    test('the customer-data step shows a loading indicator and disables the submit button while saving', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Jakarta');
        await completeStep2NoPackage(page, 'TPS Jakarta 2');
        await completeStep3(page, 'Makam Baru');
        await completeStep4(page);
        await page.getByRole('button', { name: 'Lanjut ke Data Pemesan' }).click();

        await expect(page.locator('#booking-step-6-heading')).toBeVisible();
        await page.locator('#customer-full-name').fill(CUSTOMER.fullName);
        await page.locator('#customer-mobile').fill(CUSTOMER.mobile);
        await page.locator('#customer-email').fill(CUSTOMER.email);
        await page.locator('#customer-address').fill(CUSTOMER.address);
        await page.locator('#customer-relationship').selectOption(CUSTOMER.relationship);
        await page.locator('#customer-contact-channel').selectOption(CUSTOMER.contactChannel);
        await page.locator('#privacy-notice-accepted').check();

        const submit = page.getByRole('button', { name: 'Lanjutkan' });

        // Race the click against the loading assertions -- a local server
        // responds fast enough that a sequential `await` chain can miss the
        // loading window entirely. Playwright's `toBeVisible()`/`toBeDisabled()`
        // retry internally, but only if the assertion is already in flight
        // before the state comes and goes.
        await Promise.all([
            expect(page.getByRole('status').filter({ hasText: 'Menyimpan data pemesan' })).toBeVisible(),
            expect(submit).toBeDisabled(),
            submit.click(),
        ]);

        await expect(page.locator('#booking-step-7-heading')).toBeVisible();
    });

    test('the deceased-data step shows a loading indicator and disables the submit button while saving', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Jakarta');
        await completeStep2NoPackage(page, 'TPS Jakarta 2');
        await completeStep3(page, 'Makam Baru');
        await completeStep4(page);
        await page.getByRole('button', { name: 'Lanjut ke Data Pemesan' }).click();
        await completeStep6(page);

        await expect(page.locator('#booking-step-7-heading')).toBeVisible();
        await page.locator('#deceased-full-name').fill(DECEASED.fullName);
        await page.locator('#deceased-date-of-birth').fill(DECEASED.dob);
        await page.locator('#deceased-date-of-death').fill(DECEASED.dod);
        await page.locator('#deceased-relationship').selectOption(DECEASED.relationship);

        const submit = page.getByRole('button', { name: 'Lanjutkan' });

        await Promise.all([
            expect(page.getByRole('status').filter({ hasText: 'Menyimpan data almarhum' })).toBeVisible(),
            expect(submit).toBeDisabled(),
            submit.click(),
        ]);

        await expect(page.locator('#booking-step-8-heading')).toBeVisible();
    });
});
