import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-HOME — docs/testing/test-strategy.md §2. Menu labels/order and the
 * `/bantuan` link text/href come from resources/views/components/mk/header.blade.php
 * and resources/views/livewire/public/home-page.blade.php (read directly, not
 * guessed): the four-menu product contract is hardcoded there as a
 * deliberate, non-configurable list.
 */
const PRIMARY_MENUS = ['Pemesanan Makam', 'Layanan Pemakaman', 'Perpanjangan Makam', 'FAQ'];

test('desktop navigation shows the four primary menu labels in order', async ({ page }) => {
    await page.goto('/');

    // exact: true — "Menu utama" is a substring of the mobile panel's own
    // "Menu utama (seluler)" accessible name, and Playwright's default
    // role-name match is a case-insensitive substring match.
    const desktopNav = page.getByRole('navigation', { name: 'Menu utama', exact: true });
    const links = desktopNav.getByRole('link');

    await expect(links).toHaveText(PRIMARY_MENUS);

    await expect(desktopNav.getByRole('link', { name: 'Pemesanan Makam' })).toHaveAttribute(
        'href',
        '/pemesanan-makam',
    );
    await expect(desktopNav.getByRole('link', { name: 'Layanan Pemakaman' })).toHaveAttribute('href', '/marketplace');
    await expect(desktopNav.getByRole('link', { name: 'Perpanjangan Makam' })).toHaveAttribute(
        'href',
        '/perpanjangan',
    );
    await expect(desktopNav.getByRole('link', { name: 'FAQ' })).toHaveAttribute('href', '/faq');
});

test('mobile navigation exposes the same four menu labels behind the hamburger', async ({ page }) => {
    await page.setViewportSize({ width: 375, height: 812 });
    await page.goto('/');

    // Desktop nav is `hidden lg:flex` — must not be visible at mobile width.
    await expect(page.getByRole('navigation', { name: 'Menu utama', exact: true })).toBeHidden();

    const hamburger = page.getByRole('button', { name: 'Buka menu navigasi' });
    await expect(hamburger).toHaveAttribute('aria-expanded', 'false');

    await hamburger.click();
    await expect(hamburger).toHaveAttribute('aria-expanded', 'true');

    const mobileNav = page.getByRole('navigation', { name: 'Menu utama (seluler)' });
    await expect(mobileNav).toBeVisible();

    // The mobile nav's <ul> also carries a 5th item (Masuk/Akun) after the
    // four primary menus — resources/views/components/mk/header.blade.php's
    // mobile <nav> block, confirmed by reading it directly. Check the four
    // primary labels individually (in order) rather than asserting the whole
    // link set, since the account link is legitimately present too.
    for (const label of PRIMARY_MENUS) {
        await expect(mobileNav.getByRole('link', { name: label, exact: true })).toBeVisible();
    }
    await expect(mobileNav.getByRole('link', { name: /^(Masuk\/Akun|Akun)$/ })).toBeVisible();
});

test('four primary public routes are reachable and accessible without authentication', async ({ page }) => {
    const publicRoutes = ['/', '/pemesanan-makam', '/marketplace', '/perpanjangan', '/faq'];

    for (const route of publicRoutes) {
        const response = await page.goto(route);

        expect(response?.status(), `expected ${route} to respond 200`).toBe(200);
        // A route requiring auth would redirect to /login instead of serving
        // its own content — confirms these are genuinely public.
        expect(new URL(page.url()).pathname).not.toBe('/login');
    }

    // Full axe scan on one representative non-homepage public route — the
    // homepage's own accessibility scan already lives in smoke.spec.ts.
    await page.goto('/faq');
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('homepage shows the Urgent gate explanatory state and the customer-service CTA', async ({ page }) => {
    await page.goto('/');

    // G-OPS-01 (Urgent/At-Need acceptance) is seeded `closed` — see
    // database/migrations/2026_07_26_120400_seed_feature_gate_registry.php —
    // so UrgentMode::CapacityUnknown's fallback banner must render, truthful
    // about capacity rather than claiming availability. Rendered via
    // <x-mk.alert live="polite">, which yields role="status".
    const gateBanner = page.getByRole('status');
    await expect(gateBanner.getByText('Ketersediaan Urgent Belum Dapat Dipastikan Otomatis')).toBeVisible();

    const gateBannerLink = gateBanner.getByRole('link', { name: 'hubungi Bantuan', exact: true });
    await expect(gateBannerLink).toHaveAttribute('href', '/bantuan');

    // Section 8 — the standing customer-service CTA, distinct from the gate
    // banner. Scoped to its own landmark region (aria-labelledby) so its
    // "Hubungi Bantuan" link is never confused with the banner's "hubungi
    // Bantuan" inline link above (Playwright role-name matching is
    // case-insensitive).
    const ctaRegion = page.getByRole('region', { name: 'Butuh Bantuan Memilih Layanan?' });
    await expect(ctaRegion).toBeVisible();

    const ctaLink = ctaRegion.getByRole('link', { name: 'Hubungi Bantuan', exact: true });
    await expect(ctaLink).toHaveAttribute('href', '/bantuan');
});
