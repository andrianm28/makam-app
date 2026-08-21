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
        // TEMPORARY, alongside the diagnostic artifact-upload CI step --
        // see that step's comment in .github/workflows/ci.yml. Gives a
        // plain PNG for a failing action without needing to parse the
        // trace zip. Remove once root-caused.
        screenshot: 'only-on-failure',
    },
    projects: [
        {
            name: 'chromium',
            use: { ...devices['Desktop Chrome'] },
        },
    ],
});
