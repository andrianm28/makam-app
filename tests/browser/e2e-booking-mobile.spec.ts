import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import {
    CUSTOMER,
    DECEASED,
    completeStep2,
    completeStep3Manual,
    continueFromDiscovery,
    selectCemeteryNoPackage,
    selectCity,
    selectServiceType,
    startAtStep1,
} from './e2e-booking-helpers';

/**
 * E2E-BOOK, mobile viewport — closes `docs/testing/release-gates.md`'s
 * "Booking Steps 1–9 pass desktop and mobile browser tests" box (now the
 * 4-screen wizard — `App\Domain\Booking\BookingWizardStep`), whose desktop
 * half `e2e-booking.spec.ts` already covers and whose mobile half had zero
 * coverage anywhere in `tests/browser/` (verified 22 Aug 2026: the only
 * mobile-viewport test in the whole tree was `e2e-home.spec.ts`'s single
 * hamburger-nav resize, which never touches booking).
 *
 * Reuses `e2e-booking.spec.ts`'s own step-completion helpers and fixture
 * data rather than re-deriving them — this is the SAME real fixture data
 * (cities/cemeteries/services/prices) that file's own header comment
 * documents, walked through the identical sequence, only under a mobile
 * viewport (this file's own `playwright.config.ts` project, not a
 * `setViewportSize` call, so touch emulation and a real mobile user agent
 * are both exercised, not just a narrower window).
 *
 * The shared helpers/fixtures live in `./e2e-booking-helpers.ts`, imported
 * by both this file and `e2e-booking.spec.ts` — NOT imported directly from
 * `e2e-booking.spec.ts` itself, because this repo's pinned Playwright
 * (v1.62.1) hard-forbids one test file importing another test file
 * ("test file X should not import test file Y", enforced unconditionally
 * whenever both files are matched by any project in the run — no config
 * override exists). Extracting to a plain, non-`.spec.ts` module is the
 * constraint-compliant way to keep one real source of truth.
 */
test.describe('E2E-BOOK-MOBILE — full journey at a real mobile viewport', () => {
    test('a visitor completes all 4 screens end to end on a mobile viewport', async ({ page }) => {
        // Each screen wrapped in its own `test.step()`, matching
        // `e2e-booking.spec.ts`'s desktop full-journey test — same step
        // names, so a failure's location is just as legible on mobile as it
        // is on desktop. Purely structural (no behavior change).
        await test.step('Screen 1 — Cari & Pilih: location', async () => {
            await startAtStep1(page);
            await selectCity(page, 'Jakarta');
        });

        await test.step('Screen 1 — Cari & Pilih: TPU/TPS (no package cemetery)', async () => {
            await selectCemeteryNoPackage(page, 'TPS Jakarta 2');
        });

        await test.step('Screen 1 — Cari & Pilih: service type (Makam Baru / NEW_GRAVE)', async () => {
            await selectServiceType(page, 'Makam Baru');
        });

        await test.step('Screen 1 — Cari & Pilih: real service catalog, mandatory + one additional, then save', async () => {
            await continueFromDiscovery(page, 'AMBULANCE');
        });

        await test.step('Screen 2 — Detail Pemesanan: quote summary + customer & deceased form', async () => {
            await expect(page.locator('#booking-step-2-heading')).toBeVisible();

            const axeResults = await new AxeBuilder({ page }).analyze();
            expect(axeResults.violations).toEqual([]);

            await completeStep2(page);
        });

        await test.step('Screen 3 — Pembayaran, manual fallback', async () => {
            await completeStep3Manual(page, 'BCA-TRF-000123-MOBILE');
        });

        await test.step('Screen 4 — Konfirmasi, invoice-equivalent summary, next action', async () => {
            await expect(page.getByText('Menunggu diproses', { exact: true })).toBeVisible();
            await expect(page.getByText(CUSTOMER.fullName)).toBeVisible();
            await expect(page.getByText(DECEASED.fullName)).toBeVisible();

            const finalAxeResults = await new AxeBuilder({ page }).analyze();
            expect(finalAxeResults.violations).toEqual([]);
        });
    });
});
