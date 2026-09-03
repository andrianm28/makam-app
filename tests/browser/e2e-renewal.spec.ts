import { expect, Page, test } from '@playwright/test';

/**
 * E2E-REN — docs/testing/test-strategy.md §2. Covers the public renewal
 * journey: /perpanjangan (city, TPU/TPS, grave search — Task 4 of
 * docs/superpowers/plans/2026-08-29-wizard-screen-consolidation.md merged
 * these three into ONE progressively-revealed screen; there is no longer a
 * separate `/perpanjangan/cari` route) -> /perpanjangan/pembayaran (fee +
 * payment — Task 5 of the same plan merged `RenewalFee`'s former
 * `/perpanjangan/biaya` route into this one, same progressive-reveal
 * pattern: the fee section renders first, and only an explicit "Terima
 * Tarif" click reveals payment IN PLACE, no navigation) ->
 * /perpanjangan/konfirmasi (confirmation). Selectors and copy are read
 * directly from app/Livewire/Public/Renewal/*.php and
 * resources/views/livewire/public/renewal/*.blade.php, not guessed.
 *
 * ---------------------------------------------------------------------------
 * Two environment facts this suite is written around
 * ---------------------------------------------------------------------------
 * 1. `G-DATA-01` (grave search) is closed by default, exactly like
 *    `G-PAY-01` (online payment) — `database/migrations/2026_07_26_120400_
 *    seed_feature_gate_registry.php` seeds EVERY gate closed, and no other
 *    migration opens one. In CI (a fresh `php artisan migrate` every run,
 *    per `.github/workflows/ci.yml`'s browser-test job) this means Step 3
 *    of `/perpanjangan` (revealed once a TPU/TPS is selected) renders the
 *    §6.4 gate-closed explanatory page, not the search form — the
 *    grave-search capability being closed also makes it impossible to
 *    ever open a real `Renewal` record through the public UI
 *    (`RenewalPayment::terimaDanLanjutkan()` checks the same gate before
 *    writing anything), so `/perpanjangan/pembayaran`'s fee section and
 *    `/perpanjangan/konfirmasi` can only ever be reached with a real record
 *    in a *different*, gate-open environment. This suite therefore checks
 *    gate state at runtime (`graveSearchGateClosed()` below) rather than
 *    assuming either state, so it keeps passing once `G-DATA-01` is opened
 *    for real and exercises the genuine fuzzy-search/no-result branch
 *    whenever it can.
 * 2. Grave selection is session-only, never a URL parameter
 *    (`App\Domain\Renewal\RenewalGraveSelection`'s own doc block) — Task 5
 *    removed the fee section's former `?makam=` query parameter entirely,
 *    so there is no hand-built URL that can reach the fee section anymore.
 *    A search result's forward control (`RenewalStart::
 *    selectGraveForRenewal()`) remembers the selection server-side and
 *    redirects to `/perpanjangan/pembayaran` with no id in the URL at all.
 *    This suite therefore cannot reach the fee section by direct navigation
 *    (unlike before Task 5); the fee-section-specific browser coverage
 *    below is limited to what direct navigation to `/perpanjangan/
 *    pembayaran` can still exercise honestly — the not-found state when
 *    nothing has been selected.
 */

const STEP_LABELS = ['Cari Makam', 'Biaya & Bayar', 'Konfirmasi'];

function stepperGroup(page: Page) {
    return page.getByRole('group', { name: 'Progres perpanjangan makam' });
}

/**
 * Selects the first reachable TPU/TPS (see `findCemeteryButton()` below)
 * and reports whether Step 3's gate-closed explanatory page rendered.
 * Step 3 is now revealed on the SAME `/perpanjangan` page rather than a
 * separate route/navigation (Task 4's merge), so — unlike the old
 * `page.goto('/perpanjangan/cari')` this replaces — the gate-closed
 * heading is not necessarily in the DOM the instant the click's Livewire
 * round-trip is sent. This waits for Step 3's OWN heading first (rendered
 * unconditionally in both gate states, since it sits outside the
 * `@if ($gateClosed)` branch in start.blade.php) as the settle point,
 * then checks which of the two states underneath it actually rendered.
 */
async function graveSearchGateClosed(page: Page): Promise<boolean> {
    await page.goto('/perpanjangan');

    const cemeteryButton = await findCemeteryButton(page);
    expect(cemeteryButton, 'expected at least one launch city to have a published TPU/TPS').not.toBeNull();

    await cemeteryButton!.click();
    await expect(page.getByRole('heading', { level: 2, name: /Cari Makam/ })).toBeVisible();

    return page
        .getByRole('heading', { level: 1, name: 'Pencarian Data Makam Belum Tersedia' })
        .isVisible();
}

/**
 * Tries launch city buttons in order until one's TPU/TPS list yields at
 * least one published cemetery, returning that cemetery's forward control.
 * `selectCity` is a `wire:click` action — each click needs its Livewire
 * round-trip to finish before the next city is tried, so this polls with
 * `.waitFor()` rather than a synchronous `.isVisible()` check (which reads
 * the DOM before the response lands and would race through every button
 * without ever finding the rendered list).
 *
 * `role: 'button'`, not `'link'`: pre-merge, this control was an
 * `<a href="/perpanjangan/cari?tpu=...">` (a real navigation). Task 4's
 * merged `start.blade.php` renders it as `<x-mk.button wire:click=
 * "selectCemetery(...)">` with no `href` — a real `<button>` that reveals
 * Step 3 on the same page — so the accessible role genuinely changed, not
 * just the selector's convenience.
 */
async function findCemeteryButton(page: Page) {
    const cityButtons = page.getByRole('list', { name: 'Kota peluncuran' }).getByRole('button');
    const cemeteryButton = page.getByRole('button', { name: 'Lanjut ke Pencarian Makam' }).first();
    const cityCount = await cityButtons.count();

    for (let i = 0; i < cityCount; i++) {
        await cityButtons.nth(i).click();

        const gotCemetery = await cemeteryButton
            .waitFor({ state: 'visible', timeout: 5000 })
            .then(() => true)
            .catch(() => false);

        if (gotCemetery) return cemeteryButton;
    }

    return null;
}

test('renewal journey renders the three documented steps, in order, on every screen', async ({ page }) => {
    await page.goto('/perpanjangan');

    const items = stepperGroup(page).getByRole('listitem');
    await expect(items).toHaveCount(STEP_LABELS.length);

    const texts = await items.allTextContents();
    STEP_LABELS.forEach((label, index) => {
        expect(texts[index]).toContain(label);
    });

    // AC1: step 1 ("Cari Makam") is current on a bare arrival.
    const currentStepItem = items.filter({ has: page.locator('[aria-current="step"]') });
    await expect(currentStepItem.getByText('Cari Makam', { exact: true })).toBeVisible();
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
    const cemeteryButton = await findCemeteryButton(page);
    expect(cemeteryButton, 'expected at least one launch city to have a published TPU/TPS').not.toBeNull();

    // Screen 1 is a single journey step ("Cari Makam") regardless of how
    // much of its own city/TPU-TPS/search sub-flow is filled in — choosing
    // a city does not advance the stepper (wizard-step-reduction, Task 7).
    const currentStepItem = stepperGroup(page)
        .getByRole('listitem')
        .filter({ has: page.locator('[aria-current="step"]') });
    await expect(currentStepItem.getByText('Cari Makam', { exact: true })).toBeVisible();

    // Selecting a TPU/TPS is now a same-page `wire:click` reveal (Task 4's
    // merge), not a navigation to a separate `/perpanjangan/cari` route —
    // the URL stays on `/perpanjangan` and the bookmarkable `?tpu=` param
    // (`#[Url(as: 'tpu', history: true)]`) is pushed into it via Livewire's
    // own history integration, while Step 3's heading reveals in place.
    await cemeteryButton!.click();
    await expect(page).toHaveURL(/\/perpanjangan\?.*tpu=/);
    await expect(page.getByRole('heading', { level: 2, name: /Cari Makam/ })).toBeVisible();
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
        // This page also carries the page-level §6.10 footer escape hatch
        // ("Butuh bantuan menelusuri data makam? Hubungi Bantuan.", outside
        // any step section, start.blade.php) — a real, intentional second
        // "Hubungi Bantuan" link, not a merge defect. Scope to Step 3's own
        // section (real heading text confirmed in start.blade.php) so this
        // asserts the gate-closed page's own contextual CTA specifically.
        await expect(page.getByLabel('Langkah 3 — Cari Makam').getByRole('link', { name: 'Hubungi Bantuan' })).toBeVisible();
        // §6.10 support escape hatch. Pre-merge this gate-closed page also
        // linked back to a separate "/perpanjangan" TPU/TPS-picker route
        // ("Anda juga dapat kembali ke pemilihan TPU/TPS"); Task 4's merge
        // dropped that link because Steps 1-2 are already visible on this
        // SAME page (there is nothing to navigate "back" to), leaving only
        // the FAQ link in the support slot — asserted here instead.
        await expect(page.getByRole('link', { name: 'pertanyaan yang sering diajukan' })).toHaveAttribute('href', '/faq');

        return;
    }

    // Gate open (not CI's default, but this suite must stay correct here
    // too): walk a real cemetery in through the UI and exercise the
    // genuine AC5 no-result state with a name guaranteed not to collide
    // with the "Contoh ..." fixture rows.
    await page.goto('/perpanjangan');
    const cemeteryButton = await findCemeteryButton(page);
    expect(cemeteryButton, 'expected at least one launch city to have a published TPU/TPS').not.toBeNull();

    await cemeteryButton!.click();
    // Pre-merge this was a standalone "Cari Data Makam" <h1> on its own
    // route; Task 4's merge dropped that heading in favour of the plain
    // "Mencari di {cemetery}." intro line under Step 3's own <h2> (already
    // waited on inside `graveSearchGateClosed()` above), so the settle
    // point here is the search form itself actually being present.
    await expect(page.getByLabel('Nama almarhum')).toBeVisible();

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

test('payment screen never leaks tariff data and gives an honest reason when nothing is selected', async ({
    page,
}) => {
    // No selection at all — the fee section is reached only via a
    // session-remembered grave selection (`RenewalGraveSelection`), which
    // this direct navigation never makes, so this is the same not-found
    // message `RenewalPayment::resolveState()` shows with no `perpanjangan`
    // and no pending selection either.
    await page.goto('/perpanjangan/pembayaran');
    await expect(page.getByRole('heading', { level: 1, name: 'Data perpanjangan tidak ditemukan.' })).toBeVisible();
    // Every renewal screen also carries the §6.10 footer escape hatch in
    // addition to the denial-state CTA button, so two "Hubungi Bantuan"
    // links legitimately coexist here — .first() only needs one to exist.
    await expect(page.getByRole('link', { name: 'Hubungi Bantuan' }).first()).toBeVisible();

    // The former fee section's `?makam=` query parameter is no longer
    // bound to anything — grave selection is session-only post-merge
    // (RenewalGraveSelection's own doc block) — so an arbitrary query
    // string here is simply ignored, and the screen still shows the same
    // honest not-found state rather than a broken card.
    await page.goto('/perpanjangan/pembayaran?makam=00000000-0000-0000-0000-000000000000');
    await expect(page.getByRole('heading', { level: 1, name: 'Data perpanjangan tidak ditemukan.' })).toBeVisible();

    // Neither case may render any tariff figure or source.
    await expect(page.getByText('Estimasi biaya perpanjangan')).toHaveCount(0);
    await expect(page.getByText('Sumber tarif')).toHaveCount(0);

    // The step is never removed from the stepper even when denied.
    const items = stepperGroup(page).getByRole('listitem');
    await expect(items).toHaveCount(STEP_LABELS.length);
});

test('payment and confirmation steps show an honest not-found state without a renewal reference', async ({
    page,
}) => {
    // A fixture now DOES create a `renewals` row —
    // `2026_08_23_120000_seed_external_renewal_fixture.php` (gated on
    // `SEED_E2E_EXTERNAL_RENEWAL`, which the CI browser job sets true, so
    // this spec file and `e2e-renewal-external.spec.ts` share one database
    // in that job). That fixture's renewal carries a real UUID `reference`,
    // but this test never passes any reference in — it navigates with no
    // reference at all — so its own assertions below are unaffected: the
    // only writer reachable via a real reference, OpenRenewal, is still
    // unreachable from the public UI while G-DATA-01 stays closed, so a
    // real 'manual' or 'online' payment state, and duplicate-prevention on
    // re-submission, remain NOT reachable from the public UI in this
    // environment. Both screens' only reachable, honest state via direct
    // navigation with no reference is "not found" — bearer-UUID access
    // (RenewalPayment / RenewalConfirmation doc blocks) means an absent or
    // unknown reference must read as not-found, never as an error.
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
