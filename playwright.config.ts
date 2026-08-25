import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
    testDir: './tests/browser',
    fullyParallel: true,
    forbidOnly: !!process.env.CI,
    retries: process.env.CI ? 2 : 0,
    workers: process.env.CI ? 2 : undefined,
    // Default 30s/5s (test/expect) timeouts were sized for smoke.spec.ts
    // alone. Once the E2E-HOME/FAQ/REN suites landed, CI's shared 2-vCPU
    // runner started showing broad, non-reproducible failures ("element not
    // found" on basic nav/CTA checks) that a dedicated host running the
    // identical config, steps, and PHP_CLI_SERVER_WORKERS=4 could not
    // reproduce even once, including under CI's own 2-worker parallelism
    // against a single `php artisan serve` process -- the signature (a mix
    // of hard failures and one flaky retry-then-pass in the same CI run) is
    // resource contention, not a content or build defect. Widening CI's
    // budget is a mitigation, not a proven fix -- watch the next CI run
    // rather than assume this alone resolves it.
    timeout: process.env.CI ? 60_000 : 30_000,
    expect: {
        timeout: process.env.CI ? 10_000 : 5_000,
    },
    reporter: 'list',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL || 'http://localhost:8080',
        trace: 'on-first-retry',
        // Paired with .github/workflows/ci.yml's "Upload Playwright test
        // results on failure" step -- a plain PNG for a failing action,
        // downloadable straight from a CI run instead of needing to parse
        // trace.zip. This pairing is what actually diagnosed two real bugs
        // 21 Aug 2026 (missing Filament panel assets in CI; the audit-trail
        // test's page-1 sort assumption under concurrent test traffic) that
        // would otherwise have only ever shown up as an opaque locator
        // timeout.
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
            // Excludes the mobile-only booking spec, which otherwise
            // matches this project's own default testMatch glob too and
            // would run a second, desktop-viewport copy of it alongside the
            // mobile-chromium run below — verified directly: without this
            // testIgnore, `npx playwright test --list` reported 63 tests
            // (61 pre-existing + 2 mobile-spec runs) instead of the
            // expected 62 (61 + exactly 1).
            testIgnore: /e2e-booking-mobile\.spec\.ts/,
        },
        {
            // Scoped via testMatch so this project runs ONLY the mobile
            // booking spec, not the entire tests/browser/ tree a second
            // time — a bare second project here would double every
            // existing test's CI runtime for no new coverage.
            name: 'mobile-chromium',
            use: { ...devices['Pixel 5'] },
            testMatch: /e2e-booking-mobile\.spec\.ts/,
        },
    ],
});
