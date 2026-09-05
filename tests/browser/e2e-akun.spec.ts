import * as os from 'node:os';
import * as path from 'node:path';
import { expect, test, type Page } from '@playwright/test';

/**
 * `/akun` customer account area — login/registration, index, draft list,
 * order list, and the two gate-closed stub pages (perpanjangan, dokumen).
 *
 * THIS FILE WAS NOT EXECUTED. There is no `node_modules`/browser available
 * in this environment (confirmed: `ls node_modules` -> "No such file or
 * directory"). Every string and route asserted below was read directly from
 * the real, current source at the time this file was written — see this
 * task's own report (`.superpowers/sdd/2026-09-04-pre-demo-known-gaps/
 * task-6-report.md`) for file:line citations. If a real run of this file
 * (once a browser/node_modules environment exists) fails on a string
 * mismatch, re-read the live source before assuming this file is wrong — it
 * may simply have drifted from a later UI change, same discipline
 * `e2e-admin-vendor.spec.ts`'s own header comment documents.
 *
 * ---------------------------------------------------------------------------
 * Customer-account E2E fixture strategy — resolved by reading real source
 * ---------------------------------------------------------------------------
 * `database/migrations/2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`
 * (note: `_110000`, not `_100000` as an earlier draft of this task's brief
 * guessed — `_100000` collided with an unrelated migration merged to trunk
 * meanwhile and was bumped; confirmed by reading the file's own doc block)
 * seeds exactly two accounts, `e2e-admin@example.test` and
 * `e2e-vendor@example.test` — both granted `ActorRole::ADMIN`/`FINANCE` or
 * `ActorRole::VENDOR` respectively via `GrantActorRole`. Neither is a plain
 * customer with zero role grants, and no other migration under
 * `database/migrations/` seeds a customer-shaped user either (checked the
 * full directory listing). There is therefore no existing fixture this file
 * can log in with.
 *
 * This file instead registers a fresh, throwaway customer account through
 * the real `/daftar` UI once per run (`registerFreshCustomer()` below) —
 * option (a) from the task brief — rather than inventing a fixture email
 * that doesn't exist anywhere in this codebase. `RegisterPage::register()`
 * (`app/Livewire/Public/Auth/RegisterPage.php`) auto-authenticates the new
 * user immediately (`auth()->login($user)`), so registering IS the first
 * real login for this account; every other authenticated test in this file
 * then logs back in with the same generated email/password via the real
 * `/masuk` form, proving `LoginPage` independently rather than only ever
 * reusing the registration's own session.
 *
 * A generated, timestamp+random-suffixed email (not a fixed constant) is
 * used deliberately: `RegisterPage::register()` validates
 * `'unique:users,email'`, so a fixed email would fail on any run against a
 * database that already has a prior run's row (this repo's E2E suites don't
 * assume a `migrate:fresh` before every invocation the way
 * `e2e-admin-vendor.spec.ts`'s `firstOrCreate()`-based migration fixture
 * does). No follow-up fixture-seed migration is proposed here — a fresh
 * `/daftar` registration is cheap (one extra HTTP round trip) and this is
 * the only spec file in `tests/browser/` that calls `/daftar` at all
 * (checked: `grep -rl daftar tests/browser` matches nothing else), so it
 * does not compete with any other spec for `RegisterPage`'s 3-attempts/60s
 * per-IP rate limit (`RegisterPage::register()`'s own `RateLimiter::
 * tooManyAttempts($key, 3)`, keyed `'register:'.request()->ip()`).
 *
 * ---------------------------------------------------------------------------
 * Why `#password`/`#password_confirmation` locators, not `getByLabel`, on
 * the register form specifically
 * ---------------------------------------------------------------------------
 * `resources/views/components/mk/field.blade.php` renders every required
 * field's `<label>` as `{{ $label }}<span aria-hidden>*</span><span
 * class="sr-only">(wajib diisi)</span>` — the sr-only span IS part of the
 * computed accessible name (it's clipped, not `aria-hidden`/`display:none`),
 * so the real accessible name of the register form's "Kata Sandi" field is
 * "Kata Sandi (wajib diisi)", not the bare label text. Playwright's
 * `getByLabel` does a case-insensitive **substring** match by default, and
 * "Kata Sandi" is itself a substring of "Konfirmasi Kata Sandi (wajib
 * diisi)" — the confirmation field's own accessible name — so a plain
 * `getByLabel('Kata Sandi')` on `/daftar` resolves ambiguously to both
 * fields (the same class of substring collision `e2e-admin-vendor.spec.ts`'s
 * header comment already documents for Filament's "Kata sandi"/"Sembunyikan
 * kata sandi" fields, just a different concrete pair here). `{ exact: true
 * }` doesn't fix this either, since neither field's real accessible name is
 * the bare string "Kata Sandi" — both carry the "(wajib diisi)" suffix.
 * `<x-mk.field>` never sets an `id` prop in either call site
 * (`register-page.blade.php`), so `$id = $id ?? $name ?? uniqid(...)`
 * resolves to the field's own `name` attribute (`password`/
 * `password_confirmation`) — a real, stable id backed directly by the
 * source, not a guessed CSS selector. This collision does not exist on
 * `/masuk` (only one password field, no confirmation field), so the login
 * helper below still uses `getByLabel('Kata Sandi')` safely.
 */

type CustomerCredentials = {
    email: string;
    password: string;
};

// Populated by the first test in the 'akun — registration and login' describe
// block below and read by every later describe block in this file.
// `test.describe.configure({ mode: 'serial' })` (below) is what makes this
// safe: it forces the whole file onto one worker, in declaration order, so
// `customer` is always assigned before any later block's `beforeAll`/test
// reads it — same "whole-file serial mode for a cross-describe dependency"
// reasoning `e2e-admin-vendor.spec.ts`'s own header comment documents for
// its two admin-needing describe blocks sharing one login.
let customer: CustomerCredentials;

async function registerFreshCustomer(page: Page): Promise<CustomerCredentials> {
    const email = `e2e-customer-${Date.now()}-${Math.floor(Math.random() * 1_000_000)}@example.test`;
    const password = 'E2eCustomerPassword!1';

    await page.goto('/daftar');
    await page.getByLabel('Nama').fill('E2E Customer (Contoh)');
    await page.getByLabel('Email').fill(email);
    await page.locator('#password').fill(password);
    await page.locator('#password_confirmation').fill(password);
    await page.getByRole('button', { name: 'Daftar' }).click();

    // RegisterPage::register() -> redirectIntended(route('akun.index')) —
    // no prior intended URL exists for a fresh registration, so this lands
    // on plain /akun.
    await page.waitForURL(/\/akun\/?$/);
    await page.waitForLoadState('networkidle');

    return { email, password };
}

async function loginAsCustomer(page: Page, credentials: CustomerCredentials): Promise<void> {
    await page.goto('/masuk');
    await page.getByLabel('Email').fill(credentials.email);
    await page.getByLabel('Kata Sandi').fill(credentials.password);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL(/\/akun\/?$/);
    await page.waitForLoadState('networkidle');
}

function parallelSlot(): string {
    return process.env.TEST_PARALLEL_INDEX ?? '0';
}

// One file, one fresh customer per run — no cross-run freshness caching the
// way `admin-session.ts`'s `adminStorageStatePath()` needs, since a fixed
// admin/vendor login is reused across runs but a fresh registration is
// deliberately NOT (see this file's header comment on why the email is
// generated, not fixed). This path only needs to be unique per concurrent
// worker slot within a single run, same `TEST_PARALLEL_INDEX` reasoning
// `e2e-admin-vendor.spec.ts` uses for its own storage-state paths.
function customerStorageStatePath(): string {
    return path.join(os.tmpdir(), `e2e-akun-customer-storage-state-${parallelSlot()}.json`);
}

test.describe.configure({ mode: 'serial' });

test.describe('akun — registration and login', () => {
    test('a customer can register via /daftar and reach the account index', async ({ page }) => {
        customer = await registerFreshCustomer(page);

        await expect(page).toHaveURL(/\/akun\/?$/);

        // AkunIndex's real 4-tile grid (app/Livewire/Public/Akun/AkunIndex.php
        // + resources/views/livewire/public/akun/akun-index.blade.php).
        await expect(page.getByRole('heading', { name: 'Akun Saya', level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Draft Pemesanan', exact: true })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Pesanan', exact: true })).toBeVisible();
        // Perpanjangan/Dokumen tile headings also contain the "Segera hadir"
        // badge text inside the same <h2> — exact: false (substring) match,
        // same reasoning as the badge itself being a nested element.
        await expect(page.getByRole('heading', { name: 'Perpanjangan', exact: false })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Dokumen', exact: false })).toBeVisible();
    });

    test('a customer can log in via /masuk with the account just registered', async ({ page }) => {
        await loginAsCustomer(page, customer);

        await expect(page).toHaveURL(/\/akun\/?$/);
        await expect(page.getByRole('heading', { name: 'Akun Saya', level: 1 })).toBeVisible();
    });
});

test.describe('akun — with an authenticated customer session', () => {
    const storageStatePath = customerStorageStatePath();
    test.use({ storageState: storageStatePath });

    test.beforeAll(async ({ browser }) => {
        const context = await browser.newContext();
        const page = await context.newPage();
        await loginAsCustomer(page, customer);
        await context.storageState({ path: storageStatePath });
        await context.close();
    });

    test('draft list shows the real empty state when there are no drafts', async ({ page }) => {
        await page.goto('/akun/draft');

        // DraftList's empty state (draft-list.blade.php) — a freshly
        // registered customer has no BookingDraft rows.
        await expect(page.getByRole('heading', { name: 'Draft Pemesanan', level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Belum ada draft pemesanan.' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Mulai pemesanan' })).toHaveAttribute('href', /\/pemesanan-makam$/);
    });

    test('order list shows the real empty state when there are no orders', async ({ page }) => {
        await page.goto('/akun/pesanan');

        // OrderList's empty state (order-list.blade.php) — a freshly
        // registered customer has no Order rows (Order::forUser() scopes to
        // this actor's own PEMESAN party only).
        await expect(page.getByRole('heading', { name: 'Pesanan Saya', level: 1 })).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Belum ada pesanan.' })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Mulai pemesanan' })).toHaveAttribute('href', /\/pemesanan-makam$/);
    });

    test('renewal (akun) shows the gate-closed page with a working fallback link', async ({ page }) => {
        await page.goto('/akun/perpanjangan');

        // Every akun.* page shares layouts/app.blade.php, whose <x-mk.header>
        // ALSO renders its own persistent "Bantuan" link (header.blade.php:
        // 144-149 mobile bar, 223-228 desktop bar) — the desktop bar's copy
        // is the one actually visible at this suite's default `chromium`
        // project viewport (devices['Desktop Chrome'], playwright.config.ts),
        // since the mobile bar's wrapper is `lg:hidden` and the desktop
        // bar's is `hidden lg:flex` (header.blade.php:104, :188). Scoping
        // every locator below to `<main id="main">` (layouts/app.blade.php:151)
        // excludes that header chrome entirely, so "Bantuan" below resolves
        // only to RenewalList's own support-slot link, not a strict-mode
        // ambiguous match across header + page content.
        const main = page.locator('main');

        // RenewalList renders <x-mk.gate-closed-page> (renewal-list.blade.php)
        // — heading/body/fallback copy read directly from that view and from
        // gate-closed-page.blade.php's own <h1>{{ $heading }}</h1> markup.
        await expect(main.getByRole('heading', { name: 'Perpanjangan belum tersedia di akun' })).toBeVisible();
        await expect(
            main.getByText('Perpanjangan makam saat ini masih diproses melalui alur publik yang ada, belum terhubung ke akun Anda.'),
        ).toBeVisible();

        // Fallback sends the visitor to the existing public renewal flow
        // (route('perpanjangan.index') = '/perpanjangan', routes/web.php:276)
        // — the real fallback path PUB-030 is expected to use, per this
        // component's own doc block. href asserted by suffix, not full
        // string: route() renders an absolute URL whose host depends on the
        // app's configured base URL, not this test's own baseURL.
        const fallbackLink = main.getByRole('link', { name: 'Buka Perpanjangan Makam', exact: true });
        await expect(fallbackLink).toBeVisible();
        await expect(fallbackLink).toHaveAttribute('href', /\/perpanjangan$/);

        // Support slot (route('bantuan.index') = '/bantuan', routes/web.php:423).
        const supportLink = main.getByRole('link', { name: 'Bantuan', exact: true });
        await expect(supportLink).toBeVisible();
        await expect(supportLink).toHaveAttribute('href', /\/bantuan$/);
    });

    test('document (akun) shows the gate-closed page', async ({ page }) => {
        await page.goto('/akun/dokumen');

        // Same header-chrome scoping reasoning as the renewal test above.
        const main = page.locator('main');

        // DocumentList renders <x-mk.gate-closed-page> (document-list.blade.php)
        // — both fallback and support point to route('bantuan.index') here
        // (no customer-facing upload path exists yet, per that component's
        // own doc block).
        await expect(main.getByRole('heading', { name: 'Dokumen belum tersedia' })).toBeVisible();
        await expect(main.getByText('Belum ada jalur unggah dokumen untuk pelanggan saat ini.')).toBeVisible();

        // Unlike RenewalList, DocumentList's fallback slot AND support slot
        // both render an identical "Bantuan" link to route('bantuan.index')
        // (document-list.blade.php) — two real links within <main> (plus the
        // header's own third, scoped out above), not one, so this asserts
        // both rather than picking one arbitrarily.
        const bantuanLinks = main.getByRole('link', { name: 'Bantuan', exact: true });
        await expect(bantuanLinks).toHaveCount(2);
        for (let i = 0; i < 2; i += 1) {
            await expect(bantuanLinks.nth(i)).toHaveAttribute('href', /\/bantuan$/);
        }
    });
});

test.describe('akun — guest access', () => {
    test('a guest visiting /akun/draft is redirected to login, then round-trips back after login', async ({ page }) => {
        await page.goto('/akun/draft');

        // routes/web.php's akun.* group is `Route::middleware('auth')`
        // (Laravel's default alias) — an unauthenticated request throws
        // AuthenticationException, whose handler calls
        // `redirect()->guest(route('login'))`
        // (`Illuminate\Routing\Redirector::guest()`), which both redirects to
        // /masuk AND stores the originally-requested full URL in the
        // 'url.intended' session key.
        await expect(page).toHaveURL(/\/masuk\/?$/);
        await expect(page.getByRole('heading', { name: 'Masuk', level: 1 })).toBeVisible();

        // Logging in with a real, already-registered account (from the
        // 'akun — registration and login' describe block above — guaranteed
        // populated first by this file's whole-file serial mode) exercises
        // LoginPage::login()'s own `redirectIntended(route('akun.index'))`
        // call: it consumes the SAME 'url.intended' session key the guest
        // redirect above set, landing back on /akun/draft instead of the
        // route('akun.index') default.
        await page.getByLabel('Email').fill(customer.email);
        await page.getByLabel('Kata Sandi').fill(customer.password);
        await page.getByRole('button', { name: 'Masuk' }).click();

        await expect(page).toHaveURL(/\/akun\/draft\/?$/);
        await expect(page.getByRole('heading', { name: 'Draft Pemesanan', level: 1 })).toBeVisible();
    });
});
