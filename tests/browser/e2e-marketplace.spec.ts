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

async function addProductAToCart(page: Page): Promise<void> {
    await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);
    await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
    await page.waitForURL(/\/marketplace\/keranjang$/);
}

/**
 * Fill the recipient form, select a service area, submit, and wait for the
 * order-placed banner. Covers the "fill recipient form → select area →
 * click Buat pesanan → expect Pesanan diterima" sequence shared by every
 * test that places a straight-through, valid order. Deliberately does NOT
 * cover the phone-overflow validation-failure trick used by the manual
 * payment test's first submission — that state never reaches "Pesanan
 * diterima", so it stays hand-written at its own call site.
 */
async function placeOrder(page: Page, options: { areaLabel: string }): Promise<void> {
    await page.getByLabel('Nama penerima').fill(RECIPIENT.name);
    await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);
    await page.getByLabel('Email penerima').fill(RECIPIENT.email);
    await page.getByLabel('Area layanan').selectOption({ label: options.areaLabel });
    await page.getByRole('button', { name: 'Buat pesanan' }).click();
    await expect(page.getByText('Pesanan diterima')).toBeVisible();
}

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
        let results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        // The unknown-category fallback renders an <x-mk.alert> (its own
        // role/contrast surface, not shared with the plain catalogue page
        // scanned above) — it needs its own scan, not just its own heading
        // assertion.
        await page.goto('/marketplace?kategori=NOT_A_REAL_CATEGORY');
        await expect(page.getByText('Kategori tidak dikenali')).toBeVisible();
        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});

test.describe('E2E-MKT — product detail', () => {
    test('a real listing shows vendor offer, variants panel, and adds to cart', async ({ page }) => {
        await page.goto(`/marketplace/produk/${PRODUCT_A.code}`);

        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
        await expect(page.getByText(`Ditawarkan oleh ${PRODUCT_A.vendorName}`)).toBeVisible();

        await expect(page.getByRole('button', { name: 'Tambah ke Keranjang' })).toBeVisible();

        await addProductAToCart(page);
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

test.describe('E2E-MKT — cart', () => {
    test('the cart is empty before anything is added', async ({ page }) => {
        await page.goto('/marketplace/keranjang');

        await expect(page.getByText('Keranjang Anda masih kosong')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lihat katalog' })).toBeVisible();

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('an added item appears in the cart with a working remove control', async ({ page }) => {
        await addProductAToCart(page);

        const table = page.getByRole('table', { name: 'Isi keranjang' });
        await expect(table).toBeVisible();
        // `p.text-lg` matches exactly one element here: cart.blade.php's
        // empty-cart message and its Total line both carry this class, but
        // they sit in mutually exclusive @if/@else branches today, so the
        // locator is unambiguous now — it would become ambiguous if those
        // branches were ever merged.
        await expect(page.locator('p.text-lg')).toContainText('Total');
        await expect(page.getByRole('link', { name: 'Lanjut ke pembayaran' })).toBeVisible();

        await page.getByRole('button', { name: 'Hapus' }).click();
        await expect(page.getByText('Keranjang Anda masih kosong')).toBeVisible();
    });
});

test.describe('E2E-MKT — single-vendor conflict', () => {
    test('adding a second vendor\'s product offers replace-or-finish, and replacing works', async ({ page }) => {
        await addProductAToCart(page);

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
        await addProductAToCart(page);

        // `.count()` does not auto-wait — it reads whatever is in the DOM
        // at that instant, so a rendering failure (cart table not there at
        // all) would read 0 both before and after and pass vacuously.
        // Assert the auto-waiting invariant instead: header row + one item
        // row, both before and after the conflict flow, same locator style
        // as the sibling "replace" test above.
        const rowsBefore = page.getByRole('table', { name: 'Isi keranjang' }).getByRole('row');
        await expect(rowsBefore).toHaveCount(2);

        await page.goto(`/marketplace/produk/${PRODUCT_B.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();

        const modal = page.getByRole('dialog', { name: 'Hanya satu vendor per pesanan' });
        await expect(modal).toBeVisible();
        await modal.getByRole('button', { name: 'Selesaikan pesanan ini dulu' }).click();
        await expect(modal).toBeHidden();

        // Still on the product page, cart unchanged — go check it directly.
        await page.goto('/marketplace/keranjang');
        const rowsAfter = page.getByRole('table', { name: 'Isi keranjang' }).getByRole('row');
        await expect(rowsAfter).toHaveCount(2);

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('the populated cart and conflict modal are accessible', async ({ page }) => {
        await addProductAToCart(page);

        let results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await page.goto(`/marketplace/produk/${PRODUCT_B.code}`);
        await page.getByRole('button', { name: 'Tambah ke Keranjang' }).click();
        await expect(page.getByRole('dialog', { name: 'Hanya satu vendor per pesanan' })).toBeVisible();

        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});

test.describe('E2E-MKT — checkout and manual payment', () => {
    test('a guest completes checkout and submits manual payment proof', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        await expect(page.getByRole('heading', { level: 1, name: 'Checkout' })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Ringkasan pesanan' })).toBeVisible();

        await page.getByLabel('Nama penerima').fill(RECIPIENT.name);

        // Every field on this form (checkout.blade.php) is bound with plain
        // "wire:model", never "wire:model.live" — verified directly against
        // the Blade source, not assumed from the brief — and none of the
        // recipient inputs carry an HTML `maxlength` (field.blade.php's
        // input branch renders no such attribute). So the deferred
        // selectedAreaCode value only reaches the server, and the
        // server-rendered "Ongkos kirim" line only appears, on the NEXT
        // Livewire round-trip — confirmed live: selecting the area alone
        // sends nothing over the wire, and a straight-through valid submit
        // places the order and empties the cart in that same round-trip,
        // so the fee line never has a moment to be visible on its own path.
        // An intentionally too-long phone number (over Checkout.php's own
        // `max:32` rule) forces exactly the round-trip needed without ever
        // being blocked client-side by the native `required` constraint
        // (the field itself always has non-blank text) — it fails Laravel
        // validation, so the page re-renders with the cart/order form still
        // in place and `selectedAreaCode` already committed server-side.
        await page.getByLabel('Nomor HP penerima').fill('0'.repeat(40));
        await page.getByLabel('Email penerima').fill(RECIPIENT.email);

        // "Jakarta Selatan" (not "Jakarta Pusat"): vendor 0's Jakarta Pusat
        // delivery fee is 0 (see this plan's fixture reference table), which
        // would make the "Ongkos kirim" line indistinguishable from a fee
        // that never rendered at all. Jakarta Selatan's non-zero fee lets
        // this test actually prove the fee line appears once an area is
        // chosen, not just that the select works.
        await page.getByLabel('Area layanan').selectOption({ label: 'Jakarta Selatan' });

        await page.getByRole('button', { name: 'Buat pesanan' }).click();

        // Assert the validation error is actually on screen before moving
        // on. If a future client-side `maxlength="32"` were ever added to
        // the phone field, `fill()` would silently truncate, validation
        // would pass, and the order would be placed on THIS click instead
        // of failing — this test would then fail later at the fee-line
        // assertion below with a confusing error pointing at the wrong
        // feature. Checking the validation error here first makes that
        // future failure self-explaining instead of misleading.
        // recipientPhone's `max:32` rule has no custom attribute name or
        // message (verified against Checkout.php and lang/id/validation.php)
        // — the substring below is stable across Laravel's own attribute
        // formatting of "recipientPhone".
        await expect(page.getByText('tidak boleh lebih dari 32 karakter')).toBeVisible();
        await expect(page.getByText('Ongkos kirim (Jakarta Selatan)')).toBeVisible();

        // This validation-error state (recipient/area fields intact,
        // phone error visible, fee line rendered) is a distinct DOM state
        // from the empty checkout form and the post-order success state —
        // it needs its own accessibility scan.
        const validationErrorResults = await new AxeBuilder({ page }).analyze();
        expect(validationErrorResults.violations).toEqual([]);

        // Correct the phone number to the real fixture value before the
        // actual submit.
        await page.getByLabel('Nomor HP penerima').fill(RECIPIENT.phone);

        // Schedule is optional (E2E-MKT's "schedule" AC) — filling it proves
        // the field exists and accepts a real date without blocking submit.
        await page.getByLabel('Tanggal pelaksanaan (opsional)').fill('2026-09-15');

        await page.getByRole('button', { name: 'Buat pesanan' }).click();

        // checkout.blade.php's success banner is an <x-mk.alert title="Pesanan
        // diterima">, and alert.blade.php renders `title` as a plain
        // `<p class="font-semibold">`, never a heading — verified directly
        // against the component source, not assumed from the brief.
        // getByText is the correct locator here, not getByRole('heading').
        await expect(page.getByText('Pesanan diterima')).toBeVisible();
        const trackLink = page.getByRole('link', { name: 'Lacak pesanan' });
        await expect(trackLink).toBeVisible();
        const trackHref = await trackLink.getAttribute('href');
        expect(trackHref).toMatch(/\/marketplace\/pesanan\//);

        // Manual payment section appears in place, same page, no navigation.
        await expect(page.getByRole('heading', { name: 'Pembayaran transfer manual' })).toBeVisible();
        await page.getByLabel('Nomor referensi transfer').fill('TRX-E2E-MKT-0001');

        const submitProofButton = page.getByRole('button', { name: 'Kirim bukti transfer' });
        await submitProofButton.click();

        // Livewire's `SupportDisablingFormsDuringRequest` feature (verified
        // directly against vendor/livewire/livewire/dist/livewire.js, not
        // assumed — it isn't documented in the PHP-side source tree) disables
        // every `wire:submit` form's own submit button for the DURATION of
        // the request, and only re-enables it once the commit's response
        // phase completes — i.e. once the full round trip (server call + DOM
        // morph) is actually done, not just once the click event fired.
        // Waiting for this button to become enabled again is a real barrier:
        // it forces the assertions below to run AFTER submitManualProof()
        // has genuinely returned, so the negative assertion that follows
        // isn't just trivially true because the round trip hasn't happened
        // yet.
        await expect(submitProofButton).toBeEnabled();

        // Checkout.php's submitManualProof() (read directly against the
        // source, not assumed) has exactly two outcomes once that round trip
        // completes: `$manualSubmissionError` stays null — the only way that
        // happens is that `SubmitManualPayment::submit()` inside the try
        // block ran to completion and wrote its `payment_verifications` row,
        // since nothing else in the method's body is reachable without going
        // through that call — or the catch(Throwable) branch set it to the
        // fixed failure copy and checkout.blade.php renders the danger
        // alert below the field. Nothing else in the component's rendered
        // output changes on success (the reference field is not cleared, no
        // success badge exists — moving `payment_state` is deliberately
        // deferred to the verifier lane per SubmitManualPayment's own doc
        // block, so the order-tracking badge doesn't move either), so the
        // absence of this alert — checked only now that the round trip is
        // guaranteed complete — is the one real, meaningful success signal
        // available anywhere in this screen's DOM. This replaces the
        // previous assertion here, which only re-checked the order-placed
        // banner from several lines earlier and would have stayed green even
        // if manual payment submission silently failed every time.
        await expect(page.getByText('Bukti transfer belum terkirim')).toHaveCount(0);

        // The order-placed banner and tracking link are still the same ones
        // from before the manual-proof submit — a full-page regression from
        // that submit would fail this too.
        await expect(page.getByRole('heading', { name: 'Pesanan diterima' }).or(page.getByText('Pesanan diterima'))).toBeVisible();

        // Follow the tracking link and confirm both status indicators render
        // separately (AC12 — never merged).
        await trackLink.click();
        await page.waitForURL(/\/marketplace\/pesanan\//);
        await expect(page.getByRole('heading', { level: 1, name: 'Status Pesanan' })).toBeVisible();

        // getByText matches case-insensitively by default, and the
        // site-wide beta banner (present on every page) contains the
        // lowercase substring "pembayaran" in its own copy — verified live:
        // an unqualified getByText('Pembayaran') hits both that banner
        // paragraph and this row's label span, a strict-mode violation.
        // exact: true scopes this to the "Pembayaran" status row label.
        await expect(page.getByText('Pembayaran', { exact: true })).toBeVisible();

        // PlaceMarketplaceOrder always creates exactly one vendorOrders row
        // (status VendorProcessingStatus::MENUNGGU_VENDOR) in the same
        // transaction that places the order — verified directly against
        // PlaceMarketplaceOrder.php, not assumed from the brief — so
        // OrderTracking's `$vendorStatus` is never null on this path and
        // order-tracking.blade.php's "Menunggu vendor" fallback span never
        // renders here. The badge shows StatusIntent::label()'s humanized
        // form, "Menunggu Vendor" (confirmed live), which happens to
        // case-insensitively substring-match the brief's originally
        // proposed `getByText('Menunggu vendor')` fallback check — an
        // `.or()` between the two would incorrectly resolve to both this
        // badge AND the always-present "Proses vendor" row label at once
        // (a strict-mode violation, confirmed live), so assert both
        // real, distinct pieces of text directly instead.
        await expect(page.getByText('Proses vendor', { exact: true })).toBeVisible();
        await expect(page.getByText('Menunggu Vendor', { exact: true })).toBeVisible();
    });

    test('placing an order with an empty cart is impossible — the button is disabled or absent', async ({ page }) => {
        await page.goto('/marketplace/checkout');

        await expect(page.getByText('Keranjang Anda kosong.')).toBeVisible();

        // checkout.blade.php renders "Buat pesanan" unconditionally whenever
        // `! $orderPlaced` — it is bound via
        // `:disabled="$items->isEmpty() || $hasStalePricing"` on
        // <x-mk.button>, not wrapped in an `@if ($items->isNotEmpty())`.
        // button.blade.php then renders a real `<button disabled>`, which
        // still has an accessible name and role — `toHaveCount(0)` would be
        // wrong here (verified directly against both Blade sources, not
        // assumed from the brief). Assert the disabled state instead.
        await expect(page.getByRole('button', { name: 'Buat pesanan' })).toBeDisabled();

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('an unknown order number reaches an honest not-found state, never a leak', async ({ page }) => {
        await page.goto('/marketplace/pesanan/NOT-A-REAL-ORDER-NUMBER');

        await expect(page.getByText('Pesanan tidak ditemukan')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Lihat katalog' })).toBeVisible();

        const results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });

    test('checkout, its post-order state, and order tracking are all accessible', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        let results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await placeOrder(page, { areaLabel: 'Jakarta Selatan' });

        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);

        await page.getByRole('link', { name: 'Lacak pesanan' }).click();
        await page.waitForURL(/\/marketplace\/pesanan\//);
        results = await new AxeBuilder({ page }).analyze();
        expect(results.violations).toEqual([]);
    });
});

test.describe('E2E-MKT — online payment path (honest failure or closed gate)', () => {
    test('online payment is either gate-closed, or fails with a named, honest error', async ({ page }) => {
        await addProductAToCart(page);
        await page.getByRole('link', { name: 'Lanjut ke pembayaran' }).click();
        await page.waitForURL(/\/marketplace\/checkout$/);

        await placeOrder(page, { areaLabel: 'Jakarta Timur' });

        const onlineButton = page.getByRole('button', { name: 'Bayar Online' });
        const gateClosedBanner = page.getByText('Pembayaran online belum tersedia. Gunakan');

        if (await onlineButton.isVisible().catch(() => false)) {
            // G-PAY-01 open branch: only reachable if a future run seeds
            // the gate open (`database/migrations/
            // 2026_07_26_120400_seed_feature_gate_registry.php` seeds it
            // closed, and nothing in this suite opens it, so this branch is
            // never exercised by this test's own CI runs today — it is
            // still asserted honestly rather than assumed unreachable).
            //
            // Checkout::payOnline() (read directly against the source, not
            // assumed from the plan) maps EVERY
            // `PaymentSessionOpeningDeniedException` from
            // `GuardMarketplacePaymentOpening` — regardless of which of its
            // four internal conditions actually denied — to this one fixed,
            // internal-detail-free Indonesian copy. In this environment the
            // guard's own binding condition denies first (blank
            // `PAYMENT_MERCHANT_REF`/`PAYMENT_BADAN_USAHA_REF` — see the
            // env-vars file), but the copy on screen is the same regardless
            // of which condition denied, so asserting the fixed copy is the
            // correct, non-leaking check.
            await onlineButton.click();
            await expect(page.getByText('Pembayaran online belum dapat dibuka saat ini. Gunakan transfer manual atau hubungi dukungan.')).toBeVisible();
        } else {
            await expect(gateClosedBanner).toBeVisible();
        }
    });
});
