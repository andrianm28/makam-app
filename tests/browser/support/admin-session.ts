import * as fs from 'node:fs';
import * as os from 'node:os';
import * as path from 'node:path';
import type { Browser, Page } from '@playwright/test';

/**
 * Shared admin-session caching, extracted from `e2e-admin-vendor.spec.ts`
 * (final-review finding, observability-and-adr-fixes-adjacent
 * release-gates-engineering-closeout plan) so a second spec file can reuse
 * one real login instead of adding its own against Filament's shared
 * 5-attempts/60s IP-keyed rate limit — see that file's own extensive
 * comment block (still present there) for the full rate-limit reasoning
 * this module's caching strategy exists to satisfy. `STORAGE_STATE_
 * FRESHNESS_MS` (10 minutes) comfortably exceeds one CI run's wall time,
 * so whichever spec file's admin-authenticated describe block runs FIRST
 * within a run creates this cache, and every other describe block in
 * every spec file that checks the same path within that run — regardless
 * of which file it's in — reuses it for free.
 */

export const ADMIN = {
    email: 'e2e-admin@example.test',
    password: 'E2eAdminPassword!1',
};

const STORAGE_STATE_FRESHNESS_MS = 10 * 60 * 1000;

function parallelSlot(): string {
    return process.env.TEST_PARALLEL_INDEX ?? '0';
}

export function adminStorageStatePath(): string {
    return path.join(os.tmpdir(), `e2e-admin-vendor-admin-storage-state-${parallelSlot()}.json`);
}

function hasFreshStorageState(storageStatePath: string): boolean {
    try {
        return Date.now() - fs.statSync(storageStatePath).mtimeMs < STORAGE_STATE_FRESHNESS_MS;
    } catch {
        return false;
    }
}

export async function adminLogin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.getByLabel('Alamat email').fill(ADMIN.email);
    await page.getByRole('textbox', { name: 'Kata sandi' }).fill(ADMIN.password);
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL(/\/admin\/?$/);
    await page.waitForLoadState('networkidle');
}

/**
 * Logs in and saves `storageState` to `storageStatePath` — UNLESS a
 * still-fresh file is already there (from this run's own prior login, in
 * this file or a sibling spec file), in which case this is a no-op that
 * costs zero real login attempts.
 */
export async function loginOnceUnlessFreshSession(
    browser: Browser,
    storageStatePath: string,
    login: (page: Page) => Promise<void>,
): Promise<void> {
    if (hasFreshStorageState(storageStatePath)) {
        return;
    }

    const context = await browser.newContext({ storageState: undefined });
    const page = await context.newPage();
    await login(page);
    await context.storageState({ path: storageStatePath });
    await context.close();
}
