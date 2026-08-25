import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';
import { addProductAToCart, CATEGORY, PRODUCT_A, placeOrder } from './e2e-marketplace-helpers';

/**
 * E2E-MKT, mobile viewport — closes the marketplace half of the mobile/
 * responsive gap `docs/testing/release-gates.md` §B tracks: `e2e-booking-
 * mobile.spec.ts` already covers the booking wizard under a real mobile
 * viewport, but `e2e-marketplace.spec.ts` (browse, product detail, cart,
 * single-vendor conflict, checkout, manual payment, order tracking) ran
 * under desktop-Chrome only — zero mobile-viewport coverage anywhere in
 * `tests/browser/` for the marketplace checkout flow (confirmed 25 Aug
 * 2026 by reading `playwright.config.ts`'s `mobile-chromium` project: its
 * `testMatch` scoped to `e2e-booking-mobile.spec.ts` alone).
 *
 * A mobile-focused SUBSET of `e2e-marketplace.spec.ts`'s scenarios, not the
 * whole suite re-run under mobile emulation — same choice
 * `e2e-booking-mobile.spec.ts` made (it mirrors exactly one of
 * `e2e-booking.spec.ts`'s several tests, the full 9-step journey, not all of
 * them): the desktop suite's single-vendor-conflict, empty-cart,
 * unknown-order-number, and validation-copy assertions exercise component
 * logic that does not vary by viewport, so re-running them a second time
 * under `mobile-chromium` would double CI runtime for no new coverage (the
 * `chromium` project's own `testIgnore` comment in `playwright.config.ts`
 * documents this exact concern for the booking case; the same reasoning
 * applies here). What DOES warrant its own mobile-viewport run is the
 * critical path a real mobile shopper takes — browse, open a product, add
 * it to the cart, and complete checkout with manual payment — since that is
 * where touch-target sizing, mobile nav, and small-viewport form layout
 * actually differ from desktop.
 *
 * Shared fixtures (`PRODUCT_A`, `CATEGORY`) and step helpers
 * (`addProductAToCart`, `placeOrder`) come from `./e2e-marketplace-helpers.ts`
 * — the SAME real fixture data `e2e-marketplace.spec.ts` uses, not
 * re-derived — imported by both files rather than one spec file importing
 * the other, because this repo's pinned Playwright (v1.62.1) hard-forbids
 * that ("test file X should not import test file Y", enforced whenever both
 * files are matched by any project in the run — no config override exists).
 */
test.describe('E2E-MKT-MOBILE — browse, add to cart, and checkout at a real mobile viewport', () => {
    test('a visitor browses the catalogue and filters by category', async ({ page }) => {
        await page.goto('/marketplace');

        await expect(page.getByRole('heading', { level: 1, name: 'Layanan Pemakaman' })).toBeVisible();

        const nav = page.getByRole('navigation', { name: 'Filter kategori produk' });
        await expect(nav.getByRole('link', { name: CATEGORY.flowers, exact: true })).toBeVisible();

        const grid = page.getByRole('list', { name: 'Daftar produk' });
        await expect(grid.getByRole('link')).not.toHaveCount(0);

        await nav.getByRole('link', { name: CATEGORY.flowers, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1, name: CATEGORY.flowers })).toBeVisible();
        await expect(nav.getByRole('link', { name: CATEGORY.flowers, exact: true })).toHaveAttribute('aria-current', 'page');

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('a visitor opens a product and adds it to the cart', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByText(`Ditawarkan oleh ${PRODUCT_A.vendorName}`)).toBeVisible();

        const addToCartButton = page.getByRole('button', { name: 'Tambah ke Keranjang' });
        await expect(addToCartButton).toBeVisible();

        await addProductAToCart(page);

        const table = page.getByRole('table', { name: 'Isi keranjang' });
        await expect(table).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lanjut ke pembayaran' })).toBeVisible();

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('a guest completes checkout and submits manual payment proof', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        await expect(page.getByRole('heading', { level: 1, name: 'Checkout' })).toBeVisible();

        await placeOrder(page, { areaLabel: 'Jakarta Selatan' });

        const trackLink = page.getByRole('link', { name: 'Lacak pesanan' });
        await expect(trackLink).toBeVisible();

        // Manual payment section appears in place, same page, no navigation.
        await expect(page.getByRole('heading', { name: 'Pembayaran transfer manual' })).toBeVisible();
        await page.getByLabel('Nomor referensi transfer').fill('TRX-E2E-MKT-MOBILE-0001');

        const submitProofButton = page.getByRole('button', { name: 'Kirim bukti transfer' });
        await submitProofButton.click();

        // See e2e-marketplace.spec.ts's identical wait for the rationale:
        // Livewire disables a wire:submit form's own button for the
        // duration of the request and only re-enables it once the full
        // round trip (server call + DOM morph) actually completes, so
        // waiting for "enabled again" is a real barrier before asserting
        // the outcome below.
        await expect(submitProofButton).toBeEnabled();
        await expect(page.getByText('Bukti transfer belum terkirim')).toHaveCount(0);

        await trackLink.click();
        await page.waitForURL(/\/marketplace\/pesanan\//);
        await expect(page.getByRole('heading', { level: 1, name: 'Status Pesanan' })).toBeVisible();

        // order-tracking.blade.php shows two distinct status rows, never
        // merged into one badge (AC12) — same pair
        // e2e-marketplace.spec.ts's desktop checkout test asserts. It does
        // NOT render the recipient's name (confirmed directly against the
        // Blade source above), so that is not asserted here.
        await expect(page.getByText('Pembayaran', { exact: true })).toBeVisible();
        await expect(page.getByText('Proses vendor', { exact: true })).toBeVisible();

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});
