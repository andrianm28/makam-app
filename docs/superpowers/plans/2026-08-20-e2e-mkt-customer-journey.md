# E2E-MKT Customer Journey Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the durable `tests/browser/e2e-marketplace.spec.ts` Playwright suite covering the full guest-customer marketplace journey — browse → product detail → cart → single-vendor conflict → checkout → manual payment → order tracking — against real seeded fixture data, matching the existing `E2E-HOME`/`E2E-FAQ`/`E2E-REN`/`E2E-BOOK` suites' conventions.

**Architecture:** One new spec file, `tests/browser/e2e-marketplace.spec.ts`, auto-discovered by `playwright.config.ts`'s existing `testDir: './tests/browser'` (no config or CI wiring changes needed — `.github/workflows/ci.yml`'s `browser-test` job already runs `npm run test:browser`, the whole directory). Helper functions per journey stage, `test.step()` blocks, `AxeBuilder` accessibility scans on every distinct page state, following `tests/browser/e2e-booking.spec.ts`'s established style exactly.

**Tech Stack:** Playwright (`@playwright/test`), `@axe-core/playwright`, TypeScript. No new dependencies.

**Spec:** `docs/testing/test-strategy.md` §2 `E2E-MKT` (acceptance criteria), `docs/testing/release-gates.md` §A (bullet 9, "Marketplace categories and vendor processing pass") and §E (Marketplace/vendor). This plan implements the **customer-facing half** of `E2E-MKT`'s six bullets (exact category/product coverage, product variant/cart/service-area/schedule/fee, single-vendor cart constraint, online/manual checkout, customer tracking). The remaining two bullets — "Vendor accept/process/status/evidence" and "Customer... vendor transaction history" — need an authenticated Filament-panel session, a shared concern with the not-yet-built `E2E-ADMIN/VENDOR` suite; deferred to that suite's own follow-up plan rather than solved ad hoc here (see "What this plan does NOT cover" below). §A bullet 9 and §E's vendor-processing boxes stay unchecked after this plan; §E's "Customer order tracking passes" and the seeded-catalogue/single-vendor-constraint boxes are the ones this plan actually earns.

## Global Constraints

- Real seeded fixture data only — no invented selectors or values. Every value below is read directly from source (cited per task), the same discipline `e2e-booking.spec.ts`'s own header documents.
- Every new page state gets an `AxeBuilder` scan (`docs/testing/release-gates.md` §B, "Loading, empty, error, pending, success, and support states reviewed").
- Prefer `getByRole`/`getByLabel`/exact visible text over CSS classes — matches this suite's existing convention and survives incidental markup changes.
- The marketplace online-payment path is a documented, deliberate dead end for marketplace orders as of this codebase state (`AuthorizeOrderPaymentOpening`-equivalent guard refuses `OrderType::Marketplace`) — this plan tests that it fails *honestly* (a named error, never a silent hang or a false success), not that it succeeds. Do not attempt to work around it or treat it as a bug to fix.
- Never quote or invent DOM `id` attributes not confirmed present in the Blade source — this suite's fields have no stable `id`s (unlike booking's `#customer-full-name` etc.), so every task below uses `getByLabel()`/`getByRole()` instead.

## Fixture reference (verified against `app/Support/ExampleData/VendorListingExampleData.php` and `app/Domain/Marketplace/ProductCode.php`, deterministic across every environment that has run migrations)

```
Product index 0: FLOWER_BOARD           → vendor index 0 → "Toko Bunga Contoh 1"
Product index 1: FLOWER_PETAL_PACKAGE   → vendor index 1 → "Toko Bunga Contoh 2"
Product index 2: GRAVESTONE_GRANITE     → vendor index 2 → "Toko Bunga Contoh 3"

Every vendor (0-4) serves the same 3 service areas:
  EX-JKT-01  "Jakarta Pusat"    delivery fee = 0 + vendor_index * 25_000
  EX-JKT-02  "Jakarta Selatan"  delivery fee = 150_000 + vendor_index * 25_000
  EX-JKT-03  "Jakarta Timur"    delivery fee = 200_000 + vendor_index * 25_000

Category labels (MarketplaceProductCategory::label()):
  FLOWERS      → "Karangan Bunga"
  GRAVESTONES  → "Batu Nisan"
  GRAVE_CARE   → "Perawatan Makam"
```

`FLOWER_BOARD` (vendor 0) is this plan's primary happy-path product — `AvailabilityMode::KNOWN[0 % 3]` and `EvidenceRequirement::KNOWN[0 % 3]`, the first entries of each closed list, keeping the fixture as simple as the seed allows. `FLOWER_PETAL_PACKAGE` (vendor 1) is the second product for the single-vendor-conflict task — a different vendor than `FLOWER_BOARD`, confirmed by `vendor_index = product_index % 5`.

---

### Task 1: Browse and product detail

**Files:**
- Create: `tests/browser/e2e-marketplace.spec.ts` (this task starts the file; later tasks append to it)

**Interfaces:**
- Produces: `MARKETPLACE` fixture constants (product codes, vendor names, category labels) other tasks in this file import nothing across files — this is all one spec file, so later tasks just reference the same top-of-file constants this task defines.

- [ ] **Step 1: Write the file header and fixture constants**

```typescript
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
```

- [ ] **Step 2: Write the browse/category-filter test**

```typescript
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

        await expect(page.getByRole('heading', { name: 'Kategori tidak dikenali' })).toBeVisible();
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
```

- [ ] **Step 3: Write the product-detail test (real listing + add to cart)**

```typescript
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
```

- [ ] **Step 4: Run the file to verify Steps 2-3 pass against a real server**

Run (against a locally-served app on `PLAYWRIGHT_BASE_URL`, matching how this suite's siblings are verified — see `docs/testing/dev-staging-environment.md` or this repo's own established pattern of pointing `PLAYWRIGHT_BASE_URL` at `https://dev.makam.co.id`, never a raw container port):

```bash
npx playwright test tests/browser/e2e-marketplace.spec.ts -g "browse|product detail" --reporter=list
```

Expected: all tests in `E2E-MKT — browse and category filter` and `E2E-MKT — product detail` pass. If the add-to-cart test fails at the `waitForURL` step, first confirm `FLOWER_BOARD`'s listing is actually active in whatever database the run targets (`SELECT is_active FROM vendor_listings WHERE product_id = (SELECT id FROM products WHERE code = 'FLOWER_BOARD')`) before assuming a code defect — this fixture depends on the seed migration having run.

- [ ] **Step 5: Commit**

```bash
git add tests/browser/e2e-marketplace.spec.ts
git commit -m "test(e2e-mkt): browse, category filter, and product detail journey"
```

---

### Task 2: Cart screen and single-vendor conflict

**Files:**
- Modify: `tests/browser/e2e-marketplace.spec.ts` (append)

**Interfaces:**
- Consumes: `PRODUCT_A`, `PRODUCT_B`, `CATEGORY` constants from Task 1.

- [ ] **Step 1: Write the empty-cart and populated-cart test**

```typescript
test.describe('E2E-MKT — cart', () => {
    test('the cart is empty before anything is added', async ({ page }) => {
        await page.goto('/marketplace/keranjang');

        await expect(page.getByText('Keranjang Anda masih kosong')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lihat katalog' })).toBeVisible();
    });

    test('an added item appears in the cart with a working remove control', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await page.waitForURL(/\/marketplace\/keranjang$/);

        const table = page.getByRole('table', { name: 'Isi keranjang' });
        await expect(table).toBeVisible();
        await expect(page.getByText(/^Total /)).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lanjut ke pembayaran' })).toBeVisible();

        await page.getByRole('button', { name: 'Hapus' }).click();
        await expect(page.getByText('Keranjang Anda masih kosong')).toBeVisible();
    });
});
```

- [ ] **Step 2: Write the single-vendor conflict test — both resolutions**

```typescript
test.describe('E2E-MKT — single-vendor conflict', () => {
    test('adding a second vendor\'s product offers replace-or-finish, and replacing works', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await page.waitForURL(/\/marketplace\/keranjang$/);

        await page.goto(`/marketplace/produk/${PRODUCT_B.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();

        const modal = page.getByRole('dialog', { name: 'Hanya satu vendor per pesanan' });
        await expect(modal).toBeVisible();
        await expect(modal.getByText(PRODUCT_A.vendorName)).toBeVisible();
        await expect(modal.getByText(PRODUCT_B.vendorName)).toBeVisible();

        await modal.getByRole('button', { name: 'Ganti keranjang' }).click();
        await page.waitForURL(/\/marketplace\/keranjang$/);

        // Cart now holds exactly the incoming vendor's single item — the
        // conflict resolution replaced the cart's contents, not merged them.
        // The table has no vendor-name column (confirmed against
        // cart.blade.php: Produk/Harga satuan/Jumlah/Subtotal/actions only),
        // so row COUNT is what this can actually prove, not vendor identity
        // by name.
        const rows = page.getByRole('table', { name: 'Isi keranjang' }).getByRole('row');
        await expect(rows).toHaveCount(2); // header row + one item row
    });

    test('choosing "finish this order first" leaves the original vendor\'s cart untouched', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await page.waitForURL(/\/marketplace\/keranjang$/);

        const cartRowsBefore = await page.getByRole('table', { name: 'Isi keranjang' }).getByRole('row').count();

        await page.goto(`/marketplace/produk/${PRODUCT_B.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();

        const modal = page.getByRole('dialog', { name: 'Hanya satu vendor per pesanan' });
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: 'Selesaikan pesanan ini dulu' }).click();
        await expect(modal).toBeHidden();

        // Still on the product page, cart unchanged — go check it directly.
        await page.goto('/marketplace/keranjang');
        const cartRowsAfter = await page.getByRole('table', { name: 'Isi keranjang' }).getByRole('row').count();
        expect(cartRowsAfter).toBe(cartRowsBefore);
    });

    test('the populated cart and conflict modal are accessible', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await page.waitForURL(/\/marketplace\/keranjang$/);

        let results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await page.goto(`/marketplace/produk/${PRODUCT_B.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await expect(page.getByRole('dialog', { name: 'Hanya satu vendor per pesanan' })).toBeVisible();

        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});
```

- [ ] **Step 3: Run the file to verify Task 2's tests pass**

```bash
npx playwright test tests/browser/e2e-marketplace.spec.ts -g "cart|conflict" --reporter=list
```

Expected: all `E2E-MKT — cart` and `E2E-MKT — single-vendor conflict` tests pass.

- [ ] **Step 4: Commit**

```bash
git add tests/browser/e2e-marketplace.spec.ts
git commit -m "test(e2e-mkt): cart screen and single-vendor conflict resolution"
```

---

### Task 3: Checkout, manual payment, and order tracking

**Files:**
- Modify: `tests/browser/e2e-marketplace.spec.ts` (append)

**Interfaces:**
- Consumes: `PRODUCT_A`, `RECIPIENT` constants from Task 1.

- [ ] **Step 1: Write the full checkout-to-manual-payment happy path**

```typescript
async function addProductAToCart(page: Page): Promise<void> {
    await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
    await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
    await page.waitForURL(/\/marketplace\/keranjang$/);
}

test.describe('E2E-MKT — checkout and manual payment', () => {
    test('a guest completes checkout and submits manual payment proof', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        await expect(page.getByRole('heading', { level: 1, name: 'Checkout' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Ringkasan pesanan' })).toBeVisible();

        await page.getByLabel('Nama penerima').fill(RECIPIENT.name);
        await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);
        await page.getByLabel('Email penerima').fill(RECIPIENT.email);

        // "Jakarta Selatan" (not "Jakarta Pusat"): vendor 0's Jakarta Pusat
        // delivery fee is 0 (see this plan's fixture reference table), which
        // would make the "Ongkos kirim" line indistinguishable from a fee
        // that never rendered at all. Jakarta Selatan's non-zero fee lets
        // this test actually prove the fee line appears once an area is
        // chosen, not just that the select works.
        await page.getByLabel('Area layanan').selectOption({ label: 'Jakarta Selatan' });
        await expect(page.getByText('Ongkos kirim (Jakarta Selatan)')).toBeVisible();

        // Schedule is optional (E2E-MKT's "schedule" AC) — filling it proves
        // the field exists and accepts a real date without blocking submit.
        await page.getByLabel('Tanggal pelaksanaan (opsional)').fill('2026-09-15');

        await page.getByRole('button', { name: 'Buat pesanan' }).click();

        await expect(page.getByText('Pesanan diterima')).toBeVisible();
        const trackLink = page.getByRole('link', { name: 'Lacak pesanan' });
        await expect(trackLink).toBeVisible();
        const trackHref = await trackLink.getAttribute('href');
        expect(trackHref).toMatch(/\/marketplace\/pesanan\//);

        // Manual payment section appears in place, same page, no navigation.
        await expect(page.getByRole('heading', { name: 'Pembayaran transfer manual' })).toBeVisible();
        await page.getByLabel('Nomor referensi transfer').fill('TRX-E2E-MKT-0001');
        await page.getByRole('button', { name: 'Kirim bukti transfer' }).click();

        // Re-submitting is a no-op (idempotency) — button remains usable,
        // no duplicate order/section appears. Assert the order summary is
        // still singular, not that a specific post-submit message renders,
        // since Checkout.php's own doc block only promises the create side
        // is idempotent, not a particular UI acknowledgement copy.
        await expect(page.getByRole('heading', { name: 'Pesanan diterima' }).or(page.getByText('Pesanan diterima'))).toBeVisible();

        // Follow the tracking link and confirm both status indicators render
        // separately (AC12 — never merged).
        await trackLink.click();
        await page.waitForURL(/\/marketplace\/pesanan\//);
        await expect(page.getByRole('heading', { level: 1, name: 'Status Pesanan' })).toBeVisible();
        await expect(page.getByText('Pembayaran')).toBeVisible();
        await expect(page.getByText('Proses vendor').or(page.getByText('Menunggu vendor'))).toBeVisible();
    });

    test('placing an order with an empty cart is impossible — the button is disabled or absent', async ({ page }) => {
        await page.goto('/marketplace/checkout');

        await expect(page.getByText('Keranjang Anda kosong.')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Buat pesanan' })).toHaveCount(0);
    });

    test('an unknown order number reaches an honest not-found state, never a leak', async ({ page }) => {
        await page.goto('/marketplace/pesanan/NOT-A-REAL-ORDER-NUMBER');

        await expect(page.getByText('Pesanan tidak ditemukan')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lihat katalog' })).toBeVisible();
    });

    test('checkout, its post-order state, and order tracking are all accessible', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        let results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await page.getByLabel('Nama penerima').fill(RECIPIENT.name);
        await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);
        await page.getByLabel('Email penerima').fill(RECIPIENT.email);
        await page.getByLabel('Area layanan').selectOption({ label: 'Jakarta Selatan' });
        await page.getByRole('button', { name: 'Buat pesanan' }).click();
        await expect(page.getByText('Pesanan diterima')).toBeVisible();

        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await page.getByRole('link', { name: 'Lacak pesanan' }).click();
        await page.waitForURL(/\/marketplace\/pesanan\//);
        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});
```

- [ ] **Step 2: Run the file to verify Task 3's tests pass**

```bash
npx playwright test tests/browser/e2e-marketplace.spec.ts -g "checkout|manual payment" --reporter=list
```

Expected: all `E2E-MKT — checkout and manual payment` tests pass. If `'a guest completes checkout...'` fails at the `getByLabel('Area layanan')` step, confirm `service_areas` rows exist for vendor 0 (`SELECT * FROM service_areas WHERE vendor_id = (SELECT id FROM vendors WHERE name = 'Toko Bunga Contoh 1')`) — an empty select with zero options is a documented real state (see `VendorListingExampleData`'s own doc block: "with zero rows, the select renders empty and checkout can never validate"), not a Playwright bug, if that's what's found.

- [ ] **Step 3: Commit**

```bash
git add tests/browser/e2e-marketplace.spec.ts
git commit -m "test(e2e-mkt): checkout, manual payment, and order tracking journey"
```

---

### Task 4: Online-payment honest-failure path and whole-file accessibility pass

**Files:**
- Modify: `tests/browser/e2e-marketplace.spec.ts` (append)

**Interfaces:**
- Consumes: `addProductAToCart()` helper and `RECIPIENT` constant from Task 3.

- [ ] **Step 1: Write the online-payment test**

Per this plan's Global Constraints: online payment for marketplace orders is a documented dead end today. This test proves the failure is honest (named error, no silent hang), not that payment succeeds. It's gated so it never fails a CI run in an environment where the online-payment feature gate happens to be closed (the default) — the gate-closed banner path is asserted directly instead in that branch, both are real, valid states this journey can be in.

```typescript
test.describe('E2E-MKT — online payment path (honest failure or closed gate)', () => {
    test('online payment is either gate-closed, or fails with a named, honest error', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        await page.getByLabel('Nama penerima').fill(RECIPIENT.name);
        await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);
        await page.getByLabel('Email penerima').fill(RECIPIENT.email);
        await page.getByLabel('Area layanan').selectOption({ label: 'Jakarta Timur' });
        await page.getByRole('button', { name: 'Buat pesanan' }).click();
        await expect(page.getByText('Pesanan diterima')).toBeVisible();

        const onlineButton = page.getByRole('button', { name: 'Bayar Online' });
        const gateClosedBanner = page.getByText('Pembayaran online belum tersedia. Gunakan');

        if (await onlineButton.isVisible().catch(() => false)) {
            await onlineButton.click();
            await expect(page.getByText('Pembayaran online belum tersedia untuk pesanan marketplace')).toBeVisible();
        } else {
            await expect(gateClosedBanner).toBeVisible();
        }
    });
});
```

- [ ] **Step 2: Run the whole file once, end to end**

```bash
npx playwright test tests/browser/e2e-marketplace.spec.ts --reporter=list
```

Expected: every test in the file passes. This is the first run exercising every test in sequence rather than by name-filter — a real signal for state leakage between tests (e.g., a cart item from one test bleeding into the next via a shared browser context). If a test fails here but passed in its own task's isolated run, suspect shared state first, not a code defect: Playwright's `fullyParallel: true` (already set in `playwright.config.ts`) gives each test its own browser context by default, so leakage would point at server-side session/cookie reuse across tests in the same worker, not a Playwright config gap — read the failure carefully before proposing a fix.

- [ ] **Step 3: Run the file against CI's real environment shape (real Postgres, not SQLite)**

Per this session's own established lesson (SQLite silently masks Postgres-specific bugs — see `feedback_verify_against_real_db_not_sqlite` memory / this plan's parent plan's own `HANDOFF STATUS` section), do not treat a pass against a locally-served SQLite-backed app as sufficient. Verify against the pinned CI image + a real disposable Postgres 18 container, the pattern already established this session:

```bash
# (abbreviated — see this repo's own CI-repro pattern from earlier this
# session for the full disposable-Postgres + pinned-image setup)
npx playwright test tests/browser/e2e-marketplace.spec.ts --reporter=list
```

Expected: PASS. Record the actual command and container setup used in the task's completion report — do not report PASS without having run it for real, per `AGENTS.md` §Infrastructure-agent execution.

- [ ] **Step 4: Commit**

```bash
git add tests/browser/e2e-marketplace.spec.ts
git commit -m "test(e2e-mkt): online-payment honest-failure path"
```

---

## What this plan does NOT cover

- **Vendor order processing** (accept/reject/process/send-schedule/complete/complain via the Filament vendor panel) and **vendor transaction history** — both need an authenticated `/vendor` panel session. No committed, reusable mechanism exists yet in this repo for a Playwright test to log in as a seeded vendor user (the `.uat-creds.json`-style pattern the parent plan references was a manual, uncommitted one-off from an earlier UAT pass). Solving that authentication seam once, properly — likely a dedicated artisan command or a test-only provisioning route, gated so it can never run against a real production environment — is shared infrastructure the future `E2E-ADMIN/VENDOR` suite also needs, and belongs in that suite's own plan, not duplicated here.
- **Cart's stale-pricing banner** (`hasStalePricing`) — reachable only by a vendor's listing price changing *after* an item is added to cart, which needs a backend price mutation mid-session. Not triggerable through pure browser interaction without either the same vendor-auth seam (to edit the listing via the panel) or a test-only backdoor route — deferred alongside the vendor-processing suite. This scenario is already covered at the Feature-test level (`CartScreenTest`, confirmed present in the research this plan is based on) — this gap is in *browser-level* E2E coverage only, not business-logic coverage.
- **`Cart::updateQuantity()`** — confirmed via source inspection to have no reachable UI trigger in the current Blade view (quantity renders as plain text, not an editable input). Do not write a test expecting a quantity input in the cart table; there isn't one to test. If this becomes a real gap worth closing, it's a product/UI decision (add a quantity control), not a test-writing task.
- **Updating `docs/testing/release-gates.md` checkboxes** — deliberately left to the later, dedicated Phase G closure pass once more of `E2E-MKT`/`E2E-ADMIN-VENDOR` exists, per the parent plan's own sequencing ("`Phase G` ... once Phase E's suites exist, walk §A/§B against them properly"). Checking §A bullet 9 now would be checking a box this plan only half-earns.

## Verification

| What | How | Pass condition |
|---|---|---|
| Every task | `npx playwright test tests/browser/e2e-marketplace.spec.ts -g "<task's test group>"` | All tests in that group pass |
| Whole file, in sequence | `npx playwright test tests/browser/e2e-marketplace.spec.ts` | Every test passes, no cross-test state leakage |
| Real environment shape | Same command, run against the pinned CI image + a real disposable Postgres 18 container (Task 4 Step 3) | PASS, actually executed — never reported without running |
| No regressions | Existing `tests/browser/*.spec.ts` siblings still pass | `npx playwright test` (whole directory) stays green |
| Accessibility | Every distinct page state gets an `AxeBuilder` scan with `expect(violations).toEqual([])` | Zero violations on every scanned state |

## Execution notes

Superpowers SDD, worktree-isolated, one PR for this whole suite (all 4 tasks are pieces of one file / one journey — a single reviewable unit, not four separate PRs, following the existing `E2E-BOOK` suite's own precedent of landing as one PR despite being a long file). Task-scoped review after each task's commit, whole-branch review before the PR. No production code changes in this plan — test-only. Security/authorization review is not required (no security-sensitive code touched), but the plan's author should still watch this suite's first real CI run rather than assume local-only verification is sufficient, per this session's own repeated lesson about SQLite vs. real Postgres.
