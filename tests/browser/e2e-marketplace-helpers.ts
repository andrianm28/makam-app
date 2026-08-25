import { expect, type Page } from '@playwright/test';

/**
 * Shared fixtures and step helpers for the marketplace E2E suites
 * (`e2e-marketplace.spec.ts` desktop, `e2e-marketplace-mobile.spec.ts`
 * mobile). Extracted to a plain, non-`.spec.ts` module rather than one spec
 * file importing the other — this repo's pinned Playwright (v1.62.1)
 * hard-forbids one test file importing another test file ("test file X
 * should not import test file Y", enforced unconditionally whenever both
 * files are matched by any project in the run — no config override exists).
 * Same constraint and same fix already applied by
 * `e2e-booking-helpers.ts`/`e2e-booking-mobile.spec.ts` — see that file's
 * own header comment.
 *
 * Real fixture data only — no invented selectors or values:
 *   - Products/vendors/service areas: App\Support\ExampleData\VendorListingExampleData
 *     (seeded once by 2026_08_14_100000_seed_vendors_and_listings.php, present
 *     in every migrated environment — verified directly against the class,
 *     not guessed)
 *   - Product codes:    App\Domain\Marketplace\ProductCode::KNOWN_CODES
 *   - Category labels:  App\Domain\Marketplace\MarketplaceProductCategory::label()
 *   - Field labels/copy: app/Livewire/Public/Marketplace/*.php and their
 *     Blade views, cross-checked against existing Feature tests'
 *     assertSee() calls
 */

export const PRODUCT_A = {
    code: 'FLOWER_BOARD',
    vendorName: 'Toko Bunga Contoh 1',
};

export const PRODUCT_B = {
    code: 'FLOWER_PETAL_PACKAGE',
    vendorName: 'Toko Bunga Contoh 2',
};

export const CATEGORY = {
    flowers: 'Karangan Bunga',
    gravestones: 'Batu Nisan',
    graveCare: 'Perawatan Makam',
};

export const RECIPIENT = {
    name: 'Contoh Penerima Karangan Bunga',
    phone: '081298765432',
    email: 'penerima.contoh@example.test',
};

export async function addProductAToCart(page: Page): Promise<void> {
    await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
    await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
    await page.waitForURL(/\/marketplace\/keranjang$/);
}

/**
 * Fill the recipient form, select a service area, submit, and wait for the
 * order-placed banner. Covers the "fill recipient form → select area →
 * click Buat pesanan → expect Pesanan diterima" sequence shared by every
 * test that places a straight-through, valid order.
 */
export async function placeOrder(page: Page, options: { areaLabel: string }): Promise<void> {
    await page.getByLabel('Nama penerima').fill(RECIPIENT.name);
    await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);
    await page.getByLabel('Email penerima').fill(RECIPIENT.email);
    await page.getByLabel('Area layanan').selectOption({ label: options.areaLabel });
    await page.getByRole('button', { name: 'Buat pesanan' }).click();
    await expect(page.getByText('Pesanan diterima')).toBeVisible();
}
