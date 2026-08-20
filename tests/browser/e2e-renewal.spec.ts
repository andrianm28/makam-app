import { expect, Page, test } from '@playwright/test';

/**
 * E2E-REN — docs/testing/test-strategy.md §2. Covers the public renewal
 * journey: /perpanjangan (city, TPU/TPS) -> /perpanjangan/cari (grave
 * search) -> /perpanjangan/biaya (fee) -> /perpanjangan/pembayaran
 * (payment) -> /perpanjangan/konfirmasi (confirmation). Selectors and
 * copy are read directly from app/Livewire/Public/Renewal/*.php and
 * resources/views/livewire/public/renewal/*.blade.php, not guessed.
 *
 * ---------------------------------------------------------------------------
 * Two environment facts this suite is written around
 * ---------------------------------------------------------------------------
 * 1. `G-DATA-01` (grave search) is closed by default, exactly like
 *    `G-PAY-01` (online payment) — `database/migrations/2026_07_26_120400_
 *    seed_feature_gate_registry.php` seeds EVERY gate closed, and no other
 *    migration opens one. In CI (a fresh `php artisan migrate` every run,
 *    per `.github/workflows/ci.yml`'s browser-test job) this means
 *    `/perpanjangan/cari` renders the §6.4 gate-closed explanatory page,
 *    not the search form — the grave-search capability being closed also
 *    makes it impossible to ever open a real `Renewal` record through the
 *    public UI (`RenewalFee::terimaDanLanjutkan()` checks the same gate
 *    before writing anything), so `/perpanjangan/biaya`,
 *    `/perpanjangan/pembayaran` and `/perpanjangan/konfirmasi` can only
 *    ever be reached with a real record in a *different*, gate-open
 *    environment. This suite therefore checks gate state at runtime
 *    (`graveSearchGateClosed()` below) rather than assuming either state,
 *    so it keeps passing once `G-DATA-01` is opened for real and exercises
 *    the genuine fuzzy-search/no-result branch whenever it can.
 * 2. Nothing in the search results view
 *    (resources/views/livewire/public/renewal/grave-search.blade.php)
 *    links to `/perpanjangan/biaya` — no `route('perpanjangan.biaya')`
 *    call and no literal href to that path exist anywhere under
 *    `resources/views/`. The fee/payment/confirmation steps are reachable
 *    only by a hand-built `?makam=` / `?perpanjangan=` URL today, never by
 *    clicking through from a search result. This suite tests those steps
 *    by direct navigation for that reason, and flags the missing link as a
 *    real product gap rather than working around it.
 */

const STEP_LABELS = ['Kota', 'TPU/TPS', 'Cari Makam', 'Biaya', 'Pembayaran', 'Konfirmasi'];

function stepperGroup(page: Page) {
    return page.getByRole('group', { name: 'Progres perpanjangan makam' });
}

async function graveSearchGateClosed(page: Page): Promise<boolean> {
    await page.goto('/perpanjangan/cari');

    return page
        .getByRole('heading', { level: 1, name: 'Pencarian Data Makam Belum Tersedia' })
        .isVisible();
}

/**
 * Tries launch city buttons in order until one's TPU/TPS list yields at
 * least one published cemetery, returning that cemetery link. `selectCity`
 * is a `wire:click` action — each click needs its Livewire round-trip to
 * finish before the next city is tried, so this polls with `.waitFor()`
 * rather than a synchronous `.isVisible()` check (which reads the DOM
 * before the response lands and would race through every button without
 * ever finding the rendered list).
 */
async function findCemeteryLink(page: Page) {
    const cityButtons = page.getByRole('list', { name: 'Kota peluncuran' }).getByRole('button');
    const cemeteryLink = page.getByRole('link', { name: 'Lanjut ke Pencarian Makam' }).first();
    const cityCount = await cityButtons.count();

    for (let i = 0; i < cityCount; i++) {
        await cityButtons.nth(i).click();

        const gotCemetery = await cemeteryLink
            .waitFor({ state: 'visible', timeout: 5000 })
            .then(() => true)
            .catch(() => false);

        if (gotCemetery) return cemeteryLink;
    }

    return null;
}

test('renewal journey renders the six documented steps, in order, on every screen', async ({ page }) => {
    await page.goto('/perpanjangan');

    const items = stepperGroup(page).getByRole('listitem');
    await expect(items).toHaveCount(STEP_LABELS.length);

    const texts = await items.allTextContents();
    STEP_LABELS.forEach((label, index) => {
        expect(texts[index]).toContain(label);
    });

    // AC1: step 1 (city) is current on a bare arrival.
    const currentStepItem = items.filter({ has: page.locator('[aria-current="step"]') });
    await expect(currentStepItem.getByText('Kota', { exact: true })).toBeVisible();
});

test('city step lists all five MVP launch cities and TPU/TPS selection reaches a real cemetery', async ({ page }) => {
    await page.goto('/perpanjangan');

    // AC2 — exactly LaunchCityCode::KNOWN_CODES' five cities. This is what
    // a fresh CI database always has: only 2026_08_15_110010_seed_launch_
    // cities.php touches this table, and it inserts exactly KNOWN_CODES.
    // (A shared, long-lived dev database can drift from this — e.g. a
    // sixth city inserted directly by other exploratory work — which is a
    // fact about that database, not about what CI seeds.)
    const cityList = page.getByRole('list', { name: 'Kota peluncuran' });
    const cityButtons = cityList.getByRole('button');
    await expect(cityButtons).toHaveCount(5);

    // Not every city is guaranteed to have a published TPU/TPS in every
    // environment (docs/product/... "empty TPU/TPS" is itself an honest
    // §6.2 state, per start.blade.php) — try cities in order until one
    // yields at least one cemetery card, rather than assuming the first.
    const cemeteryLink = await findCemeteryLink(page);
    expect(cemeteryLink, 'expected at least one launch city to have a published TPU/TPS').not.toBeNull();

    // Step 2 is now current (a city has been chosen).
    const currentStepItem = stepperGroup(page)
        .getByRole('listitem')
        .filter({ has: page.locator('[aria-current="step"]') });
    await expect(currentStepItem.getByText('TPU/TPS', { exact: true })).toBeVisible();

    await cemeteryLink!.click();
    await expect(page).toHaveURL(/\/perpanjangan\/cari\?tpu=/);
});

test('grave search step is honest whether the online capability is open or closed', async ({ page }) => {
    const closed = await graveSearchGateClosed(page);

    if (closed) {
        // AC16, design-system.md §6.4 — the default, CI-guaranteed state:
        // every feature gate seeds `closed` and nothing in this repo opens
        // G-DATA-01. Never a raw 404; always this explanatory page.
        await expect(
            page.getByRole('heading', { level: 1, name: 'Pencarian Data Makam Belum Tersedia' }),
        ).toBeVisible();
        await expect(page.getByText('Ini tidak berarti data makam yang Anda cari tidak ada.')).toBeVisible();
        await expect(page.getByRole('link', { name: 'Hubungi Bantuan' })).toBeVisible();
        // Support escape hatch back into the journey (§6.10).
        await expect(page.getByRole('link', { name: 'pemilihan TPU/TPS' })).toHaveAttribute('href', '/perpanjangan');

        return;
    }

    // Gate open (not CI's default, but this suite must stay correct here
    // too): walk a real cemetery in through the UI and exercise the
    // genuine AC5 no-result state with a name guaranteed not to collide
    // with the "Contoh ..." fixture rows.
    await page.goto('/perpanjangan');
    const cemeteryLink = await findCemeteryLink(page);
    expect(cemeteryLink, 'expected at least one launch city to have a published TPU/TPS').not.toBeNull();

    await cemeteryLink!.click();
    await expect(page.getByRole('heading', { level: 1, name: 'Cari Data Makam' })).toBeVisible();

    await page.getByLabel('Nama almarhum').fill('Zzznamatidakada999xyz');
    await page.getByRole('button', { name: 'Cari Data Makam' }).click();

    // AC5 / design-system.md §6.2 — three parts: what is empty, why, what
    // to do next. Never a bare "not found".
    await expect(page.getByRole('heading', { level: 2, name: 'Data makam tidak ditemukan.' })).toBeVisible();
    await expect(
        page.getByText('Registri makam kami belum tentu lengkap', { exact: true }),
    ).toBeVisible();
    await expect(
        page.getByText('Hasil ini belum tentu berarti makam yang Anda cari tidak ada.', { exact: true }),
    ).toBeVisible();
    await expect(page.getByRole('link', { name: 'Input manual' })).toBeVisible();
    // Case-insensitive role-name matching means "Hubungi bantuan" also
    // matches the §6.10 footer's "Hubungi Bantuan" — .first() only needs
    // one of the two legitimate links to exist.
    await expect(page.getByRole('link', { name: 'Hubungi bantuan' }).first()).toBeVisible();
});

test('fee step never leaks tariff data and always gives an honest reason it cannot proceed', async ({ page }) => {
    const closed = await graveSearchGateClosed(page);

    // No `makam` at all — checked before the gate, so this is the same
    // message regardless of gate state (RenewalFee::resolveState()).
    await page.goto('/perpanjangan/biaya');
    await expect(page.getByRole('heading', { level: 1, name: 'Data makam tidak ditemukan.' })).toBeVisible();
    // Every renewal screen also carries the §6.10 footer escape hatch in
    // addition to the denial-state CTA button, so two "Hubungi Bantuan"
    // links legitimately coexist here — .first() only needs one to exist.
    await expect(page.getByRole('link', { name: 'Hubungi Bantuan' }).first()).toBeVisible();

    // A syntactically valid but nonexistent UUID — `grave_records.id` is a
    // `uuid` column (database/migrations/2026_08_08_100000_create_grave_
    // records_table.php), and a non-UUID string here throws an unhandled
    // PostgreSQL "invalid input syntax for type uuid" error (a real 500,
    // confirmed live against dev.makam.co.id) rather than resolving to the
    // honest not-found message. That is a genuine pre-existing bug in
    // RenewalFee::resolveState() — out of this batch's scope to fix
    // (browser-spec-only) — reported separately rather than worked around
    // by relaxing this test's own expectations.
    await page.goto('/perpanjangan/biaya?makam=00000000-0000-0000-0000-000000000000');

    if (closed) {
        await expect(
            page.getByRole('heading', {
                level: 1,
                name: 'Pencarian data makam secara online belum tersedia. Silakan hubungi petugas kami.',
            }),
        ).toBeVisible();
    } else {
        await expect(page.getByRole('heading', { level: 1, name: 'Data makam tidak ditemukan.' })).toBeVisible();
    }

    // Neither denial branch may render any tariff figure or source.
    await expect(page.getByText('Estimasi biaya perpanjangan')).toHaveCount(0);
    await expect(page.getByText('Sumber tarif')).toHaveCount(0);

    // The step is never removed from the stepper even when denied.
    const items = stepperGroup(page).getByRole('listitem');
    await expect(items).toHaveCount(STEP_LABELS.length);
});

test('payment and confirmation steps show an honest not-found state without a renewal reference', async ({
    page,
}) => {
    // No fixture ever creates a `renewals` row (no seed migration touches
    // that table), and the only writer, OpenRenewal, is unreachable from
    // the public UI while G-DATA-01 stays closed — so a real 'manual' or
    // 'online' payment state, and duplicate-prevention on re-submission,
    // are NOT reachable from the public UI in this environment. Both
    // screens' only reachable, honest state via direct navigation is
    // "not found" — bearer-UUID access (RenewalPayment /
    // RenewalConfirmation doc blocks) means an absent or unknown
    // reference must read as not-found, never as an error.
    for (const path of ['/perpanjangan/pembayaran', '/perpanjangan/konfirmasi']) {
        await page.goto(path);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Data perpanjangan tidak ditemukan.' }),
        ).toBeVisible();
        // Every renewal screen also carries the §6.10 footer escape hatch
        // in addition to the denial-state CTA button, so two "Hubungi
        // Bantuan" links legitimately coexist — .first() only needs one.
        await expect(page.getByRole('link', { name: 'Hubungi Bantuan' }).first()).toBeVisible();

        await page.goto(`${path}?perpanjangan=00000000-0000-0000-0000-000000000000`);
        await expect(
            page.getByRole('heading', { level: 1, name: 'Data perpanjangan tidak ditemukan.' }),
        ).toBeVisible();

        const items = stepperGroup(page).getByRole('listitem');
        await expect(items).toHaveCount(STEP_LABELS.length);
    }
});
