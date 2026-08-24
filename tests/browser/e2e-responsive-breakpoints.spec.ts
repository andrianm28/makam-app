import { expect, test } from '@playwright/test';

/**
 * E2E-RESPONSIVE — docs/design/design-system.md §1.5's 7 documented
 * breakpoints (320/360/640/768/1024/1280/1536), tested against the real
 * homepage. Distinct from the `mobile-chromium` Playwright project
 * (Pixel 5, 393px, `e2e-booking-mobile.spec.ts` only) — that project is a
 * full mobile-viewport run of the booking wizard at one non-matrix width,
 * not a member of this matrix and not overlapping coverage.
 *
 * Per width, every test asserts "no horizontal overflow" (§1.5's 320px
 * baseline: "everything reachable"). Where §1.5 documents a concrete
 * structural change AND that change is actually present on the homepage,
 * an additional assertion targets it directly (real classes read from
 * resources/views/components/mk/header.blade.php and
 * resources/views/livewire/public/home-page.blade.php, not assumed):
 *   - 640/768/1024: nav state, confirmed real per header.blade.php's
 *     `lg:hidden` mobile bar / `hidden lg:flex` desktop bar (also below,
 *     for every width, since it's a single `lg` cutover).
 *   - 640 (sm): services grid `sm:grid-cols-2` → 2 columns ("2-up service
 *     cards", §1.5's literal sm claim).
 *   - 768 (md): §1.5's literal md claim is "wizard gets summary sidebar" —
 *     not present on the homepage (confirmed: no such sidebar in
 *     home-page.blade.php or wizard.blade.php). The FAQ-highlights grid's
 *     real `md:grid-cols-2` is used instead as a genuine, different
 *     md-triggered structural change on the tested page.
 *   - 1024 (lg): services grid `lg:grid-cols-4` → 4 columns, alongside the
 *     nav cutover itself (§1.5's literal lg claim, "3-up grids" is a
 *     nearby but not exact match — 4-up is what the real markup does).
 *   - 1280 (xl): `--container-content: 80rem` (1280px, tokens.css) is the
 *     `max-w-content` wrapper's cap — at exactly 1280px viewport width the
 *     container's rendered width equals 1280 (the cap and the viewport
 *     coincide at this boundary).
 *   - 1536 (2xl): same container stays capped at 1280 even though the
 *     viewport is 1536 — the real "no new layout, gutters grow only"
 *     behaviour §1.5 documents for 2xl.
 *   - 320/360: §1.5 documents only "Baseline"/"minor density gains" for
 *     these two — no structural claim exists to target, so only the
 *     overflow and (for consistency) nav-state assertions apply.
 */
const BREAKPOINTS = [320, 360, 640, 768, 1024, 1280, 1536] as const;

test.describe('E2E-RESPONSIVE — documented breakpoint matrix (design-system.md §1.5)', () => {
    for (const width of BREAKPOINTS) {
        test(`homepage layout at ${width}px`, async ({ page }) => {
            await page.setViewportSize({ width, height: 900 });
            await page.goto('/');

            const { scrollWidth, clientWidth } = await page.evaluate(() => ({
                scrollWidth: document.documentElement.scrollWidth,
                clientWidth: document.documentElement.clientWidth,
            }));
            expect(scrollWidth, `page must not overflow horizontally at ${width}px`).toBeLessThanOrEqual(clientWidth);

            // Nav cutover is a single `lg` (1024px) boundary — real for
            // every width in the matrix, not just 1024 itself.
            const desktopNav = page.getByRole('navigation', { name: 'Menu utama', exact: true });
            const hamburger = page.getByRole('button', { name: 'Buka menu navigasi' });

            if (width < 1024) {
                await expect(desktopNav).toBeHidden();
                await expect(hamburger).toBeVisible();
            } else {
                await expect(desktopNav).toBeVisible();
                await expect(hamburger).toBeHidden();
            }

            if (width === 640) {
                const servicesGrid = page.locator('[aria-label="Layanan utama"]');
                const columns = await servicesGrid.evaluate(
                    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length,
                );
                expect(columns, 'sm: services grid must be 2-up').toBe(2);
            }

            if (width === 768) {
                const faqGrid = page.locator('[aria-label="Pertanyaan unggulan"]');
                const columns = await faqGrid.evaluate(
                    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length,
                );
                expect(columns, 'md: FAQ-highlights grid must be 2-up').toBe(2);
            }

            if (width === 1024) {
                const servicesGrid = page.locator('[aria-label="Layanan utama"]');
                const columns = await servicesGrid.evaluate(
                    (el) => getComputedStyle(el).gridTemplateColumns.split(' ').filter(Boolean).length,
                );
                expect(columns, 'lg: services grid must be 4-up').toBe(4);
            }

            if (width === 1280) {
                // `[aria-labelledby="services-heading"]` carries `mx-auto
                // max-w-content` together with its own padding on one
                // element, so its border-box width is genuinely capped by
                // `--container-content`. (A bare `.max-w-content` picks up
                // layouts/app.blade.php's beta-notice wrapper first, whose
                // padding lives on its *parent* — that element's own width
                // is net of padding, not the raw cap, so it isn't usable
                // for this assertion.)
                const container = page.locator('[aria-labelledby="services-heading"]');
                const containerWidth = await container.evaluate((el) => el.getBoundingClientRect().width);
                expect(Math.round(containerWidth), 'xl: page-shell container must reach --container-content (1280px)').toBe(
                    1280,
                );
            }

            if (width === 1536) {
                const container = page.locator('[aria-labelledby="services-heading"]');
                const containerWidth = await container.evaluate((el) => el.getBoundingClientRect().width);
                expect(
                    Math.round(containerWidth),
                    '2xl: page-shell container must stay capped at 1280px, not grow to the 1536px viewport',
                ).toBe(1280);
            }
        });
    }
});
