import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
    CUSTOMER,
    DECEASED,
    completeStep1,
    completeStep2NoPackage,
    completeStep3,
    completeStep4,
    completeStep6,
    completeStep7,
    completeStep8Manual,
    startAtStep1,
} from './e2e-booking-helpers';

/**
 * E2E-BOOK — the 9-step public booking wizard (`/pemesanan-makam`).
 * `docs/testing/test-strategy.md` §2 `E2E-BOOK` acceptance criteria.
 *
 * Real fixture data only — no invented selectors or values:
 *   - Cities:            App\Domain\CemeteryDirectory\LaunchCityCode
 *   - Cemeteries:        App\Support\ExampleData\CemeteryExampleData (10 seeded
 *                         cemeteries, 2-per-city; index 0 "TPU Jakarta 1" and
 *                         index 4 "TPU Depok 5" carry `cemetery_packages` rows,
 *                         every other cemetery has none)
 *   - Service types:     App\Domain\Booking\BookingServiceType (Step 3)
 *   - Service catalogue: App\Domain\ServiceCatalog\ServiceCode +
 *                         App\Support\ExampleData\ServiceOperationalExampleData
 *                         (real seeded Indonesian names/prices, verified
 *                         directly against the running database before
 *                         writing this file)
 *   - Field ids/enums:   app/Livewire/Public/Booking/BookingWizard.php,
 *                         resources/views/livewire/public/booking/wizard.blade.php,
 *                         app/Domain/Booking/Actions/SaveBookingDraftStep.php
 *                         (validation rules — the fixture values below are
 *                         chosen to satisfy them, not guessed)
 *
 * Step headings carry a stable `id="booking-step-N-heading"` anchor
 * (wizard.blade.php); every step transition is asserted against that id
 * rather than translated copy, the same discipline `smoke.spec.ts` uses for
 * the homepage heading.
 *
 * The step-completion helpers and fixture consts (`CUSTOMER`, `DECEASED`,
 * `startAtStep1`, `completeStep1`, ...) live in `./e2e-booking-helpers.ts`
 * and are imported here, not defined locally — `e2e-booking-mobile.spec.ts`
 * imports the same module, so both viewports walk the identical real flow
 * with one real source of truth. See that file's own header comment for why
 * it isn't defined directly in this `.spec.ts` file.
 */

test.describe('E2E-BOOK — full journey (Makam Baru, standard branch)', () => {
    test('a visitor completes all 9 steps end to end with real fixture data', async ({ page }) => {
        await test.step('Step 1 — location', async () => {
            await startAtStep1(page);

            // Progress indicator: current step is 1 of N, announced via the
            // accessible progressbar stepper.blade.php always renders (a bare
            // sr-only div with aria-valuenow/valuemin/valuemax/valuetext, no
            // accessible name of its own — the stepper's outer role="group"
            // carries the "Progres pemesanan" label instead).
            const progress = page.getByRole('progressbar');
            await expect(progress).toHaveAttribute('aria-valuenow', '1');

            await completeStep1(page, 'Jakarta');
        });

        await test.step('Step 2 — TPU/TPS (no package cemetery)', async () => {
            // "TPS Jakarta 2" (App\Support\ExampleData\CemeteryExampleData
            // index 1) carries no cemetery_packages rows, so it is a single
            // whole-card button.
            await completeStep2NoPackage(page, 'TPS Jakarta 2');
        });

        await test.step('Step 3 — service type (Makam Baru / NEW_GRAVE)', async () => {
            // Product labels, not raw enum codes — BookingServiceType::LABELS.
            // Scoped to Step 3's own section (same real collision
            // `completeStep3()` in e2e-booking-helpers.ts documents: Step
            // 2's cemetery listing can render its own "Makam Tumpang"
            // package button, which stays visible alongside Step 3 under
            // progressive reveal).
            const step3 = page.getByLabel('Langkah 3 — Pilih Jenis Layanan');
            for (const label of ['Makam Baru', 'Makam Tumpang', 'Urgent', 'Pre-Need']) {
                await expect(step3.getByRole('button', { name: label, exact: true })).toBeVisible();
            }
            await completeStep3(page, 'Makam Baru');
        });

        await test.step('Step 4 — real service catalog, mandatory + one additional', async () => {
            // Basic (mandatory) services are pre-checked, disabled, and badged
            // "Wajib" — real catalogue names, not raw ServiceCode strings.
            await expect(page.locator('#service-DOCUMENT_PROCESSING')).toBeChecked();
            await expect(page.locator('#service-DOCUMENT_PROCESSING')).toBeDisabled();
            await expect(page.locator('#service-GRAVE_DIGGING')).toBeChecked();
            await expect(page.getByText('Pengurusan Dokumen')).toBeVisible();
            await expect(page.getByText('Penggalian Makam')).toBeVisible();
            await expect(page.getByText('Wajib')).toHaveCount(2);

            // A real additional catalogue service, selectable and unchecked
            // by default.
            await expect(page.locator('#service-AMBULANCE')).toBeVisible();
            await expect(page.locator('#service-AMBULANCE')).not.toBeChecked();
            await expect(page.getByText('Ambulans')).toBeVisible();

            await completeStep4(page, 'AMBULANCE');
        });

        await test.step('Step 5 — quote line items', async () => {
            // Real seeded prices (App\Support\ExampleData\ServiceOperationalExampleData
            // ::dummyPrices(): 350_000 + index*200_000 over ServiceCode::KNOWN_CODES).
            // DOCUMENT_PROCESSING (i=0) = Rp 350.000, GRAVE_DIGGING (i=1) =
            // Rp 550.000, AMBULANCE (i=2) = Rp 750.000 — verified directly
            // against the running database before writing this assertion.
            // <x-mk.table> renders BOTH a real <table> (md+) and a <dl>
            // mobile-card fallback in the DOM simultaneously (one hidden via
            // responsive classes at this viewport), so text assertions are
            // scoped to the real table to avoid a strict-mode double match.
            const summaryTable = page.getByRole('table');
            await expect(summaryTable.getByText('Pengurusan Dokumen')).toBeVisible();
            await expect(summaryTable.getByText('Rp 350.000')).toBeVisible();
            await expect(summaryTable.getByText('Penggalian Makam')).toBeVisible();
            await expect(summaryTable.getByText('Rp 550.000')).toBeVisible();
            await expect(summaryTable.getByText('Ambulans')).toBeVisible();
            await expect(summaryTable.getByText('Rp 750.000')).toBeVisible();
            await expect(page.getByText('Total: Rp 1.650.000')).toBeVisible();

            await page.getByRole('button', { name: 'Lanjut ke Data Pemesan' }).click();
        });

        await test.step('Step 6 — customer form', async () => {
            const axeResults = await new AxeBuilder({ page }).analyze();
            expect(axeResults.violations).toEqual([]);

            await completeStep6(page);
        });

        await test.step('Step 7 — deceased form, honest no-upload state', async () => {
            // ADR-0035 finding: document upload is a DELIBERATE MVP scope cut.
            // The real current behaviour is an honest "collected offline"
            // placeholder, never an upload form — asserted as absence, not a
            // fabricated upload flow.
            await expect(page.getByText('Anda belum perlu menyiapkan dokumen')).toBeVisible();
            await expect(page.locator('input[type="file"]')).toHaveCount(0);

            await completeStep7(page);
        });

        await test.step('Step 8 — payment, manual fallback', async () => {
            await expect(page.getByRole('heading', { name: 'Pembayaran Manual', exact: true })).toBeVisible();
            await expect(page.locator('#payment-reference')).toBeVisible();

            await completeStep8Manual(page, 'BCA-TRF-000123-CONTOH');
        });

        await test.step('Step 9 — confirmation, invoice-equivalent summary, next action', async () => {
            // Pending, never styled as success — the order now exists (fixed
            // 25 Aug 2026: manual submission creates a real Order via
            // SubmitBookingDraft, same as the online path), but payment has
            // not been verified yet (design-system.md §6.7). The order
            // reference is real and shown; only payment status is pending.
            await expect(page.getByText('Menunggu diproses', { exact: true })).toBeVisible();
            await expect(
                page.getByText(/Pesanan Anda diterima dengan nomor MK-/),
            ).toBeVisible();

            // Notification state — never claims a delivery that has not
            // happened (AGENTS.md / design-system.md §6.8).
            await expect(page.getByText('Belum dikirim').first()).toBeVisible();

            // The invoice-equivalent quote summary carries through to the
            // confirmation screen with the same real line items and total.
            await expect(page.getByText('Ringkasan Pesanan')).toBeVisible();
            await expect(page.getByText('Rp 1.650.000').last()).toBeVisible();

            // Submitted data, echoed back honestly.
            await expect(page.getByText(CUSTOMER.fullName)).toBeVisible();
            await expect(page.getByText(DECEASED.fullName)).toBeVisible();
            await expect(page.getByText('Transfer Manual')).toBeVisible();

            // The "what happens next" list — the honest next-action state.
            // The "nomor pesanan resmi diberikan setelah diproses" bullet is
            // now conditional on no order existing yet; it does not appear
            // here since this submission genuinely created one.
            await expect(page.getByText('Apa yang selanjutnya?')).toBeVisible();

            const axeResults = await new AxeBuilder({ page }).analyze();
            expect(axeResults.violations).toEqual([]);
        });
    });
});

test.describe('E2E-BOOK — progress indicator and back/forward navigation', () => {
    test('the stepper reflects the current step and back navigation preserves data', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Bogor');

        // "TPU Bogor 3" — CemeteryExampleData::OPEN_CEMETERY_SLUG, the plain
        // published cemetery with no packages and no other special role.
        await completeStep2NoPackage(page, 'TPU Bogor 3');

        const progress = page.getByRole('progressbar').first();
        await expect(progress).toHaveAttribute('aria-valuenow', '3');
        await expect(progress).toHaveAttribute('aria-valuetext', /Langkah 3 dari 9/);

        // Back navigation via the step's own "Kembali" control. Step 2's
        // own completed section stays visible alongside Step 3 here
        // (progressive reveal), and it has its own "Kembali" button too, so
        // this is scoped to Step 3's own section (real heading text
        // confirmed in wizard.blade.php) to click Step 3's control
        // specifically, matching this test's own intent.
        await page.getByLabel('Langkah 3 — Pilih Jenis Layanan').getByRole('button', { name: 'Kembali' }).click();
        await expect(page.locator('#booking-step-2-heading')).toBeVisible();
        await expect(progress).toHaveAttribute('aria-valuenow', '2');

        // Forward again — the same cemetery choice is still selectable and
        // the draft is not corrupted by the round trip.
        await completeStep2NoPackage(page, 'TPU Bogor 3');
        await expect(progress).toHaveAttribute('aria-valuenow', '3');

        await completeStep3(page, 'Makam Baru');
        await completeStep4(page);
        await page.getByRole('button', { name: 'Lanjut ke Data Pemesan' }).click();
        await completeStep6(page);

        // Step 7's own "Kembali" returns to step 6 with the just-entered
        // customer data still populated — proving the autosaved draft, not
        // client-only form state, is what step 6 renders on return. Steps 5
        // and 6 both stay visible alongside Step 7 here (progressive
        // reveal) and each has its own "Kembali" button, so this is scoped
        // to Step 7's own section (real heading text confirmed in
        // wizard.blade.php) to click Step 7's control specifically.
        await page.getByLabel('Langkah 7 — Data Almarhum').getByRole('button', { name: 'Kembali' }).click();
        await expect(page.locator('#booking-step-6-heading')).toBeVisible();
        await expect(page.locator('#customer-full-name')).toHaveValue(CUSTOMER.fullName);
        await expect(page.locator('#customer-email')).toHaveValue(CUSTOMER.email);

        // The stepper's own dot for a completed step is a real clickable
        // control (design-system.md §3.9: "complete -> clickable").
        const step5Dot = page.getByRole('button', { name: /Langkah 5:.*selesai/ });
        await expect(step5Dot).toBeVisible();
        await step5Dot.click();
        await expect(page.locator('#booking-step-5-heading')).toBeVisible();
    });
});

test.describe('E2E-BOOK — autosave and resume', () => {
    test('navigating away and back to the draft URL resumes at the saved step', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Tangerang');
        await completeStep2NoPackage(page, 'TPU Tangerang 7');

        const draftUrl = page.url();
        expect(draftUrl).toMatch(/\/pemesanan-makam\/draft\/[^/]+$/);

        // Navigate fully away from the wizard, then return via the draft URL
        // — resume relies on a session-bound secret
        // (App\Domain\Booking\BookingDraftBinding), not merely knowing the id.
        await page.goto('/');
        await expect(page).toHaveTitle(/Makam\.co\.id/);

        await page.goto(draftUrl);
        await expect(page.locator('#booking-step-3-heading')).toBeVisible();

        const progress = page.getByRole('progressbar').first();
        await expect(progress).toHaveAttribute('aria-valuenow', '3');
    });

    test('an unknown draft id resets to a working blank wizard rather than exposing state', async ({ page }) => {
        await page.goto('/pemesanan-makam/draft/00000000-0000-0000-0000-000000000000');
        await expect(page.locator('#booking-step-1-heading')).toBeVisible();
    });
});

test.describe('E2E-BOOK — TPU/TPS package selection', () => {
    test('a cemetery with package rows requires choosing a package, not the whole card', async ({ page }) => {
        await startAtStep1(page);
        await completeStep1(page, 'Jakarta');

        // "TPU Jakarta 1" (CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])
        // carries real cemetery_packages rows and renders the two-level
        // choice instead of a single whole-card button.
        await expect(page.getByText('TPU Jakarta 1')).toBeVisible();
        await expect(page.getByText('Pilih paket/kelas untuk TPU/TPS ini:')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Makam Tumpang', exact: true })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Makam Tumpang — Kelas A' })).toBeVisible();
        await expect(page.getByRole('button', { name: 'Makam Tumpang — Kelas B' })).toBeVisible();

        await page.getByRole('button', { name: 'Makam Single', exact: true }).click();
        await expect(page.locator('#booking-step-3-heading')).toBeVisible();
    });
});

test.describe('E2E-BOOK — service type conditional branches', () => {
    /**
     * Scoping decision: `App\Domain\Booking\BookingServiceType`'s own doc
     * block states plainly that selecting OVERLAPPING_GRAVE or URGENT_TODAY
     * "records the choice only — this batch enforces NO operational
     * precondition on either value," and Step 4 onward reads only
     * `selected_services`/`completed_steps`, never `service_type`. Verified
     * directly in `SaveBookingDraftStep`/`BookingWizard` before writing this
     * suite: there is currently no behavioural branch to exercise beyond the
     * choice itself and Step 4 rendering identically afterward. One full
     * 9-step run already covers the NEW_GRAVE (Makam Baru) branch above;
     * these three spot-check that the other three choices are each
     * selectable, are correctly labelled, and land on the same Step 4 with
     * the same mandatory services — not four redundant full 9-step runs of
     * behaviour that does not yet diverge.
     */
    for (const [serviceTypeLabel, city, cemeteryName] of [
        // "TPS Bekasi 10" (CemeteryExampleData::DRAFT_SLUG) is the one
        // deliberately unpublished cemetery and never appears in
        // CemeteryPublicQuery::inCity() — Bekasi's only reachable cemetery
        // here is "TPU Bekasi 9", so Pre-Need uses Tangerang instead.
        ['Makam Tumpang', 'Depok', 'TPS Depok 6'],
        ['Urgent', 'Bekasi', 'TPU Bekasi 9'],
        ['Pre-Need', 'Tangerang', 'TPS Tangerang 8'],
    ] as const) {
        test(`"${serviceTypeLabel}" is selectable and advances to Step 4 with the mandatory services`, async ({ page }) => {
            await startAtStep1(page);
            await completeStep1(page, city);
            await completeStep2NoPackage(page, cemeteryName);
            await completeStep3(page, serviceTypeLabel);

            await expect(page.locator('#service-DOCUMENT_PROCESSING')).toBeChecked();
            await expect(page.locator('#service-GRAVE_DIGGING')).toBeChecked();
        });
    }
});
