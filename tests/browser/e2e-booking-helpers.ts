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
 * --- 9-step -> 4-screen wizard-step-reduction rewrite ---
 * The booking wizard was renumbered from nine saved steps to four
 * (`App\Domain\Booking\BookingWizardStep`: DISCOVERY=1,
 * CUSTOMER_AND_DECEASED_DATA=2, PAYMENT=3, CONFIRMATION=4). DISCOVERY merges
 * what used to be four separate saved steps (city/cemetery/service-type/
 * services) into ONE screen with progressive-reveal LOCAL component state
 * (`BookingWizard::selectCity()`/`selectCemetery()`/`selectServiceType()` —
 * none of these persist anything) and exactly one save at the end
 * (`continueFromDiscovery()` -> `saveStep1()`). The old Ringkasan
 * ("Summary") step is gone as a numbered step; it is now an always-visible
 * panel on the SAME screen as the merged customer+deceased form
 * (`saveStep2()`, replacing the old `saveStep6()`/`saveStep7()`).
 *
 * Helper names below mirror the real Livewire method names
 * (`app/Livewire/Public/Booking/BookingWizard.php`) rather than the old
 * per-numbered-step names, since the underlying actions no longer map
 * 1:1 to stepper numbers: `selectCity()`/`selectCemeteryNoPackage()`/
 * `selectServiceType()` are all local, non-persisting UI state changes
 * within DISCOVERY, and only `continueFromDiscovery()` actually saves.
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

/**
 * DISCOVERY's city sub-choice — `BookingWizard::selectCity()`. Local
 * component state only; nothing is saved and no navigation happens, so
 * this only waits for the next progressive-reveal section (Pilih TPU/TPS)
 * to appear.
 */
export async function selectCity(page: Page, cityLabel: string): Promise<void> {
    await page.getByRole('button', { name: cityLabel, exact: true }).click();
    await expect(page.locator('#discovery-cemetery-heading')).toBeVisible();
}

/**
 * Selects a cemetery that has NO `cemetery_packages` rows (a single click) —
 * `BookingWizard::selectCemetery()`. Still local component state, same as
 * `selectCity()` above: nothing is persisted until `continueFromDiscovery()`
 * runs at the end of this whole screen.
 *
 * The card is not itself a `<button>` whose accessible name is the cemetery
 * name — design-system.md §3.3's Cemetery card spec (PUB-011) requires the
 * card to show photo/address/facilities/price content the card can no
 * longer also BE the click target for. The selection control is the
 * explicit "Pilih {cemetery name}" button inside the (non-interactive) card.
 */
export async function selectCemeteryNoPackage(page: Page, cemeteryName: string): Promise<void> {
    await page.getByRole('button', { name: `Pilih ${cemeteryName}` }).click();
    await expect(page.locator('#discovery-service-type-heading')).toBeVisible();
}

/**
 * DISCOVERY's service-type sub-choice — `BookingWizard::selectServiceType()`.
 *
 * Scoped to the "Pilih Jenis Layanan" section (`aria-labelledby=
 * "discovery-service-type-heading"`), not the whole page: a cemetery WITH
 * `cemetery_packages` rows (rendered in the section just above this one,
 * still visible under progressive reveal) can render its own package button
 * sharing text with a service-type label (e.g. both call something "Makam
 * Tumpang") — a real, confirmed collision, not a same-step duplicate.
 */
export async function selectServiceType(page: Page, serviceTypeLabel: string): Promise<void> {
    await page.getByLabel('Pilih Jenis Layanan').getByRole('button', { name: serviceTypeLabel, exact: true }).click();
    await expect(page.locator('#discovery-services-heading')).toBeVisible();
}

/**
 * The ONE control on the whole DISCOVERY screen that persists anything —
 * `BookingWizard::continueFromDiscovery()` -> `saveStep1()`. Builds the
 * merged city/cemetery/service-type/services payload out of the local
 * selections made by `selectCity()`/`selectCemeteryNoPackage()`/
 * `selectServiceType()` above and redirects to the resumable draft URL on
 * success — replaces the old `completeStep4()` (which only saved the
 * services sub-step of the former nine-step model).
 */
export async function continueFromDiscovery(page: Page, extraServiceCode?: string): Promise<void> {
    if (extraServiceCode) {
        await page.locator(`#service-${extraServiceCode}`).check();
    }
    await page.getByRole('button', { name: 'Lanjutkan' }).click();
    await page.waitForURL(/\/pemesanan-makam\/draft\//);
    await expect(page.locator('#booking-step-2-heading')).toBeVisible();
}

/**
 * The merged customer+deceased form — `BookingWizard::saveStep2()`, which
 * REPLACES the old `completeStep6()` (customer data) and `completeStep7()`
 * (deceased data): both payloads are now one form and one save. The
 * always-visible Ringkasan (order summary) panel that precedes this form on
 * the same screen is read-only and needs no interaction to "get past" —
 * unlike the old numbered Summary step, there is no separate forward
 * control for it any more.
 */
export async function completeStep2(page: Page): Promise<void> {
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

    await page.getByRole('button', { name: 'Lanjutkan', exact: true }).click();
    await expect(page.locator('#booking-step-3-heading')).toBeVisible();
}

/**
 * The MANUAL fallback path only — deliberate. `G-PAY-01` (online payment) is
 * closed by default on a fresh migration
 * (`database/migrations/2026_07_26_120400_seed_feature_gate_registry.php`
 * seeds every gate closed; verified directly rather than assumed). The
 * manual card is rendered unconditionally regardless of gate state, so this
 * path is the one guaranteed to be exercisable in every environment this
 * suite runs in. Replaces the old `completeStep8Manual()` —
 * `BookingWizard::saveStep3()` is the renamed method (was `saveStep8()`).
 */
export async function completeStep3Manual(page: Page, reference: string): Promise<void> {
    await page.locator('#payment-reference').fill(reference);
    await page.getByRole('button', { name: 'Saya Akan Bayar Manual' }).click();
    await expect(page.locator('#booking-step-4-heading')).toBeVisible();
}
