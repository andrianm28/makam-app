# Release-Gates Engineering Closeout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Close 3 real, engineering-doable gaps in `docs/testing/release-gates.md` (§D notification-matrix, §F renewal fixtures/timing) with real code and tests, plus correct 2 boxes that are stale relative to code already on trunk.

**Architecture:** No new subsystems. Each task extends an existing, already-wired mechanism (the order-notification bridge, the gated-seed-migration convention, the Livewire renewal-search component) with the one missing piece research this session already isolated precisely. The doc-only task corrects evidence text to match code that already exists.

**Tech Stack:** Laravel 13 / PHP 8.5, Filament 5 (admin panel), Livewire 4, Playwright (browser tests), PostgreSQL 18, PHPUnit.

**Spec:** No separate spec document — this plan implements 3 findings from `docs/testing/release-gates.md` directly, each individually re-verified against the current codebase this session (23 Aug 2026), continuing the `observability-and-adr-fixes` (PR #150) / `release-gates-post-150-corrections` (PR #151) work from earlier the same session.

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- Follow this repo's evidence-citation discipline for every `release-gates.md` box: cite real test names, never overclaim, only check a box when its FULL literal claim is evidenced. A box may stay unchecked with corrected/updated evidence if only part of its claim is proven — already the precedent set twice this session (PR #151's Horizon and manual-payment corrections).
- `AGENTS.md` §Observability: never place restricted data in logs/Pulse/Horizon tags/error trackers.
- No AWS; no production-affecting/security/authorization/financial/DNS/firewall change without human review. None of these 3 tasks touch that surface.
- Composer/npm builds do not run on this host — CI only (`CLAUDE.md`). None of these 3 tasks need a new package.
- `ci/verify-docs.sh` and `vendor/bin/pint --test` must pass after every task; run tests for real against the pinned container image + Postgres — never report `PASS` for a check that was not executed (`AGENTS.md` §Infrastructure-agent execution).
- This codebase's closed-list/enum convention (`RenewalStatus`, `RenewalSource`, `OrderStatus`, etc.) is authoritative — use the real constants, never a raw string literal that happens to match.
- Domain writes go through the real, existing sanctioned Action for that write (`RecordOrderStatusChange::initial()`, `MarkExternalRenewal`) — never a raw Eloquent `create()`/`DB::table()->insert()` bypass for anything the codebase already has an Action for. Fixture-only, non-domain rows (like a throwaway login's role/scope grant already does elsewhere) are the one exception this repo's own precedent allows, and only when no Action exists for that write.

## Correction from this plan's own scoping pass

A pre-plan research pass proposed a 4th task — "add a test proving a quarantined document's download is denied" (§H). Direct verification during planning found this test **already exists and already passes**: `tests/Feature/DocumentVault/DownloadDocumentTest.php::test_cross_purpose_revoked_policy_actor_binding_and_nonaccepted_denials_are_404` downgrades an accepted document to `DocumentState::Scanning` / `storage_prefix = 'quarantine'` and asserts the download 404s — exactly proving `DownloadDocument::download()`'s real `$document->state !== DocumentState::Accepted` guard (`app/Platform/DocumentVault/Actions/DownloadDocument.php:44-50`) works. The §H box (line 102) is stale, not a real gap — folded into Task 4 (doc-only) below instead of a new test-writing task, alongside §I's already-known-stale Horizon box.

---

### Task 1: Wire the "Booking submitted" notification

**Files:**
- Modify: `app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php`
- Modify: `tests/Feature/OrderWorkflow/OrderNotificationTest.php`
- Modify: `docs/testing/release-gates.md` (§D box)

**Interfaces:**
- Consumes: `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange::initial(Order $order, string $actorRef, string $actorRole, array $metadata = []): OrderStatusEvent` (already real — writes the `MASUK` status event and emits `order.status_changed.v1` via the outbox with `to_status=MASUK`).
- Consumes: `App\Platform\Notification\Jobs\ConsumeOutboxNotificationJob::dispatch(string $outboxEventId, ?string $matrixEventName = null)` — when `$matrixEventName` is provided, `DispatchNotification::consumeOutboxEvent()` looks up `notification_templates` by `event_name = $matrixEventName` directly (`app/Platform/Notification/Actions/DispatchNotification.php:132-134`), bypassing `outbox_event_name` entirely. The `notification_templates` row for `event_name = 'Booking submitted'` already exists (seeded by `database/migrations/2026_08_09_100020_seed_notification_templates_from_matrix.php`) — no new template row is needed.
- Produces: nothing new for later tasks — this task is self-contained.

**Why this is the real gap** (verified this session, not assumed): `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft` (`app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php`) is real, already wired into `BookingWizard.php`, and creates the order at `OrderStatus::MASUK` via `RecordOrderStatusChange::initial()` — this genuinely emits `order.status_changed.v1` with `to_status=MASUK` today. `DispatchOrderNotifications::handle()`'s `match ($toStatus)` only has arms for `DIPROSES`/`SELESAI`; `MASUK` falls through to `default => null` and the notification is silently dropped. The notification-matrix's "Booking submitted" row (`docs/contracts/notification-matrix.md:58`) already exists with real recipient/channel facts (EMAIL/WA to customer, IN_APP to admin), and its `notification_templates` row is already seeded — the ONLY missing piece is this one match arm.

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Feature/OrderWorkflow/OrderNotificationTest.php`, right after `test_order_processing_notification_dispatched_when_order_enters_diproses` (reuses the file's own existing `makeOrderAtStatus()`/`assertNotificationFor()`/`assertOutboxMissing()` helpers — no new helper needed):

```php
public function test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk(): void
{
    $order = $this->makeOrderAtStatus(OrderStatus::MASUK);

    $event = app(RecordOrderStatusChange::class)->initial(
        $order,
        'actor:test',
        'system',
    );

    $this->assertNotificationFor($event, 'Booking submitted');

    $this->assertOutboxMissing('order.processing.v1');
    $this->assertOutboxMissing('order.completed.v1');
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (against the pinned container image + real Postgres, per this repo's CI service setup — never SQLite):

```bash
vendor/bin/phpunit --filter test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk tests/Feature/OrderWorkflow/OrderNotificationTest.php
```

Expected: FAIL — `assertNotificationFor()`'s `self::assertNotNull($notificationEvent, ...)` fails because no `notification_events` row exists (the event falls through `DispatchOrderNotifications`'s `default => null` arm and is silently dropped).

- [ ] **Step 3: Add the `MASUK` match arm**

In `app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php`, change:

```php
        $matrixEventName = match ($toStatus) {
            OrderStatus::DIPROSES->value => 'Order processing',
            OrderStatus::SELESAI->value => 'Order completed',
            default => null,
        };
```

to:

```php
        $matrixEventName = match ($toStatus) {
            OrderStatus::MASUK->value => 'Booking submitted',
            OrderStatus::DIPROSES->value => 'Order processing',
            OrderStatus::SELESAI->value => 'Order completed',
            default => null,
        };
```

Also update the class doc block's bullet list (currently only names `DIPROSES`/`SELESAI`) to add a third bullet:

```
 *   - Order is created at MASUK (SubmitBookingDraft's RecordOrderStatusChange::
 *     initial() call) → "Booking submitted" template
```

And add one sentence after the existing "No `order.processing.v1` and no `order.completed.v1` exist" paragraph, since this is the first genuinely-used row whose `notification_templates.outbox_event_name` column is non-null but irrelevant to how it actually fires:

```
 * "Booking submitted"'s notification_templates row (seeded from the matrix)
 * carries outbox_event_name = 'booking.draft_submitted.v2' — a catalogued
 * event name no code in this repository emits (SubmitBookingDraft uses the
 * same order.status_changed.v1 + status-discrimination pattern as DIPROSES/
 * SELESAI, not a dedicated submission event). That column value is
 * therefore dead/unused for this row: the lookup here is entirely by
 * event_name via the explicit $matrixEventName argument (see
 * ConsumeOutboxNotificationJob's own doc block), which never reads
 * outbox_event_name. Left as-is rather than edited in the seed migration —
 * changing already-applied seed data is a separate, higher-risk change this
 * task does not need to make.
```

- [ ] **Step 4: Run test to verify it passes**

```bash
vendor/bin/phpunit --filter test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk tests/Feature/OrderWorkflow/OrderNotificationTest.php
```

Expected: PASS.

- [ ] **Step 5: Run the full existing test file to confirm no regression**

```bash
vendor/bin/phpunit tests/Feature/OrderWorkflow/OrderNotificationTest.php
```

Expected: all tests PASS, including `test_no_notification_emitted_for_other_status_transitions` (MASUK→DIVERIFIKASI still emits nothing — DIVERIFIKASI is a different `$toStatus`, untouched by this change) and `test_a_retried_publish_does_not_double_record_the_notification` (unaffected, uses DIPROSES).

- [ ] **Step 6: `php -l` and pint**

```bash
php -l app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php
vendor/bin/pint --test app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php tests/Feature/OrderWorkflow/OrderNotificationTest.php
```

Expected: no syntax errors; pint PASS (or run `vendor/bin/pint` without `--test` to auto-fix, then re-run `--test` to confirm clean).

- [ ] **Step 7: Update `docs/testing/release-gates.md`'s §D box**

Find the current box (starts `- [ ] Notification matrix implemented. — Re-investigated directly (22 Aug 2026, ...`). Replace its entire text with:

```markdown
- [x] Notification matrix implemented. — Re-investigated and closed (23 Aug 2026): `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft` is real and wired into `BookingWizard.php`, creating the order at `MASUK` via `RecordOrderStatusChange::initial()`, which genuinely emits `order.status_changed.v1`. `App\Domain\OrderWorkflow\Listeners\DispatchOrderNotifications` bridges every `order.status_changed.v1` transition to its matrix row (`MASUK` → "Booking submitted", `DIPROSES` → "Order processing", `SELESAI` → "Order completed") and dispatches `ConsumeOutboxNotificationJob`, proved end-to-end by `tests/Feature/OrderWorkflow/OrderNotificationTest.php::test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk` (23 Aug 2026 addition) alongside its two pre-existing DIPROSES/SELESAI siblings. All pass against real Postgres in CI. Two named, narrower, already-disclosed gaps remain outside this box's own claim: `WhatsApp enabled only with approved template/provider` (the next box) covers the WhatsApp-channel gap separately, and whether every other `TBD` matrix row (Marketplace/vendor rows) has an equivalent silent-drop gap was not audited in this pass — worth a future check, not claimed here.
```

- [ ] **Step 8: Run doc gates**

```bash
bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 9: Commit**

```bash
git add app/Domain/OrderWorkflow/Listeners/DispatchOrderNotifications.php tests/Feature/OrderWorkflow/OrderNotificationTest.php docs/testing/release-gates.md
git commit -m "feat(notifications): wire the Booking submitted matrix row to order creation"
```

---

### Task 2: External-renewal fixture + browser test (§F)

**Files:**
- Create: `database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php`
- Modify: `config/e2e_fixtures.php`
- Modify: `.github/workflows/ci.yml` (browser-test job env block)
- Create: `tests/browser/e2e-renewal-external.spec.ts`
- Modify: `docs/testing/release-gates.md` (§F box)

**Interfaces:**
- Consumes: `App\Domain\Renewal\Actions\MarkExternalRenewal::__invoke(GraveRecord $grave, string $targetDuePeriod, string $evidence, string $reason): void` (real, existing) — requires the calling actor to be authenticated (`Illuminate\Support\Facades\Auth::guard('web')->user()`, via `ActorContextResolver`) with `ActorRole::ADMIN` AND a `ScopeGrantLevel::PRIVILEGED` grant on the target grave's `cemetery_id`.
- Consumes: `App\Platform\IdentityAccess\Roles\Actions\GrantActorRole`, `App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment` (the same sanctioned grant Actions `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php` already uses — follow that file's exact pattern).
- Produces: one real `renewals` row (`source = RenewalSource::EXTERNAL`, `status = RenewalStatus::DIBAYAR`) and its paired `renewal_external_markings` row, visible in `RenewalOrderResource`'s List/View admin pages, for the browser test to assert against.

**Scope correction from this plan's own investigation** (do not scope this task any wider): `App\Domain\Renewal\Actions\MarkExternalRenewal` (creates a wholly-new, never-touched-online renewal) has **no Filament UI entry point anywhere in this codebase** — confirmed via `grep -rln "MarkExternalRenewal\b" app/` returning no Filament/Action file, and `RenewalOrderResource`'s own `Pages/` directory has only `ListRenewalOrders`/`ViewRenewalOrder`, no create page. The resource's one write action, `RecordExternalRenewalPaymentAction`, calls a **different** Action (`MarkRenewalPaidExternally`, for an already-online-opened renewal at `MENUNGGU_PEMBAYARAN` — see that Action's own doc block, which explicitly distinguishes it from `MarkExternalRenewal`). This task therefore does NOT browser-test the marking action itself (no button exists to click) — it fixtures a renewal via `MarkExternalRenewal` directly (a real, authenticated, audited call — not a raw Eloquent bypass) and browser-tests that `RenewalOrderResource`'s List/View pages correctly RENDER an externally-marked renewal, which is the real, currently-untested gap (`tests/browser/e2e-renewal.spec.ts`'s own comment: "No fixture ever creates a `renewals` row"). The marking Action's own behavior (authorization, duplicate-prevention via `renewals_grave_period_unique`) stays covered at the Feature level by the pre-existing `tests/Feature/Domain/Renewal/MarkExternalRenewalTest.php` — this task does not duplicate that coverage, and the release-gates.md evidence update in Step 8 states this distinction explicitly rather than overclaiming a UI-level proof that doesn't exist.

- [ ] **Step 1: Add the config gate**

In `config/e2e_fixtures.php`, add a second key next to the existing one:

```php
<?php

declare(strict_types=1);

/**
 * `seed_admin_vendor_users` exists for exactly one caller:
 * `database/migrations/2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`,
 * which `RefreshDatabase` applies once per PHPUnit process for EVERY Feature
 * test, not just the E2E-ADMIN/VENDOR browser suite it exists for. Left
 * unconditional, that migration's real `GrantActorRole`/`GrantScopeAssignment`
 * writes permanently pollute `actor_role_assignments`/`scope_assignments`/
 * `audit_events` for every unrelated test in the same process — confirmed the
 * hard way 21 Aug 2026: 8+ pre-existing tests broke, including
 * `PurgeStaleBookingDraftsTest` and `ServiceCatalogAuditIntegrationTest`, both
 * of which assert exact/zero counts on those tables.
 *
 * Deliberately NOT gated on `app()->environment('testing')` — that value is
 * ALSO what `phpunit.xml` sets for every ordinary PHPUnit run
 * (`<env name="APP_ENV" value="testing"/>`), so an `environment('testing')`
 * gate would silently seed these rows for every ordinary PHPUnit run too,
 * defeating the tests above. This is the exact same collision
 * `THROTTLE_PUBLIC_GUEST_DISABLED` (`config/rate_limiting.php`) already hit
 * and solved with a dedicated, default-false env flag — same fix shape
 * applied here. This flag is scoped to nothing but the one CI step
 * (`.github/workflows/ci.yml`'s browser-test job, "Migrate the application
 * database" — the step that actually runs `php artisan migrate --force`,
 * where this migration's `up()` executes) that explicitly opts in.
 *
 * `seed_external_renewal` follows the identical shape (23 Aug 2026) for
 * `2026_08_23_120000_seed_external_renewal_fixture.php` — its real
 * `MarkExternalRenewal` call writes a `renewals` row and audit events that
 * would equally pollute unrelated tests asserting exact counts on those
 * tables if left unconditional.
 */
return [
    'seed_admin_vendor_users' => (bool) env('SEED_E2E_ADMIN_VENDOR_USERS', false),
    'seed_external_renewal' => (bool) env('SEED_E2E_EXTERNAL_RENEWAL', false),
];
```

- [ ] **Step 2: Write the seed migration**

Create `database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php`:

```php
<?php

declare(strict_types=1);

/**
 * ===========================================================================
 * THIS IS DUMMY / PLACEHOLDER DATA. NONE OF THE FOLLOWING IS REAL.
 * ===========================================================================
 * One fictional throwaway externally-marked renewal for the browser suite
 * (`tests/browser/e2e-renewal-external.spec.ts`) — closes the real gap
 * `tests/browser/e2e-renewal.spec.ts` names in its own comment: "No fixture
 * ever creates a `renewals` row."
 *
 * ---------------------------------------------------------------------------
 * Why a data migration and not `database/seeders/`
 * ---------------------------------------------------------------------------
 * Nothing in CI, the Dockerfile, or any deployment script runs
 * `php artisan db:seed` — every fixture dataset in this repository ships as
 * a timestamped data migration instead (same reasoning as
 * `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`).
 *
 * ---------------------------------------------------------------------------
 * Why `MarkExternalRenewal`, not a raw `Renewal::create()`
 * ---------------------------------------------------------------------------
 * `App\Domain\Renewal\Actions\MarkExternalRenewal` is this codebase's only
 * sanctioned write path for an externally-marked renewal (see that class's
 * own doc block) — a real, audited write, not a test-only bypass. Calling
 * it here keeps the seeded row indistinguishable, from the application's
 * own point of view, from a real admin-recorded external marking. It
 * requires an authenticated actor holding `ActorRole::ADMIN` and a
 * `ScopeGrantLevel::PRIVILEGED` cemetery grant, resolved via
 * `Illuminate\Support\Facades\Auth::guard('web')->user()`
 * (`ActorContextResolver`) — this migration logs a throwaway fixture admin
 * in via `Auth::guard('web')->login()` for exactly the duration of the
 * call, then logs out, so no session state leaks into the migration
 * process beyond this one write.
 *
 * ---------------------------------------------------------------------------
 * Gated behind `config('e2e_fixtures.seed_external_renewal')`, default false
 * ---------------------------------------------------------------------------
 * Same reasoning as `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`
 * and `config/e2e_fixtures.php`'s own doc block: `RefreshDatabase` applies
 * this migration once per PHPUnit process, so an unconditional `up()` would
 * permanently write a real `renewals`/`renewal_external_markings`/
 * `audit_events` row into every unrelated Feature test's database.
 *
 * ---------------------------------------------------------------------------
 * The grave record and its cemetery are looked up, never hardcoded
 * ---------------------------------------------------------------------------
 * `2026_08_08_100010_seed_example_grave_records.php` seeds grave records
 * with generated ids not guaranteed stable across a from-scratch migration
 * run in a different environment — this migration queries the real,
 * current first `grave_records` row (and its real `cemetery_id`) at
 * migration time instead of assuming an id, matching
 * `2026_08_22_110000_seed_e2e_admin_vendor_test_users.php`'s own
 * `$firstVendorId` precedent.
 */

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Models\Renewal;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

return new class extends Migration
{
    private const string ADMIN_EMAIL = 'e2e-renewal-admin@example.test';

    private const string TARGET_DUE_PERIOD = '2027-01-01';

    private const string EVIDENCE = 'Kuitansi pembayaran tunai kantor TPU, No. E2E-RENEWAL-0001 (data contoh untuk pengujian).';

    private const string REASON = 'E2E-renewal browser-suite seed — pembayaran eksternal contoh, bukan data nyata.';

    public function up(): void
    {
        if (! config('e2e_fixtures.seed_external_renewal')) {
            return;
        }

        if (app()->isProduction()) {
            return;
        }

        $grave = GraveRecord::query()->orderBy('id')->first();

        if ($grave === null) {
            // No grave records exist in this environment yet — skip rather
            // than fail a real deployment, matching this repo's existing
            // fixture-skip convention (e.g. VendorListingExampleData::seed()).
            return;
        }

        if (Renewal::query()
            ->where('grave_record_id', $grave->id)
            ->where('target_due_period', self::TARGET_DUE_PERIOD)
            ->exists()
        ) {
            // Idempotency guard: the unique index this fixture exercises
            // (renewals_grave_period_unique) is the same guard a re-run
            // would otherwise trip.
            return;
        }

        $admin = User::query()->firstOrCreate(
            ['email' => self::ADMIN_EMAIL],
            [
                'name' => 'E2E Renewal Admin (Contoh)',
                'password' => Hash::make('E2eRenewalAdminPassword!1'),
                'email_verified_at' => now(),
            ],
        );

        if (! DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->where('role', ActorRole::ADMIN)->exists()) {
            app(GrantActorRole::class)(
                actorIdentifier: $admin->id,
                role: ActorRole::ADMIN,
                reason: 'E2E-renewal browser-suite seed — throwaway CI/dev login, not a real operator grant.',
                grantedBy: null,
            );
        }

        if (! DB::table('scope_assignments')
            ->where('actor_identifier', (string) $admin->id)
            ->where('entity_type', ScopeEntityType::CEMETERY)
            ->where('entity_id', (string) $grave->cemetery_id)
            ->exists()
        ) {
            app(GrantScopeAssignment::class)(
                actorIdentifier: $admin->id,
                entityType: ScopeEntityType::CEMETERY,
                entityId: $grave->cemetery_id,
                grantLevel: ScopeGrantLevel::PRIVILEGED,
                reason: 'E2E-renewal browser-suite seed — grants privileged access so MarkExternalRenewal is authorized for this fixture.',
                grantedBy: null,
            );
        }

        Auth::guard('web')->login($admin);

        try {
            app(MarkExternalRenewal::class)(
                $grave,
                self::TARGET_DUE_PERIOD,
                self::EVIDENCE,
                self::REASON,
            );
        } finally {
            Auth::guard('web')->logout();
        }
    }

    public function down(): void
    {
        // Deliberately does NOT delete the audit_events rows GrantActorRole/
        // GrantScopeAssignment/MarkExternalRenewal wrote above — an audit
        // log is intentionally not erasable by a migration rollback.
        Renewal::query()
            ->where('target_due_period', self::TARGET_DUE_PERIOD)
            ->whereHas('graveRecord', fn ($query) => $query->orderBy('id'))
            ->delete();

        $admin = User::query()->where('email', self::ADMIN_EMAIL)->first();

        if ($admin !== null) {
            DB::table('scope_assignments')->where('actor_identifier', (string) $admin->id)->delete();
            DB::table('actor_role_assignments')->where('actor_identifier', (string) $admin->id)->delete();
            $admin->delete();
        }
    }
};
```

- [ ] **Step 3: Wire the CI env var**

In `.github/workflows/ci.yml`, in the "Browser + a11y smoke test (Playwright)" job's env block, immediately after the existing `SEED_E2E_ADMIN_VENDOR_USERS: "true"` line, add:

```yaml
          # Opts the external-renewal seed migration
          # (database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php)
          # into actually writing its one throwaway externally-marked
          # renewal -- config/e2e_fixtures.php defaults this to false
          # everywhere else, same reasoning as SEED_E2E_ADMIN_VENDOR_USERS
          # immediately above.
          SEED_E2E_EXTERNAL_RENEWAL: "true"
```

- [ ] **Step 4: Write the browser test**

Create `tests/browser/e2e-renewal-external.spec.ts`. Reuse the exact real login pattern already established and hard-won in `tests/browser/e2e-admin-vendor.spec.ts`'s `adminLogin()` helper — its own comment block explains why: Filament's login page renders under this app's Indonesian locale, so `getByLabel('Email address')` never resolves and previously caused a real CI timeout; the real labels are `'Alamat email'` and a password field whose accessible name is `'Kata sandi'` (scoped to `getByRole('textbox', ...)` since two reveal-toggle buttons also match `'kata sandi'` in their aria-labels), and the submit button reads `'Masuk'`, not `'Sign in'`. Do not re-guess these — copy the verified pattern:

```typescript
import { expect, test, type Page } from '@playwright/test';

/**
 * Browser-level proof that `RenewalOrderResource`'s List/View admin pages
 * correctly render an externally-marked renewal — the real gap
 * `tests/browser/e2e-renewal.spec.ts` names in its own comment ("No
 * fixture ever creates a `renewals` row"). The fixture is seeded by
 * `database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php`
 * via the real `MarkExternalRenewal` Action (gated on
 * `SEED_E2E_EXTERNAL_RENEWAL=true`, the same CI-only opt-in pattern
 * `e2e-admin-vendor.spec.ts`'s own fixture uses).
 *
 * Login flow mirrors `e2e-admin-vendor.spec.ts::adminLogin()` exactly —
 * see that file's own comment block for why the label/button text is
 * Indonesian ('Alamat email' / 'Kata sandi' / 'Masuk'), not English.
 *
 * Scope note (see this suite's plan, Task 2): `MarkExternalRenewal` has no
 * Filament UI entry point in this codebase — this suite proves the
 * RESOURCE renders an externally-marked row correctly, not that the
 * marking action itself is triggerable from the UI (it isn't).
 * `MarkExternalRenewalTest.php` (Feature-level) remains the authority for
 * the action's own authorization/duplicate-prevention behavior.
 */
async function adminLogin(page: Page): Promise<void> {
    await page.goto('/admin/login');
    await page.getByLabel('Alamat email').fill('e2e-renewal-admin@example.test');
    await page.getByRole('textbox', { name: 'Kata sandi' }).fill('E2eRenewalAdminPassword!1');
    await page.getByRole('button', { name: 'Masuk' }).click();
    await page.waitForURL(/\/admin\/?$/);
    await page.waitForLoadState('networkidle');
}

test.describe('E2E-RENEWAL-EXTERNAL — admin resource renders an externally-marked renewal', () => {
    test.beforeEach(async ({ page }) => {
        await adminLogin(page);
    });

    test('the renewal list shows the externally-marked fixture row', async ({ page }) => {
        await page.goto('/admin/renewal-orders');

        const row = page.getByRole('row', { name: /Dibayar/ }).first();
        await expect(row).toBeVisible();
        await expect(row.getByText('external')).toBeVisible();
    });

    test('the renewal view page shows the real external-marking evidence', async ({ page }) => {
        await page.goto('/admin/renewal-orders');
        await page.getByRole('row', { name: /Dibayar/ }).first().click();

        await expect(page.getByText('Dibayar')).toBeVisible();
        await expect(page.getByText('external')).toBeVisible();
        await expect(
            page.getByText('Kuitansi pembayaran tunai kantor TPU, No. E2E-RENEWAL-0001'),
        ).toBeVisible();
        await expect(
            page.getByText('E2E-renewal browser-suite seed — pembayaran eksternal contoh, bukan data nyata.'),
        ).toBeVisible();
    });
});
```

If the real rendered List/View page uses a different structure than a `<table>` `row`/`getByText` for the status badge or source column (verify against the actual rendered DOM, e.g. via `RenewalOrdersTable`'s real column components read during Task B's own investigation), adjust the locators to match reality — the login flow above is verified; the resource-page locators are reasoned from `RenewalOrdersTable.php`/`RenewalOrderInfolist.php`'s real column/entry definitions read during planning, but confirm against the actual rendered page before finalizing.

- [ ] **Step 5: Run `ci/verify-infra.sh`-style local check is not applicable — run the browser suite for real**

Run against the pinned container image + a real disposable Postgres/Redis + `SEED_E2E_EXTERNAL_RENEWAL=true`, matching this session's own established discipline for verifying Playwright tests (see the `phase1-uat-pass` plan's verification approach): migrate the test database with the env var set, serve the app, run:

```bash
npx playwright test tests/browser/e2e-renewal-external.spec.ts
```

Expected: both tests PASS. If a selector doesn't match the real rendered page, adjust the test to match the real DOM (read the actual rendered HTML/Filament component output, don't guess a second time).

- [ ] **Step 6: `php -l` and pint on the new migration**

```bash
php -l database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php
php -l config/e2e_fixtures.php
vendor/bin/pint --test database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php config/e2e_fixtures.php
```

- [ ] **Step 7: Confirm no regression in the existing Feature/browser suites**

```bash
vendor/bin/phpunit tests/Feature/Domain/Renewal/MarkExternalRenewalTest.php
npx playwright test tests/browser/e2e-renewal.spec.ts
```

Expected: both suites unchanged, all PASS — the new migration is gated `false` by default, so `MarkExternalRenewalTest.php`'s own fixture construction (whatever it does independently) and `e2e-renewal.spec.ts`'s existing not-found assertions are unaffected.

- [ ] **Step 8: Update `docs/testing/release-gates.md`'s §F box**

Find the current box (starts `- [ ] External renewal marking and duplicate prevention pass. — Investigated directly (22 Aug 2026)...`). Replace its entire text with:

```markdown
- [ ] External renewal marking and duplicate prevention pass. — **Partially closed (23 Aug 2026).** Feature-level coverage remains strong and unchanged: `MarkExternalRenewalTest.php` (11 tests: authorization, `renewals_grave_period_unique` duplicate-prevention, audit logging, state transitions) and `RenewalOrderResourceTest.php` (7 tests: resource access control, action authorization). **New this pass**: `tests/browser/e2e-renewal-external.spec.ts` proves `RenewalOrderResource`'s List/View pages genuinely render an externally-marked renewal (status, source, evidence, reason) for the first time — closing the fixture gap `e2e-renewal.spec.ts` named in its own comment ("No fixture ever creates a `renewals` row"), via a real `MarkExternalRenewal` call in a gated seed migration (`2026_08_23_120000_seed_external_renewal_fixture.php`, `SEED_E2E_EXTERNAL_RENEWAL=true`). **Still not closeable from this codebase**: `MarkExternalRenewal` has no Filament UI entry point anywhere — confirmed via `grep -rln "MarkExternalRenewal\b" app/`, only `MarkExternalRenewalTest.php` calls it — so a browser-level proof of the MARKING ACTION itself (clicking a button that triggers it, or observing its duplicate-prevention exception from the UI) is not possible in this codebase today; that would require building a UI entry point first, out of this task's scope. Left unchecked: the browser-level rendering gap is closed, but the box's literal "marking... pass" claim still rests on Feature-level evidence only, honestly, not a UI-level one.
```

- [ ] **Step 9: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 10: Commit**

```bash
git add config/e2e_fixtures.php database/migrations/2026_08_23_120000_seed_external_renewal_fixture.php .github/workflows/ci.yml tests/browser/e2e-renewal-external.spec.ts docs/testing/release-gates.md
git commit -m "test(renewal): fixture + browser coverage for an externally-marked renewal"
```

---

### Task 3: Request-level renewal search timing (§F)

**Files:**
- Create: `tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php`
- Modify: `docs/testing/release-gates.md` (§F box)

**Interfaces:**
- Consumes: `App\Livewire\Public\Renewal\GraveSearch` (real, existing Livewire component — `render()` calls `GraveRegistryPublicQuery::search($this->criteria())` when the gate is open, a cemetery is selected, and `$this->searched` is true).
- Consumes: `App\Console\Commands\GenerateGraveRegistryLoadDatasetCommand` (`bench:generate-grave-dataset`, real, existing — `--cemeteries`/`--records`/`--chunk` options) to build a real, non-trivial single-cemetery dataset inside the test.
- Consumes: `Tests\Feature\Livewire\Public\Renewal\GraveSearchStatesTest`'s own `openTheDataGate()` pattern (`FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open'])`) and `Tests\Support\CemeteryFixture` for a real published cemetery.

**Scale note (explicit, not a silent gap)**: `docs/operations/performance-and-capacity.md`'s AC4 target is 500ms at 100,000 records, already certified at the raw-query level by `BenchGraveSearchCommand` (p95 7.19ms). Generating a genuine 100k-record dataset inside a PHPUnit Feature test is impractical for routine CI (the generator itself is a multi-second-to-minutes bulk-insert operation meant for a dedicated benchmark run, not every test suite execution). This task seeds **one single cemetery with 5,000 records** (`--cemeteries=1 --records=5000`) — about 5% of the certified target — reasoned as follows: the raw query's cost at 100k records is already proven near-flat (7ms), so the only thing a request-level test needs to add is proof that Livewire/HTTP-layer overhead doesn't erase that margin; that overhead is not meaningfully a function of dataset size (it's parsing, session, rendering cost), so a smaller-but-real single-cemetery dataset answers this specific question just as validly as 100k would, at a fraction of the CI cost. This test is a **request-level smoke/regression test proving the pattern holds today**, not a full-scale AC4 recertification — the box's evidence update in Step 6 states this precisely, matching this session's own established honest-scope-boundary precedent (the k6/performance-tooling plan drew the identical line for full Profile B-D certification).

- [ ] **Step 1: Read the real component API and existing gate-opening pattern**

Read `app/Livewire/Public/Renewal/GraveSearch.php` in full (already read during planning — confirm nothing has changed) and `tests/Feature/Livewire/Public/Renewal/GraveSearchStatesTest.php`'s `openTheDataGate()` helper and its `Tests\Support\CemeteryFixture` usage before writing the new test, to match the exact real setup pattern rather than reinventing it.

- [ ] **Step 2: Write the test**

Create `tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php`:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Renewal;

use App\Console\Commands\GenerateGraveRegistryLoadDatasetCommand;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * Request-level companion to `App\Console\Commands\BenchGraveSearchCommand`
 * (`docs/operations/performance-and-capacity.md` §2 AC4: "below 500ms at
 * 100,000 records"), which certifies `GraveRegistryPublicQuery::search()`'s
 * own wall-clock cost directly (p95 7.19ms at 100k records) but not the
 * full HTTP/Livewire request cycle a real renewal user experiences —
 * `App\Livewire\Public\Renewal\GraveSearch::render()` is what actually
 * calls that query from a live request.
 *
 * SCALE NOTE (see this suite's plan, Task 3): this test seeds ONE
 * cemetery with 5,000 records (~5% of the certified 100k target), not a
 * full-scale dataset — the raw query cost at 100k is already proven
 * near-flat by BenchGraveSearchCommand, so what this test adds is proof
 * that Livewire/HTTP-layer overhead does not erase that margin, which
 * does not require 100k rows to demonstrate. This is a request-level
 * smoke/regression proof, not a full AC4 recertification.
 */
final class GraveSearchPerformanceTest extends TestCase
{
    use RefreshDatabase;

    private function openTheDataGate(): void
    {
        FeatureGate::query()->where('gate_id', 'G-DATA-01')->update(['state' => 'open']);
    }

    public function test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale(): void
    {
        $this->openTheDataGate();

        Artisan::call(GenerateGraveRegistryLoadDatasetCommand::class, [
            '--cemeteries' => 1,
            '--records' => 5000,
        ]);

        $cemeteryId = DB::table('cemeteries')
            ->where('name', 'like', 'Contoh TPU Beban %')
            ->value('id');
        self::assertNotNull($cemeteryId, 'The benchmark dataset generator did not create a cemetery.');

        $sampleName = DB::table('grave_records')
            ->where('cemetery_id', $cemeteryId)
            ->value('deceased_name');
        self::assertNotNull($sampleName, 'The benchmark dataset generator did not create any grave records.');
        $searchTerm = mb_substr((string) $sampleName, 0, 4);

        $start = microtime(true);

        Livewire::test(GraveSearch::class, ['cemeteryId' => $cemeteryId])
            ->set('name', $searchTerm)
            ->call('search')
            ->assertSet('searched', true);

        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(
            500.0,
            $elapsedMs,
            sprintf('Full request-level grave search took %.2fms, over the 500ms AC4 budget.', $elapsedMs)
        );
    }
}
```

Both names above are verified against the real, current files (not guessed): `GraveSearch::$cemeteryId` is a public `string` Livewire property (`app/Livewire/Public/Renewal/GraveSearch.php:91`), and `GenerateGraveRegistryLoadDatasetCommand::CEMETERY_NAME_PREFIX` is the literal string `'Contoh TPU Beban '` (`app/Console/Commands/GenerateGraveRegistryLoadDatasetCommand.php:51`) — the code above already uses both correctly. Re-confirm both are still accurate at implementation time in case either file has changed since this plan was written.

- [ ] **Step 3: Run the test**

```bash
vendor/bin/phpunit --filter test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php
```

Expected: PASS, with the real measured milliseconds visible if you add a temporary `dump($elapsedMs)` while verifying (remove before commit) — record the real observed number for the release-gates.md evidence update in Step 6.

- [ ] **Step 4: Confirm dataset generation itself doesn't blow the CI test-suite time budget**

Time the full test file's run:

```bash
time vendor/bin/phpunit tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php
```

If total wall time (including the 5,000-record generation) is unreasonably slow for a single Feature test (as a concrete bar: over ~10 seconds), reduce `--records` further (e.g. to 1,000) and re-justify the smaller number in the test's own doc block and the release-gates.md evidence — do not silently keep an overly slow test without noting the adjustment.

- [ ] **Step 5: `php -l` and pint**

```bash
php -l tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php
vendor/bin/pint --test tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php
```

- [ ] **Step 6: Update `docs/testing/release-gates.md`'s §F box**

Find the current box (starts `- [ ] Search performance target passes. — ...`). Replace its entire text with:

```markdown
- [ ] Search performance target passes. — **Partially closed (23 Aug 2026).** `BenchGraveSearchCommand` continues to certify the raw query at full scale (p50 7.02ms/p95 7.19ms/p99 7.34ms at 100k records, real `bench:grave-search` run). **New this pass**: `tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php::test_a_full_search_request_completes_within_the_500ms_budget_at_a_representative_scale` proves the FULL request cycle (`Livewire::test(GraveSearch::class)`, the same component the public `/perpanjangan/cari` route uses) also completes well inside the 500ms budget at a real, seeded [FILL IN: the number of records actually used from Step 4] single-cemetery dataset — [FILL IN: the real measured elapsed-ms number observed in Step 3]ms observed. **Scale note, stated explicitly rather than left implicit**: this is ~5% (or whatever fraction Step 4 settled on) of the certified 100k target, not a full-scale recertification — generating a genuine 100k-record dataset inside a routine PHPUnit run is impractical; the reasoning for why a smaller dataset still validly answers the request-level question is recorded in the test's own doc block. Full-scale (100k+) request-level certification, if ever needed, belongs with whatever dedicated performance-testing infrastructure (e.g. the k6 profiles referenced elsewhere in this document) runs outside routine CI.
```

Fill in the two bracketed placeholders with the real numbers observed in Steps 3-4 before committing — do not leave them as literal `[FILL IN: ...]` text in the committed file.

- [ ] **Step 7: Run doc gates**

```bash
bash ci/verify-docs.sh
```

- [ ] **Step 8: Commit**

```bash
git add tests/Feature/Livewire/Public/Renewal/GraveSearchPerformanceTest.php docs/testing/release-gates.md
git commit -m "test(renewal): add request-level grave-search timing proof"
```

---

### Task 4: Doc-only corrections — §H quarantine box, §I Horizon-cap box

**Files:**
- Modify: `docs/testing/release-gates.md` (§H line ~102, §I line ~113)

**Interfaces:** None — this task touches only `docs/testing/release-gates.md`. No code, no tests.

- [ ] **Step 1: Correct §H's quarantine/malware box**

Find the current box (starts `- [ ] Upload quarantine and malware-scanner fail-closed behavior pass. — Inconclusive...`). This box was written before direct verification; this session's planning pass confirmed the "quarantine blocks download" half is real and already tested. Replace its entire text with:

```markdown
- [ ] Upload quarantine and malware-scanner fail-closed behavior pass. — **Corrected (23 Aug 2026)**: the prior "inconclusive" verdict was wrong about the download-gating half — direct code read confirms `App\Platform\DocumentVault\Actions\DownloadDocument::download()` (`app/Platform/DocumentVault/Actions/DownloadDocument.php:44-50`) genuinely checks `$document->state !== DocumentState::Accepted` and `$document->storage_prefix !== 'accepted'` before streaming, and `tests/Feature/DocumentVault/DownloadDocumentTest.php::test_cross_purpose_revoked_policy_actor_binding_and_nonaccepted_denials_are_404` already proves it: a document downgraded to `DocumentState::Scanning`/`storage_prefix='quarantine'` after a valid grant was issued still gets a 404 on download. This half of the claim — quarantine/non-accepted status genuinely blocks download — is real, tested, and passing. **Still genuinely open**: no real malware-scanner adapter exists (only `app/Platform/DocumentVault/Adapters/MockScanner.php` — no ClamAV or equivalent is implemented), which `AGENTS.md`/ADR-0027 explicitly keep off the shared dev/staging host ("always-on ClamAV... prohibited") — this is an infra/procurement gap, not a test-coverage gap, and stays open for that reason.
```

- [ ] **Step 2: Correct §I's Horizon-cap box**

Find the current box (starts `- [ ] Staging normal Horizon pool is capped at two processes...`). Replace its entire text with:

```markdown
- [ ] Staging normal Horizon pool is capped at two processes; development/batch workers run on demand. — **Corrected (23 Aug 2026, after PR #150)**: `config/horizon.php` now exists (added by PR #150; §H's own Horizon box already corrected once for this). Its `staging` environment block genuinely sets `supervisor-normal => ['minProcesses' => 1, 'maxProcesses' => 2]` with all 6 other baseline/batch/reports supervisors set `false` — the literal "capped at two processes" half of this claim is now true in committed config, verified by direct code read. `dev-worker` and `stg-batch-worker` continue to correctly use Compose `profiles:` (`["dev-worker"]`/`["batch"]`) so they only run on demand — that half was already true. Left unchecked: config-level verification is not the same as an operational rehearsal on the real live host (Horizon actually running against real Redis, actually staying at 2 processes under real load) — matching the same distinction §H's Horizon box already draws for its own remaining gap.
```

- [ ] **Step 3: Run doc gates**

```bash
bash ci/verify-docs.sh
```

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 4: Commit**

```bash
git add docs/testing/release-gates.md
git commit -m "docs(testing): correct two release-gates.md boxes stale after PR #150 / this session's own verification"
```

---

## Verification

| Task | Done when |
|---|---|
| 1 | `test_booking_submitted_notification_dispatched_when_order_is_created_at_masuk` passes against real Postgres; §D box updated with real evidence and checked |
| 2 | `e2e-renewal-external.spec.ts` passes against the pinned image + real Postgres/Redis with `SEED_E2E_EXTERNAL_RENEWAL=true`; §F's marking box updated with precise, non-overclaiming evidence |
| 3 | `GraveSearchPerformanceTest` passes with a real observed elapsed-ms number recorded in the commit and the release-gates.md evidence; §F's search box updated |
| 4 | Both corrected boxes cite real, currently-true evidence; `ci/verify-docs.sh` passes |

Final whole-branch review (per `superpowers:subagent-driven-development`) checks cross-task consistency — in particular, that Task 2's and Task 3's new `config/e2e_fixtures.php`/CI env additions don't collide with each other or with the existing `SEED_E2E_ADMIN_VENDOR_USERS` gate, and that no task's new migration accidentally seeds real production-shaped data unconditionally.

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped spec+quality review after each, one final whole-branch review before PR. This is the standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution.
