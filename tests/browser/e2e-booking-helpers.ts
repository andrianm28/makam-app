import { expect, type Page } from '@playwright/test';

/**
 * Shared step-completion helpers and fixture data for the public booking
 * wizard (`/pemesanan-makam`), reused identically by `e2e-booking.spec.ts`
 * (desktop) and `e2e-booking-mobile.spec.ts` (mobile viewport).
 *
 * Deliberately NOT a `.spec.ts` file: this repo's pinned Playwright
 * (v1.62.1) hard-forbids one test file importing another test file
 * ("test file X should not import test file Y" —
 * `node_modules/playwright/lib/runner/index.js`; no config override exists
 * for this check) precisely to prevent the double-registration bug that
 * pattern causes once a second project also matches the importing file —
 * confirmed directly against this repo's `node_modules` and by observing
 * `e2e-booking.spec.ts`'s own tests silently re-running a second time under
 * `mobile-chromium` when this file's helpers briefly lived inside
 * `e2e-booking.spec.ts` and were imported from there. Both spec files import
 * from this plain module instead, so the fixture data and step logic still
 * have exactly one real source of truth, without violating that constraint.
 *
 * Real fixture data only — no invented selectors or values: see
 * `e2e-booking.spec.ts`'s own header comment for the full provenance
 * (LaunchCityCode, CemeteryExampleData, BookingServiceType, ServiceCode /
 * ServiceOperationalExampleData, BookingWizard.php / wizard.blade.php /
 * SaveBookingDraftStep.php).
 */

export const CUSTOMER = {
    fullName: 'Contoh Pemesan Sejahtera',
    // Matches SaveBookingDraftStep::validateCustomerData()'s mobile regex
    // `^(\+62|62|0)[0-9]{9,13}$`.
    mobile: '081234567890',
    email: 'pemesan.contoh@example.test',
    // >= 10 chars, per validateCustomerData().
    address: 'Jl. Contoh Alamat Pemesan No. 12, Jakarta',
    relationship: 'ANAK',
    contactChannel: 'WHATSAPP',
};

export const DECEASED = {
    fullName: 'Contoh Almarhum Melati',
    dob: '1950-05-10',
    dod: '2026-08-01',
    relationship: 'ORANG_TUA',
};

export async function startAtStep1(page: Page): Promise<void> {
    await page.goto('/pemesanan-makam');
    await expect(page.locator('#booking-step-1-heading')).toBeVisible();
}

export async function completeStep1(page: Page, cityLabel: string): Promise<void> {
    await page.getByRole('button', { name: cityLabel, exact: true }).click();
    await page.waitForURL(/\/pemesanan-makam\/draft\//);
    await expect(page.locator('#booking-step-2-heading')).toBeVisible();
}

/**
 * Selects a cemetery that has NO `cemetery_packages` rows (a single click).
 *
 * The Step 2 card is no longer itself a `<button>` whose accessible name is
 * the cemetery name — design-system.md §3.3's Cemetery card spec (PUB-011)
 * requires the card to show photo/address/facilities/price content the
 * card can no longer also BE the click target for (an interactive
 * `<x-mk.card>` may hold only one focusable control, and this step needs a
 * `wire:click` action, not a navigation link — see wizard.blade.php's own
 * comment at Step 2). The selection control is now an explicit
 * "Pilih {cemetery name}" button inside the (non-interactive) card.
 */
export async function completeStep2NoPackage(page: Page, cemeteryName: string): Promise<void> {
    await page.getByRole('button', { name: `Pilih ${cemeteryName}` }).click();
    await expect(page.locator('#booking-step-3-heading')).toBeVisible();
}

export async function completeStep3(page: Page, serviceTypeLabel: string): Promise<void> {
    // Step 2's own cemetery listing (`aria-label="Daftar TPU/TPS"`) stays
    // visible alongside Step 3 (progressive reveal), and for a city whose
    // OTHER (unselected) cemetery carries `cemetery_packages` rows, that
    // listing renders its own package-selection button that can share text
    // with a Step 3 service-type label (e.g. both call something "Makam
    // Tumpang") — a real, confirmed collision, not a same-step duplicate.
    // Scope to Step 3's own section (real heading text confirmed in
    // wizard.blade.php) so this always clicks the service-type button, not
    // a same-named cemetery/package control.
    await page.getByLabel('Langkah 3 — Pilih Jenis Layanan').getByRole('button', { name: serviceTypeLabel, exact: true }).click();
    await expect(page.locator('#booking-step-4-heading')).toBeVisible();
}

export async function completeStep4(page: Page, extraServiceCode?: string): Promise<void> {
    if (extraServiceCode) {
        await page.locator(`#service-${extraServiceCode}`).check();
    }
    await page.getByRole('button', { name: 'Lanjutkan' }).click();
    await expect(page.locator('#booking-step-5-heading')).toBeVisible();
}

export async function completeStep6(page: Page): Promise<void> {
    await expect(page.locator('#booking-step-6-heading')).toBeVisible();
    await page.locator('#customer-full-name').fill(CUSTOMER.fullName);
    await page.locator('#customer-mobile').fill(CUSTOMER.mobile);
    await page.locator('#customer-email').fill(CUSTOMER.email);
    await page.locator('#customer-address').fill(CUSTOMER.address);
    await page.locator('#customer-relationship').selectOption(CUSTOMER.relationship);
    await page.locator('#customer-contact-channel').selectOption(CUSTOMER.contactChannel);
    await page.locator('#privacy-notice-accepted').check();
    await page.getByRole('button', { name: 'Lanjutkan' }).click();
    await expect(page.locator('#booking-step-7-heading')).toBeVisible();
}

export async function completeStep7(page: Page): Promise<void> {
    await page.locator('#deceased-full-name').fill(DECEASED.fullName);
    await page.locator('#deceased-date-of-birth').fill(DECEASED.dob);
    await page.locator('#deceased-date-of-death').fill(DECEASED.dod);
    await page.locator('#deceased-relationship').selectOption(DECEASED.relationship);
    // Step 6 stays visible alongside Step 7 here (progressive reveal), and
    // both sections' own forward controls are labelled "Lanjutkan". Scope
    // to Step 7's own section (real heading text confirmed in
    // wizard.blade.php) so this always submits Step 7, not Step 6's
    // already-submitted form.
    await page.getByLabel('Langkah 7 — Data Almarhum').getByRole('button', { name: 'Lanjutkan' }).click();
    await expect(page.locator('#booking-step-8-heading')).toBeVisible();
}

/**
 * The MANUAL fallback path only — deliberate. `G-PAY-01` (online payment) is
 * closed by default on a fresh migration
 * (`database/migrations/2026_07_26_120400_seed_feature_gate_registry.php`
 * seeds every gate closed; verified directly rather than assumed). The
 * manual card is rendered unconditionally regardless of gate state, so this
 * path is the one guaranteed to be exercisable in every environment this
 * suite runs in.
 */
export async function completeStep8Manual(page: Page, reference: string): Promise<void> {
    await page.locator('#payment-reference').fill(reference);
    await page.getByRole('button', { name: 'Saya Akan Bayar Manual' }).click();
    await expect(page.locator('#booking-step-9-heading')).toBeVisible();
}
