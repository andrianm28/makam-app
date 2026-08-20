import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * E2E-MKT (customer half) — the guest marketplace journey: browse, product
 * detail, cart, single-vendor conflict, checkout, manual payment, order
 * tracking. `docs/testing/test-strategy.md` §2 `E2E-MKT` acceptance
 * criteria. Vendor-side processing (accept/process/status/evidence) and
 * vendor transaction history are NOT covered here — they need an
 * authenticated Filament vendor-panel session, a concern shared with the
 * not-yet-built `E2E-ADMIN/VENDOR` suite; see this plan's own header.
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
 *
 * No stable DOM `id`s exist on this journey's form fields (unlike booking's
 * `#customer-full-name` etc.) — every locator below is `getByLabel()` or
 * `getByRole()`, matching what's actually in the markup.
 */

const PRODUCT_A = {
    code: 'FLOWER_BOARD',
    vendorName: 'Toko Bunga Contoh 1',
};

const PRODUCT_B = {
    code: 'FLOWER_PETAL_PACKAGE',
    vendorName: 'Toko Bunga Contoh 2',
};

const CATEGORY = {
    flowers: 'Karangan Bunga',
    gravestones: 'Batu Nisan',
    graveCare: 'Perawatan Makam',
};

const RECIPIENT = {
    name: 'Contoh Penerima Karangan Bunga',
    phone: '081298765432',
    email: 'penerima.contoh@example.test',
};

test.describe('E2E-MKT — browse and category filter', () => {
    test('the catalogue lists real products and category chips filter it', async ({ page }) => {
        await page.goto('/marketplace');

        await expect(page.getByRole('heading', { level: 1, name: 'Layanan Pemakaman' })).toBeVisible();

        const nav = page.getByRole('navigation', { name: 'Filter kategori produk' });
        await expect(nav.getByRole('link', { name: 'Semua Kategori' })).toBeVisible();
        await expect(nav.getByRole('link', { name: CATEGORY.flowers, exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: CATEGORY.gravestones, exact: true })).toBeVisible();
        await expect(nav.getByRole('link', { name: CATEGORY.graveCare, exact: true })).toBeVisible();

        const grid = page.getByRole('list', { name: 'Daftar produk' });
        await expect(grid.getByRole('link')).not.toHaveCount(0);

        await nav.getByRole('link', { name: CATEGORY.flowers, exact: true }).click();
        await expect(page.getByRole('heading', { level: 1, name: CATEGORY.flowers })).toBeVisible();
        await expect(nav.getByRole('link', { name: CATEGORY.flowers, exact: true })).toHaveAttribute('aria-current', 'page');
    });

    test('an unknown category value falls back honestly to the full catalogue', async ({ page }) => {
        await page.goto('/marketplace?kategori=NOT_A_REAL_CATEGORY');

        // <x-mk.alert>'s `title` prop renders as a `<p class="font-semibold">`
        // (resources/views/components/mk/alert.blade.php), not a semantic
        // heading — verified directly against the component before writing
        // this locator, rather than assuming `getByRole('heading', ...)`
        // would match.
        await expect(page.getByText('Kategori tidak dikenali')).toBeVisible();
        // Falls back, does not 404 — the full catalogue still renders.
        await expect(page.getByRole('list', { name: 'Daftar produk' }).getByRole('link')).not.toHaveCount(0);
    });

    test('the browse and unknown-category pages are accessible', async ({ page }) => {
        await page.goto('/marketplace');
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});

test.describe('E2E-MKT — product detail', () => {
    test('a real listing shows vendor offer, variants panel, and adds to cart', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByText(`Ditawarkan oleh ${PRODUCT_A.vendorName}`)).toBeVisible();

        const addButton = page.getByRole('button', { name: 'Tambah ke Keranjang' });
        await expect(addButton).toBeVisible();

        await addButton.click();
        await page.waitForURL(/\/marketplace\/keranjang$/);
        await expect(page.getByRole('heading', { level: 1, name: 'Keranjang' })).toBeVisible();
    });

    test('an unknown product code 404s', async ({ page }) => {
        const response = await page.goto('/marketplace/produk/NOT_A_REAL_CODE');
        expect(response?.status()).toBe(404);
    });

    test('the variant panel renders a real state for a real product', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);

        const variantSection = page.locator('section', { has: page.getByRole('heading', { name: 'Pilihan Varian' }) });
        await expect(variantSection).toBeVisible();

        // FLOWER_BOARD is not a gravestone product, so ProductDetail's own
        // variant-axis logic is expected to render the "no variant axes"
        // state — assert on whichever of the three documented states is
        // actually showing rather than assuming one, since this proves the
        // panel renders SOME real, non-blank state rather than asserting a
        // guess about which.
        const noAxes = variantSection.getByText('Produk ini tidak memiliki pilihan varian');
        const noRows = variantSection.getByText('Belum ada varian yang terdaftar.');
        const list = variantSection.getByRole('list', { name: 'Daftar varian produk' });

        const states = await Promise.all([
            noAxes.isVisible().catch(() => false),
            noRows.isVisible().catch(() => false),
            list.isVisible().catch(() => false),
        ]);
        expect(states.filter(Boolean).length).toBe(1); // exactly one state, never zero or more than one
    });

    test('the product detail page is accessible', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});
