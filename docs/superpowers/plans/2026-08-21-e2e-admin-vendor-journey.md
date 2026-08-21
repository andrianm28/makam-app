# E2E-ADMIN/VENDOR Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the sixth and final planned durable Playwright suite, `tests/browser/e2e-admin-vendor.spec.ts`, covering `docs/testing/test-strategy.md` §2's `E2E-ADMIN/VENDOR` acceptance criteria (dashboard modules, query scope + sensitive-action audit, transaction history + payout visibility) and closing the remaining §A/§E boxes in `docs/testing/release-gates.md` that need admin/vendor browser evidence.

**Architecture:** One new Playwright spec file, following `tests/browser/e2e-marketplace.spec.ts`'s established conventions exactly (AxeBuilder scans, `getByLabel`/`getByRole` locators, real fixture data cross-checked against source, no invented selectors). New: this is the first E2E suite that needs an *authenticated* browser session, for two Filament panels (`/admin`, `/vendor`) that don't exist yet in any browser test. Authentication is bootstrapped via one new data migration that seeds two throwaway login users (admin, vendor) and grants their roles/scopes through this repo's real, audited, console-only grant Actions — not a raw `Eloquent::create()` shortcut — so the seeded state is a faithful, sanctioned production write, not a test-only bypass.

**Tech Stack:** Playwright + `@axe-core/playwright` (existing), Laravel data migration (existing pattern), `App\Platform\IdentityAccess\Roles\Actions\GrantActorRole` / `App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment` (existing, real write paths).

**Spec:** `docs/testing/test-strategy.md` §2 (`E2E-ADMIN/VENDOR` acceptance criteria) and `docs/testing/release-gates.md` §A ("Admin and vendor modules pass role-scoped tests") and §E ("Vendor transaction history and payout reference are scoped").

## Global Constraints

- No hardcoded design values or arbitrary Tailwind classes in any Blade/test file touched (`ci/verify-docs.sh` Gates 1-2) — this plan touches no Blade files, so this constraint is inherited but not expected to trigger.
- Every seeded credential is a throwaway, clearly-fake account — email addresses must use the `@example.test` convention already established by `RECIPIENT` in `e2e-marketplace.spec.ts`, and the migration's own doc comment must say plainly these are fake/CI-only accounts, matching `2026_08_14_100000_seed_vendors_and_listings.php`'s "THIS IS DUMMY / PLACEHOLDER DATA" convention.
- Every role/scope grant goes through `GrantActorRole`/`GrantScopeAssignment` (the real, audited, console-only write paths) — never a raw `ActorRoleAssignment::create()` or `ScopeAssignment::create()` bypass, and never a raw Eloquent `User::factory()->create()` without also granting the role (an unrole'd user cannot pass `canAccessPanel()` and the login test would falsely "pass" by silently landing on an access-denied page instead of the real panel).
- Nothing in this repo runs `php artisan db:seed` in CI or any deployment script (`2026_08_14_100000_seed_vendors_and_listings.php`'s own doc block confirms this) — new seed data ships as a timestamped data migration, never a `database/seeders/` class.
- No new CI workflow step is needed — the migration alone, run by the browser-test job's existing `php artisan migrate --force` step, seeds everything this suite needs. Do not add a new "seed" step to `.github/workflows/ci.yml`.
- Composer/npm builds run in CI only, never on this host (`CLAUDE.md`) — every task's local verification step uses the pinned CI Docker image pattern already established this session, never a bare `php artisan test` on this host (PHP 8.3 here, app requires 8.5).
- Follow `e2e-marketplace.spec.ts`'s locator convention: `getByLabel()`/`getByRole()` against real rendered markup, never a raw CSS selector or invented `data-testid`.
- `AxeBuilder` scans on every new page state reached, zero violations, matching every prior E2E suite this session.

---

### Task 1: Seed the admin and vendor test users (data migration)

**Files:**
- Create: `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`
- Test: `tests/Feature/Migrations/SeedE2eAdminVendorTestUsersTest.php`

**Interfaces:**
- Consumes: `App\Platform\IdentityAccess\Roles\Actions\GrantActorRole` (`__invoke(int|string $actorIdentifier, string $role, string $reason, int|string|null $grantedBy): ActorRoleAssignment`), `App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment` (`__invoke(int|string $actorIdentifier, string $entityType, int|string $entityId, ?string $grantLevel, string $reason, int|string|null $grantedBy): ScopeAssignment`), `App\Platform\IdentityAccess\Roles\ActorRole::ADMIN` / `::VENDOR`, `App\Platform\IdentityAccess\Scopes\ScopeEntityType::VENDOR`, `App\Models\User`.
- Produces: two real, logged-in-able `users` rows the rest of this plan's tasks depend on by fixed email/password:
  - `e2e-admin@example.test` / `E2eAdminPassword!1` — granted `ActorRole::ADMIN` (unscoped — sees every entity, the baseline for the query-scope contrast test in Task 3).
  - `e2e-vendor@example.test` / `E2eVendorPassword!1` — granted `ActorRole::VENDOR` plus a `ScopeAssignment` (`entityType: ScopeEntityType::VENDOR`) pointing at the first vendor row seeded by `2026_08_14_100000_seed_vendors_and_listings.php` (query `vendors` table ordered by `id` and take the first row — do not hardcode a vendor id, it must be looked up at migration time so this works in any freshly-migrated environment).

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Migrations;

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class SeedE2eAdminVendorTestUsersTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_a_logged_in_able_admin_user(): void
    {
        $admin = User::query()->where('email', 'e2e-admin@example.test')->first();

        $this->assertNotNull($admin, 'expected the E2E-seeded admin user to exist after migration');
        $this->assertTrue(Hash::check('E2eAdminPassword!1', $admin->password));

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $admin->id,
            'role' => ActorRole::ADMIN,
        ]);
    }

    public function test_it_seeds_a_logged_in_able_vendor_user_scoped_to_a_real_vendor(): void
    {
        $vendor = User::query()->where('email', 'e2e-vendor@example.test')->first();

        $this->assertNotNull($vendor, 'expected the E2E-seeded vendor user to exist after migration');
        $this->assertTrue(Hash::check('E2eVendorPassword!1', $vendor->password));

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => (string) $vendor->id,
            'role' => ActorRole::VENDOR,
        ]);

        $scope = ScopeAssignment::query()
            ->where('actor_identifier', (string) $vendor->id)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->first();

        $this->assertNotNull($scope, 'expected the E2E-seeded vendor user to hold a vendor-entity scope assignment');
        $this->assertNotNull(
            \DB::table('vendors')->where('id', $scope->entity_id)->first(),
            'the scope assignment must point at a real, existing vendor row'
        );
    }

    public function test_it_is_idempotent_on_a_re_run(): void
    {
        // RefreshDatabase already ran every migration once for this test
        // class. Re-running just this migration's up() a second time (the
        // real thing that happens if a CI runner ever re-migrates without
        // a fresh database) must not throw a duplicate-key error.
        $migration = require database_path('migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php');

        $migration->up();

        $this->assertSame(
            1,
            User::query()->where('email', 'e2e-admin@example.test')->count(),
            're-running the migration must not create a duplicate admin user'
        );
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run against the pinned CI image (this host's PHP is too old):
```bash
sudo docker run --rm --user 1000:1000 -v /home/ubuntu/makam-app:/var/www/html -w /var/www/html --entrypoint /bin/sh <pinned-image-digest-from-.github/workflows/ci.yml> -c "php artisan test tests/Feature/Migrations/SeedE2eAdminVendorTestUsersTest.php"
```
Expected: FAIL — the migration file does not exist yet, so `assertNotNull($admin, ...)` fails (no such user was ever seeded).

- [ ] **Step 3: Write the migration**

```php
<?php

declare(strict_types=1);

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * Two fictional throwaway login accounts for the E2E-ADMIN/VENDOR browser
 * suite (`tests/browser/e2e-admin-vendor.spec.ts`) — the `@example.test`
 * domain and fixed, published-in-this-repo passwords make it unmistakable
 * these are not real credentials, matching `RECIPIENT`'s convention in
 * `tests/browser/e2e-marketplace.spec.ts` and this migration file's own
 * "THIS IS DUMMY DATA" convention from `2026_08_14_100000_seed_vendors_and_
 * listings.php`.
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * Nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed` — every fixture dataset in this repository ships as
 * a timestamped data migration instead (same reasoning as the vendor/
 * listing seed migration this one depends on).
 *
 * ---------------------------------------------------------------------------
 * Why `GrantActorRole`/`GrantScopeAssignment`, not a raw `create()`
 * ---------------------------------------------------------------------------
 * Both Actions are this codebase's only sanctioned write path onto
 * `actor_role_assignments`/`scope_assignments` (see each Action's own doc
 * block) — real audited grants, not a test-only bypass. Calling them here
 * keeps the seeded state indistinguishable, from the application's own
 * authorization code's point of view, from a real operator-run
 * `identity:grant-role`/`identity:grant-scope` grant. `grantedBy: null`
 * matches every other genuinely-unattended, no-human-operator write in
 * this codebase (`ActorRole::SYSTEM` is already the sentinel for exactly
 * this case).
 *
 * ---------------------------------------------------------------------------
 * The vendor scope's `entity_id` is looked up, never hardcoded
 * ---------------------------------------------------------------------------
 * `2026_08_14_100000_seed_vendors_and_listings.php` seeds vendor rows with
 * auto-incrementing ids that are not guaranteed stable across a from-
 * scratch migration run in a different environment (fresh CI database vs.
 * a long-lived dev database with extra vendors added later) — this
 * migration queries the real, current first `vendors` row at migration
 * time instead of assuming an id.
 *
 * Migration timestamp slot: `2026_08_22_100000`, after every existing
 * identity/vendor migration.
 */

use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'e2e-admin@example.test';

    private const string VENDOR_EMAIL = 'e2e-vendor@example.test';

    public function up(): void
    {
        $admin = User::query()->firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'E2E Admin (Contoh)',
                'password' => Hash::make('E2eAdminPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->where('role', ActorRole::ADMIN)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $admin->id,
                role: ActorRole::ADMIN,
                reason: 'E2E-ADMIN/VENDOR suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        $vendor = User::query()->firstOrCreate(
            ['email' => self::VENDOR_EMAIL],
            [
                'name' => 'E2E Vendor (Contoh)',
                'password' => Hash::make('E2eVendorPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $vendor->id)->where('role', ActorRole::VENDOR)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $vendor->id,
                role: ActorRole::VENDOR,
                reason: 'E2E-ADMIN/VENDOR suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        $firstVendorId = DB::table('vendors')->orderBy('id')->value('id');

        if ($firstVendorId !== null
            && ! DB::table('scope_assignments')
                ->where('actor_identifier', (string) $vendor->id)
                ->where('entity_type', ScopeEntityType::VENDOR)
                ->where('entity_id', (string) $firstVendorId)
                ->exists()
        ) {
            app(GrantScopeAssignment::class)(
                actorIdentifier: $vendor->id,
                entityType: ScopeEntityType::VENDOR,
                entityId: $firstVendorId,
                grantLevel: null,
                reason: 'E2E-ADMIN/VENDOR suite seed — scopes the throwaway vendor login to the first seeded vendor.',
                grantedBy: null,
            );
        }
    }

    public function down(): void
    {
        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();
        $vendor = User::query()->where('email', self::VENDOR_EMAIL)->first();

        foreach ([$admin, $vendor] as $user) {
            if ($user === null) {
                continue;
            }

            DB::table('scope_assignments')->where('actor_identifier', (string) $user->id)->delete();
            DB::table('actor_role_assignments')->where('actor_identifier', (string) $user->id)->delete();
            $user->delete();
        }
    }
};
```

- [ ] **Step 4: Run test to verify it passes**

Same command as Step 2. Expected: `OK (3 tests, ...)`.

- [ ] **Step 5: Independently confirm `firstOrCreate` idempotency doesn't fight `RefreshDatabase`**

`RefreshDatabase` re-runs every migration from zero for each test class, so Step 1's third test (re-running `up()` a second time inside an already-migrated test database) is the real idempotency proof — confirm its output specifically, not just the overall suite result.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php tests/Feature/Migrations/SeedE2eAdminVendorTestUsersTest.php
git commit -m "test(e2e-admin-vendor): seed throwaway admin/vendor login users for the E2E suite"
```

---

### Task 2: Admin login helper, dashboard, and reports coverage

**Files:**
- Create: `tests/browser/e2e-admin-vendor.spec.ts`

**Interfaces:**
- Consumes: Task 1's seeded `e2e-admin@example.test` / `E2eAdminPassword!1`.
- Produces: `adminLogin(page)` helper (exported implicitly by being defined at module scope in this file — Task 3 and Task 4 both call it) and the file's top-level fixture constants, which Task 3/4 extend rather than duplicate.

- [ ] **Step 1: Create the spec file with header, fixtures, and the admin login helper**

```typescript
import AxeBuilder from '@axe-core/playwright';
import { expect, test, type Page } from '@playwright/test';

/**
 * E2E-ADMIN/VENDOR — the last of the six planned durable Playwright
 * suites (`docs/testing/test-strategy.md` §2). Covers: all required admin
 * dashboard modules, query scope + sensitive-action audit, and vendor
 * transaction history + payout visibility.
 *
 * Authentication: both accounts are seeded by
 * `database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php`
 * (throwaway `@example.test` accounts, real audited role/scope grants via
 * `GrantActorRole`/`GrantScopeAssignment` — not a test-only auth bypass).
 * Both Filament panels (`/admin`, `/vendor`) use Filament's own default
 * email/password login form — no custom login page exists for either.
 *
 * Real fixture data only — no invented selectors or values:
 *   - Dashboard widget titles/stat labels: app/Filament/Admin/Widgets/*.php
 *   - Report page titles: app/Filament/Admin/Pages/*Report*.php, *::getTitle()
 *   - Audit resource columns: app/Filament/Admin/Resources/AuditEvents/Tables/AuditEventsTable.php
 *   - Vendor page titles: app/Filament/Vendor/Pages/*.php, ::getTitle()
 *   - Vendor/entity fixture data: App\Support\ExampleData\VendorListingExampleData
 *     (seeded by 2026_08_14_100000_seed_vendors_and_listings.php)
 *
 * All values above were read directly from the source files during
 * planning, not guessed from PR titles — several PR titles this suite's
 * plan was built against (ADM-001/070/090/100, VND-080) describe intent
 * more broadly than what actually shipped; every literal string below is
 * cross-checked against the real class/view, and Step 1 of each task below
 * re-confirms it against the live rendered page before asserting on it.
 */

const ADMIN = {
    email: 'e2e-admin@example.test',
    password: 'E2eAdminPassword!1',
};

const VENDOR = {
    email: 'e2e-vendor@example.test',
    password: 'E2eVendorPassword!1',
};

async function adminLogin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.getByLabel('Email address').fill(ADMIN.email);
    await page.getByLabel('Password').fill(ADMIN.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/admin(\/|$)/);
}

async function vendorLogin(page: Page): Promise<void> {
    await page.goto('/vendor/login');
    await page.getByLabel('Email address').fill(VENDOR.email);
    await page.getByLabel('Password').fill(VENDOR.password);
    await page.getByRole('button', { name: 'Sign in' }).click();
    await page.waitForURL(/\/vendor(\/|$)/);
}

test.describe('E2E-ADMIN/VENDOR — admin dashboard and reports', () => {
    test('admin can log in and reach the dashboard', async ({ page }) => {
        await adminLogin(page);

        await expect(page).toHaveURL(/\/admin(\/|$)/);
    });

    test('dashboard shows all four required widgets', async ({ page }) => {
        await adminLogin(page);

        // PlatformOverviewWidget
        await expect(page.getByText('TPU')).toBeVisible();
        await expect(page.getByText('TPS')).toBeVisible();
        await expect(page.getByText('Vendor Aktif')).toBeVisible();
        await expect(page.getByText('FAQ Dipublikasikan')).toBeVisible();

        // FinancialOverviewWidget
        await expect(page.getByText('Pembayaran Berhasil')).toBeVisible();
        await expect(page.getByText('Pembayaran Bermasalah')).toBeVisible();
        await expect(page.getByText('Laporan Rekonsiliasi')).toBeVisible();

        // FailedPaymentExceptionQueueWidget
        await expect(page.getByText('Antrian Pembayaran Gagal')).toBeVisible();
    });

    test('dashboard has zero accessibility violations', async ({ page }) => {
        await adminLogin(page);

        const results = await new AxeBuilder({ page }).analyze();

        expect(results.violations).toEqual([]);
    });

    test('all six required report pages are reachable and titled correctly', async ({ page }) => {
        await adminLogin(page);

        const reports: Array<{ path: string; title: string }> = [
            { path: '/admin/finance-reports', title: 'Laporan Keuangan' },
            { path: '/admin/orders-report', title: 'Laporan Pesanan' },
            { path: '/admin/renewal-period-report', title: 'Laporan Perpanjangan' },
            { path: '/admin/receipts-report', title: 'Laporan Penerimaan' },
            { path: '/admin/vendor-performance-report', title: 'Laporan Kinerja Vendor' },
            { path: '/admin/outgoing-payments-report', title: 'Laporan Pembayaran Keluar' },
        ];

        for (const report of reports) {
            await page.goto(report.path);
            await expect(page.getByRole('heading', { name: report.title })).toBeVisible();
        }
    });
});
```

- [ ] **Step 2: Run against the pinned CI image and fix any selector drift**

```bash
sudo docker run --rm --user 1000:1000 -v /home/ubuntu/makam-app:/var/www/html -w /var/www/html --entrypoint /bin/sh <pinned-image-digest> -c "
  cp .env.example .env && php artisan key:generate --force
  php artisan migrate:fresh --force
  npm run build
  PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8080 &
  sleep 3
  PLAYWRIGHT_BASE_URL=http://127.0.0.1:8080 npx playwright test tests/browser/e2e-admin-vendor.spec.ts
"
```

**This is the step where selector/route assumptions get corrected against reality.** Two things this plan's own research could not fully confirm and that MUST be verified here, not assumed:
1. **The exact Filament login form field labels** (`'Email address'`/`'Password'`, `'Sign in'` button text) — these are Filament's own default panel-login-page copy, but this repo may have customized them (`app/Filament/Admin/Auth/` or the panel provider's `->login()` call may pass a custom login page class). If the real labels differ, fix the `adminLogin`/`vendorLogin` helpers to match — this is the single most likely point of drift in this whole task.
2. **The exact admin route slugs** (`/admin/finance-reports` etc.) — Filament derives a resource/page's URL slug from its class name by default unless overridden; confirm each of the 6 report pages' real slug by reading its class for a `getSlug()` /  `getRouteName()` override, or by clicking through the admin nav in this same Docker/Playwright run and reading the real URL bar, rather than guessing the derivation.

- [ ] **Step 3: Commit**

```bash
git add tests/browser/e2e-admin-vendor.spec.ts
git commit -m "test(e2e-admin-vendor): admin login, dashboard widgets, and report pages"
```

---

### Task 3: Sensitive-action audit and query-scope coverage

**Files:**
- Modify: `tests/browser/e2e-admin-vendor.spec.ts`

**Interfaces:**
- Consumes: Task 2's `adminLogin(page)` helper and `ADMIN`/`VENDOR` constants.
- Produces: nothing new consumed by later tasks — this task is self-contained within the file.

- [ ] **Step 1: Write the audit-trail review test**

Append to `tests/browser/e2e-admin-vendor.spec.ts`, inside a new `test.describe` block:

```typescript
test.describe('E2E-ADMIN/VENDOR — sensitive-action audit and query scope', () => {
    test('audit trail review shows the required columns and is read-only', async ({ page }) => {
        await adminLogin(page);
        await page.goto('/admin/audit-events');

        await expect(page.getByRole('heading', { name: 'Jejak Audit' })).toBeVisible();

        // The columns AuditEventsTable.php defines — confirms the "sensitive
        // action audit" AC's real data shape, not just that the page loads.
        for (const column of ['Waktu', 'Aksi', 'Hasil', 'Aktor', 'Peran aktor', 'Sumber', 'Subjek', 'Alasan']) {
            await expect(page.getByRole('columnheader', { name: column })).toBeVisible();
        }

        // Read-only per ADM-100's own scope: no "New"/"Create"/"Buat" action
        // anywhere on the page — this resource registers index+view routes
        // only.
        await expect(page.getByRole('link', { name: /buat|create|new/i })).toHaveCount(0);
    });

    test('audit trail records this suite\'s own seeded grants', async ({ page }) => {
        await adminLogin(page);
        await page.goto('/admin/audit-events');

        // Task 1's migration granted ActorRole::VENDOR through
        // GrantActorRole — a real audited write, so it must appear here.
        // This is the strongest possible proof the "sensitive action audit"
        // AC is real: the suite's own setup step is itself an audited event.
        await expect(page.getByText(/E2E-ADMIN\/VENDOR suite seed/).first()).toBeVisible();
    });

    test('reconciliation admin list query-scope: unscoped admin sees the module', async ({ page }) => {
        await adminLogin(page);
        await page.goto('/admin/reconciliations');

        await expect(page.getByRole('heading', { name: 'Rekonsiliasi' })).toBeVisible();
        for (const column of ['Badan usaha', 'Periode', 'Status']) {
            await expect(page.getByRole('columnheader', { name: column })).toBeVisible();
        }
    });
});
```

- [ ] **Step 2: Verify the query-scope contrast is actually testable within this suite's scope**

Before writing a second, business-entity-scoped admin account (which would require extending Task 1's migration), read `app/Filament/Admin/Resources/Reconciliations/ReconciliationsResource.php`'s `getEloquentQuery()` (or equivalent) directly to confirm HOW `business_entity` scoping is enforced for an admin. If it's the same `ScopeAssignment` mechanism Task 1 already used for the vendor account:
- Extend Task 1's migration (in a follow-up commit within this task, not by editing the already-committed Task 1 migration file) with a THIRD seeded user, `e2e-restricted-admin@example.test`, granted `ActorRole::RESTRICTED_ADMIN` (not `ActorRole::ADMIN`) plus a `business_entity`-scoped `ScopeAssignment` pointing at one real seeded business entity — then add a browser test proving this account's `/admin/reconciliations` list shows strictly fewer rows than the unscoped admin's, by comparing the row count between the two logged-in sessions (`getByRole('row').count()` against each, after each account's own login).
- If entity-scoping instead turns out to be enforced at a different layer this plan's research didn't reach (e.g., not reproducible from the browser layer within this task's budget), do NOT invent a passing assertion — write the row-count comparison test but mark it `test.fixme('...')` with a one-line comment naming exactly what's still needed, and report this honestly as a DONE_WITH_CONCERNS in this task's report rather than silently shipping a vacuous test.

This step is deliberately not fully pre-specified — Task 1's own survey could not fully confirm the admin-side scoping trait name (see the plan's own research notes). Investigate for real here rather than trusting a guess.

- [ ] **Step 3: Add an AxeBuilder scan for the audit and reconciliation pages**

```typescript
test('audit and reconciliation pages have zero accessibility violations', async ({ page }) => {
    await adminLogin(page);

    await page.goto('/admin/audit-events');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);

    await page.goto('/admin/reconciliations');
    expect((await new AxeBuilder({ page }).analyze()).violations).toEqual([]);
});
```

- [ ] **Step 4: Run against the pinned CI image**

Same command shape as Task 2 Step 2, now covering the whole file (`tests/browser/e2e-admin-vendor.spec.ts`).

- [ ] **Step 5: Commit**

```bash
git add tests/browser/e2e-admin-vendor.spec.ts database/migrations/2026_08_22_100000_seed_e2e_admin_vendor_test_users.php
git commit -m "test(e2e-admin-vendor): audit trail review and reconciliation query-scope coverage"
```

(Only stage the migration file too if Step 2 actually extended it with a third seeded account — otherwise omit it from this commit.)

---

### Task 4: Vendor panel — profile, transaction history, payout visibility

**Files:**
- Modify: `tests/browser/e2e-admin-vendor.spec.ts`

**Interfaces:**
- Consumes: Task 2's `vendorLogin(page)` helper and `VENDOR` constant.
- Produces: nothing new consumed elsewhere — final task in this plan.

- [ ] **Step 1: Write the vendor panel tests**

Append a final `test.describe` block:

```typescript
test.describe('E2E-ADMIN/VENDOR — vendor profile, transactions, and payouts', () => {
    test('vendor can log in and reach their own dashboard', async ({ page }) => {
        await vendorLogin(page);

        await expect(page).toHaveURL(/\/vendor(\/|$)/);
        await expect(page.getByRole('heading', { name: 'Dashboard Vendor' })).toBeVisible();
    });

    test('vendor profile/account page is reachable and editable', async ({ page }) => {
        await vendorLogin(page);
        await page.goto('/vendor/profile');

        await expect(page.getByRole('heading', { name: 'Profil Akun' })).toBeVisible();
    });

    test('vendor transaction history is reachable and scoped to this vendor only', async ({ page }) => {
        await vendorLogin(page);
        await page.goto('/vendor/transaction-history');

        await expect(page.getByRole('heading', { name: 'Riwayat Transaksi' })).toBeVisible();

        // Every visible row must belong to the vendor this session is
        // scoped to (Task 1's ScopeAssignment) — not a blanket "some rows
        // exist" check. If the table's rows carry a vendor-name column,
        // assert every one matches the seeded vendor's real name; if not,
        // this at minimum confirms the page renders with zero cross-vendor
        // leakage by checking the row count is bounded to what this one
        // vendor's seeded fixture data actually produced (read
        // VendorListingExampleData during implementation to know the real
        // expected count, don't assert an arbitrary number).
    });

    test('vendor payout status/reference is visible and scoped', async ({ page }) => {
        await vendorLogin(page);
        await page.goto('/vendor/payout-status');

        await expect(page.getByRole('heading', { name: 'Status Pencairan' })).toBeVisible();
    });

    test('vendor panel pages have zero accessibility violations', async ({ page }) => {
        await vendorLogin(page);

        for (const path of ['/vendor', '/vendor/profile', '/vendor/transaction-history', '/vendor/payout-status']) {
            await page.goto(path);
            const results = await new AxeBuilder({ page }).analyze();
            expect(results.violations).toEqual([]);
        }
    });

    test('vendor cannot reach the admin panel', async ({ page }) => {
        await vendorLogin(page);
        await page.goto('/admin');

        // A vendor-role account hitting an admin-only panel must NOT reach
        // a real admin page — Filament's canAccessPanel() denial typically
        // renders a 403 or redirects to the vendor's own panel/login.
        // Confirm which one this repo actually does and assert against
        // that real behavior, not a guessed status code.
        await expect(page.getByRole('heading', { name: 'Jejak Audit' })).not.toBeVisible();
    });
});
```

- [ ] **Step 2: Fill in the transaction-history scoping assertion for real**

The comment left in Step 1's `'vendor transaction history is reachable and scoped to this vendor only'` test is a placeholder for investigation, not a finished test — per this plan's own "No Placeholders" discipline, replace it with a real, specific assertion before this task is done. Read `app/Filament/Vendor/Pages/TransactionHistory.php` and its table/query definition directly, confirm exactly what distinguishes "this vendor's rows" from another vendor's, and assert that concretely (e.g., a column value equality check against every visible row, or — if `VendorListingExampleData` seeds only one active listing per vendor as the Task 1 survey found — an exact row-count assertion with the expected count named and justified in a comment).

- [ ] **Step 3: Run the full spec file against the pinned CI image**

```bash
sudo docker run --rm --user 1000:1000 -v /home/ubuntu/makam-app:/var/www/html -w /var/www/html --entrypoint /bin/sh <pinned-image-digest> -c "
  cp .env.example .env && php artisan key:generate --force
  php artisan migrate:fresh --force
  npm run build
  PHP_CLI_SERVER_WORKERS=4 php artisan serve --host=127.0.0.1 --port=8080 &
  sleep 3
  PLAYWRIGHT_BASE_URL=http://127.0.0.1:8080 npx playwright test tests/browser/e2e-admin-vendor.spec.ts
"
```

Expected: every test in the file passes, zero AxeBuilder violations anywhere.

- [ ] **Step 4: Commit**

```bash
git add tests/browser/e2e-admin-vendor.spec.ts
git commit -m "test(e2e-admin-vendor): vendor profile, transaction history, and payout visibility"
```

---

## What this plan does NOT cover

- Vendor's order-acceptance/processing journey end to end (`VendorOrders`/`WorkOrders` resources) beyond confirming they exist and are reachable — that full accept→process→evidence flow is already covered from the customer/vendor-write side by `tests/Feature/` tests per the traceability matrix; adding a full browser-driven accept/process/evidence journey here would meaningfully grow this plan's scope beyond what test-strategy.md's three-bullet AC asks for. Flag as a possible follow-up, not built here.
- A second, business-entity-scoped admin account and its full contrast test — Task 3 Step 2 investigates this for real and either builds it or explicitly marks it `test.fixme` with a named gap, rather than this plan pre-deciding an outcome its own research couldn't fully confirm.
- Any change to `release-gates.md` itself — a separate closure pass (already in progress elsewhere this session) checks boxes against this suite's real, passing results once this plan lands; this plan only produces the suite.

## Verification

| What | How | Pass condition |
|---|---|---|
| Task 1's seed migration | `tests/Feature/Migrations/SeedE2eAdminVendorTestUsersTest.php` against the pinned CI image | 3/3 tests pass, including the idempotency re-run |
| Tasks 2-4's browser suite | `npx playwright test tests/browser/e2e-admin-vendor.spec.ts` against a freshly-migrated+built pinned-image server | All tests pass, zero AxeBuilder violations anywhere |
| No regressions | `.github/workflows/ci.yml`'s full PHP + Playwright CI run on the real PR | Every existing job stays green — this plan adds one migration and one new spec file, touches nothing else |
| `ci/verify-docs.sh` | Run from repo root | All gates pass (no hardcoded design values introduced — this plan touches no Blade/CSS) |

## Execution notes

Superpowers SDD, worktree-isolated (`superpowers:using-git-worktrees`), task-scoped review then whole-branch review, one PR for the whole suite — matching how `e2e-marketplace.spec.ts` landed as PR #139 despite spanning 4 tasks. No security/authorization design decision is made here (this plan seeds test accounts through the repo's own real, existing, audited grant Actions — it does not invent a new authorization mechanism), but because Task 1 creates real audited grant rows and Task 3 may need to extend that migration, the task-scoped reviewer for Tasks 1 and 3 should specifically confirm no shortcut around `GrantActorRole`/`GrantScopeAssignment` was taken.
