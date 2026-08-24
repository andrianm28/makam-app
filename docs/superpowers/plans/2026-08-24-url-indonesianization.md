# URL Path Indonesianization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Bring every URL path in the live application to consistent Bahasa Indonesia (except naturalized loanwords: `admin`, `marketplace`, `faq`, `password`, `vendor`, and the internal-only `feature-gates`), with permanent redirects for the 2 public-facing renames and a clean cutover for the ~40 internal admin/vendor panel renames.

**Architecture:** No new subsystems. Route path *strings* change; route *names* (already English, e.g. `cemeteries.index`) do not, so the overwhelming majority of internal `route('name')` callers need zero changes — confirmed directly this session via grep: zero literal hardcoded path-string references exist anywhere in `app/`, `resources/`, or `tests/` for `/cemeteries` or `/memorial/`. Filament admin/vendor resource slugs are simple `protected static ?string $slug` string-literal changes (Pages already declare this property explicitly; Resources mostly rely on Filament's auto-derivation from the class name and need the property added).

**Tech Stack:** Laravel 13 / PHP 8.5, Filament 4 admin/vendor panels.

**Spec:** `docs/superpowers/specs/2026-08-24-url-indonesianization-design.md`

## Global Constraints

- Route **names** (`->name('...')`) never change — only path strings. If a task's diff touches a `->name(...)` call, that is a mistake; revert it.
- Explicitly out of scope, never touched by any task: `/pembayaran/kembali`, `/pembayaran/batal` (already Indonesian), `/health/live`, `/health/ready` (infra convention), `/internal/documents/{document}/download/{token}` (programmatic signed-URL engine), the `bantuan@makam.co.id` email address (`app/Support/ContactInfo.php`), and every route already Indonesian or a kept loanword (`/admin`, `/marketplace`, `/faq`, `/reset-password`, `/lupa-password`, `/preneed`, all 19 already-Indonesian public routes, `feature-gates`, `vendors`).
- **Filament admin/vendor panel `login`/`logout` routes are dropped from this plan's scope.** Unlike the public `/masuk`/`/keluar`/`/daftar` routes (plain `Route::get` entries, trivial to rename), Filament's panel authentication routes are framework-managed (`->login()` in `AdminPanelProvider`/`VendorPanelProvider`) and renaming their path requires overriding Filament's own login/logout page routing mechanism, not a simple `$slug` property — disproportionate complexity for an internal-staff-only convenience rename. Confirmed via `grep -n "->login()" app/Providers/Filament/AdminPanelProvider.php`. Do not attempt this in any task below.
- Redirects (`Route::permanentRedirect()`) apply ONLY to the 2 public routes in Task 1. The admin/vendor panel slug renames in Tasks 2-3 get NO redirects — internal-only, never search-indexed, navigated via in-app links that self-heal through `Resource::getUrl()`.
- `Route::permanentRedirect($uri, $destination)` in this app's Laravel 13 (confirmed by reading `vendor/laravel/framework/src/Illuminate/Routing/Router.php:272` and `RedirectController.php` directly) DOES support route parameters natively — `{profileId}` in the destination is bound automatically from the matched source route's parameters. No closure-based workaround is needed.
- **Doc updates distinguish "current state" from "historical record."** `docs/product/screen-inventory.md` and `docs/domain/traceability-matrix.md` describe CURRENT shipped state and must be updated to the new paths. `docs/planning/sprint-plan.md`'s dated deploy-verification log rows and `.kiro/specs/*/tasks.md`'s dated completion notes are historical record of what was true and tested on a specific past date (e.g. "`/cemeteries` (200, all five launch cities present)" dated 08 Aug 2026) — these are NEVER rewritten; doing so would falsify history. If genuinely unsure whether a doc line is current-state or historical-log, leave it and note it in your task report rather than guessing.
- This host cannot run npm/composer builds — CI only. Tests run via the established recipe: start disposable `postgres:18` + `redis:8.2-alpine` containers, then `docker run --network host --user 1000:1000 -e DB_CONNECTION=pgsql ... <pinned ghcr.io/andrianm28/makam-app image, find via: docker images --digests | grep makam-app> php -d memory_limit=512M vendor/bin/phpunit <paths>`. Use `vendor/bin/phpunit` directly, never `php artisan test` (misleading truncated output on this host, confirmed this session).
- `bash ci/verify-docs.sh` must pass after every task.
- No AWS; no DNS/firewall changes needed (pure application-level routing).

---

### Task 1: Public routes — `/cemeteries` and `/memorial/{profileId}`

**Files:**
- Modify: `routes/web.php`
- Modify: `docs/product/screen-inventory.md`
- Modify: `docs/domain/traceability-matrix.md`
- Test: `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`, `tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php`, `tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageTest.php` (create if it doesn't exist — check first) — new redirect-assertion tests

**Interfaces:**
- Consumes: nothing from other tasks (independent).
- Produces: nothing consumed by later tasks (independent — Tasks 1-3 touch disjoint files).

- [ ] **Step 1: Read the real current route definitions**

Read `routes/web.php` lines 160-310 (the Cemetery Directory and Memorial sections). Confirm the exact current lines:

```php
Route::get('/cemeteries', CemeteryDirectoryIndex::class)->name('cemeteries.index');
Route::get('/cemeteries/{cemeterySlug}', CemeteryDetail::class)->name('cemeteries.show');
```
and
```php
Route::get('/m/{token}', MemorialPublicPage::class)->name('memorial.show');
Route::get('/memorial/{profileId}', MemorialFamilyPage::class)->name('memorial.family');
```

**Do not rename `/m/{token}`** — it's the QR-scan shortlink prefix, not a translatable word, and is not in this plan's scope. Only `/memorial/{profileId}` (the `memorial.family` route) changes.

- [ ] **Step 2: Rename the path strings, keep the names**

```php
Route::get('/pemakaman', CemeteryDirectoryIndex::class)->name('cemeteries.index');
Route::get('/pemakaman/{cemeterySlug}', CemeteryDetail::class)->name('cemeteries.show');
```
and
```php
Route::get('/kenangan/{profileId}', MemorialFamilyPage::class)->name('memorial.family');
```

- [ ] **Step 3: Add permanent redirects from the old paths**

Add these immediately after the renamed route definitions (register them where the old routes used to be, so the redirect and the new route are easy to find together):

```php
Route::permanentRedirect('/cemeteries', '/pemakaman');
Route::permanentRedirect('/cemeteries/{cemeterySlug}', '/pemakaman/{cemeterySlug}');
Route::permanentRedirect('/memorial/{profileId}', '/kenangan/{profileId}');
```

- [ ] **Step 4: Confirm zero literal path-string call sites need updating**

Run these two commands and confirm both return no output (already confirmed this session — this step is a verification, not expected to find anything):

```bash
grep -rn "['\"]\/cemeteries['\"/]" --include="*.php" --include="*.blade.php" --include="*.ts" app resources tests | grep -v routes/web.php
grep -rn "['\"]\/memorial\/" --include="*.php" --include="*.blade.php" --include="*.ts" app resources tests | grep -v routes/web.php
```

If either produces output, read the matching file and update the literal path string to the new path (this would mean the earlier research was incomplete for a file added since) — do not skip a real hit.

- [ ] **Step 5: Update the 2 current-state docs**

`docs/product/screen-inventory.md`: update the PUB-011 row (line ~92, "standalone `/cemeteries` and `/cemeteries/{cemeterySlug}`") and the PUB-092 row (line ~120, "Memorial family — `/memorial/{profileId}`") to the new paths.

`docs/domain/traceability-matrix.md`: update line ~310 ("PUB-011 shipped standalone at `/cemeteries` and `/cemeteries/{cemeterySlug}`") and line ~438 ("`VisitationPage` (`/kunjungan`...)... `MemorialPublicPage` (`/m/{token}`), `MemorialFamilyPage` (`/memorial/{profileId}`)" — only change the `/memorial/{profileId}` part, `/m/{token}` and `/kunjungan` are unchanged) to the new paths.

Do NOT touch `docs/planning/sprint-plan.md` or `.kiro/specs/cemetery-directory-and-availability/tasks.md` in this task — their `/cemeteries` mentions are dated historical deploy/completion log entries (see Global Constraints).

- [ ] **Step 6: Add redirect-assertion tests**

In `tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php`, add:

```php
public function test_the_old_cemeteries_path_redirects_permanently_to_pemakaman(): void
{
    $this->get('/cemeteries')
        ->assertRedirect('/pemakaman')
        ->assertStatus(301);
}
```

In `tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php`, read the file first to find a real seeded cemetery slug fixture pattern already used in that file's other tests, then add:

```php
public function test_the_old_cemeteries_slug_path_redirects_permanently_to_pemakaman(): void
{
    // Use the same cemetery-fixture creation pattern this file's other tests already use.
    $cemetery = /* real fixture creation, matching this file's existing convention */;

    $this->get("/cemeteries/{$cemetery->slug}")
        ->assertRedirect("/pemakaman/{$cemetery->slug}")
        ->assertStatus(301);
}
```

Check whether `tests/Feature/Livewire/Public/Memorial/MemorialFamilyPageTest.php` exists (`find tests/Feature/Livewire/Public/Memorial -iname "*.php"`). If it exists, add a redirect test there following the same pattern against a real fixture. If no such test file exists, add the redirect test to whichever existing Memorial family test file you find (check `tests/Feature` for one first via `grep -rl "MemorialFamilyPage" tests/Feature`), matching its existing fixture conventions — do not invent a new file for one test if an existing one already covers this route.

- [ ] **Step 7: Run tests against real Postgres, run doc gates, commit**

```bash
docker run -d --name t1u-pg -e POSTGRES_USER=testuser -e POSTGRES_PASSWORD=testpass -e POSTGRES_DB=testdb -p <free-port>:5432 postgres:18
docker run -d --name t1u-redis -p <free-port>:6379 redis:8.2-alpine
sleep 5
IMG=$(docker images --digests | grep makam-app | head -1 | awk '{print $1"@"$3}')
docker run --rm --network host --user 1000:1000 \
  -e APP_ENV=testing -e APP_KEY=base64:$(openssl rand -base64 32) \
  -e DB_CONNECTION=pgsql -e DB_HOST=127.0.0.1 -e DB_PORT=<port> -e DB_DATABASE=testdb -e DB_USERNAME=testuser -e DB_PASSWORD=testpass \
  -e REDIS_HOST=127.0.0.1 -e REDIS_PORT=<port> \
  -v "$(pwd)":/var/www/html -w /var/www/html \
  "$IMG" php -d memory_limit=512M vendor/bin/phpunit tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php
# repeat targeting the memorial test file you found/created in Step 6
docker rm -f t1u-pg t1u-redis
bash ci/verify-docs.sh
git add routes/web.php docs/product/screen-inventory.md docs/domain/traceability-matrix.md tests/Feature/Livewire/Public/Directory/CemeteryDirectoryIndexRouteTest.php tests/Feature/Livewire/Public/Directory/CemeteryDetailRouteTest.php
# add the memorial test file path too
git commit -m "feat(routes): indonesianize /cemeteries and /memorial public paths with redirects"
```

---

### Task 2: Admin panel Filament slugs

**Files:**
- Modify: 24 Resource files under `app/Filament/Admin/Resources/` (add or change `protected static ?string $slug`)
- Modify: 10 Page files under `app/Filament/Admin/Pages/` and `app/Filament/Admin/Resources/SiteSettings/Pages/EditSiteSettings.php` (change existing `protected static ?string $slug`)
- Modify: `routes/web.php` (3 plain `Route::get`/`Route::post` entries under `/admin/finance/exports`, `/admin/payments/manual-verifications/{paymentVerification}/verify`, `/admin/payments/reversals/{reversalType}` — these are NOT Filament resources, confirmed via direct grep; they're registered directly in `routes/web.php` alongside the public routes but conceptually belong to the admin surface, so they're batched into this task)
- Test files listed per-slug below

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks. Independent of Task 1 and Task 3 (disjoint files — confirmed no overlap with Task 1's `routes/web.php` lines or Task 3's `app/Filament/Vendor/` files).

- [ ] **Step 1: Confirm each file's current slug state**

Most Resources rely on Filament's slug auto-derivation and have NO `$slug` property today (confirmed this session via direct grep across all 24 `*Resource.php` files) — for these, ADD the property. All Page files already have an explicit `protected static ?string $slug = '...'` (confirmed this session) — for these, CHANGE the existing value. `ProductResource.php` and `SiteSettings/Pages/EditSiteSettings.php` already have explicit slugs too — change their values.

- [ ] **Step 2: Add/change the slug on each Resource file**

For each Resource below with no existing `$slug`, add the property near the top of the class body (immediately after the `protected static ?string $navigationIcon` or similar existing static property — match this codebase's existing property ordering convention, check 2-3 files first). For `ProductResource.php`, just change the existing value.

| File | New slug |
|---|---|
| `app/Filament/Admin/Resources/Agreements/AgreementsResource.php` | `persetujuan` |
| `app/Filament/Admin/Resources/AuditEvents/AuditEventsResource.php` | `log-audit` |
| `app/Filament/Admin/Resources/BookingOrders/BookingOrderResource.php` | `pesanan-pemakaman` |
| `app/Filament/Admin/Resources/CarePlans/CarePlansResource.php` | `rencana-perawatan` |
| `app/Filament/Admin/Resources/CemeteryResource.php` | `pemakaman` |
| `app/Filament/Admin/Resources/CemeteryVisitationPolicies/CemeteryVisitationPolicyResource.php` | `kebijakan-kunjungan-pemakaman` |
| `app/Filament/Admin/Resources/Certificates/CertificatesResource.php` | `sertifikat` |
| `app/Filament/Admin/Resources/FaqArticles/FaqArticleResource.php` | `artikel-faq` |
| `app/Filament/Admin/Resources/GravePlots/GravePlotsResource.php` | `petak-makam` |
| `app/Filament/Admin/Resources/LaunchCities/LaunchCityResource.php` | `kota-peluncuran` |
| `app/Filament/Admin/Resources/MarketplaceOrders/MarketplaceOrderResource.php` | `pesanan-marketplace` |
| `app/Filament/Admin/Resources/MemorialProfiles/MemorialProfileResource.php` | `profil-kenangan` |
| `app/Filament/Admin/Resources/ModerationCases/ModerationCaseResource.php` | `kasus-moderasi` |
| `app/Filament/Admin/Resources/PreNeedCases/PreNeedCaseResource.php` | `kasus-preneed` |
| `app/Filament/Admin/Resources/ProductResource/ProductResource.php` | `produk` (change existing `'products'`) |
| `app/Filament/Admin/Resources/Reconciliations/ReconciliationsResource.php` | `rekonsiliasi` |
| `app/Filament/Admin/Resources/RenewalOrders/RenewalOrderResource.php` | `pesanan-perpanjangan` |
| `app/Filament/Admin/Resources/ServiceDefinitionResource.php` | `definisi-layanan` |
| `app/Filament/Admin/Resources/ServicePackages/ServicePackageResource.php` | `paket-layanan` |
| `app/Filament/Admin/Resources/SiteSettings/SiteSettingsResource.php` (and its `Pages/EditSiteSettings.php`, change existing value) | `pengaturan-situs` |
| `app/Filament/Admin/Resources/Subscriptions/SubscriptionsResource.php` | `langganan` |
| `app/Filament/Admin/Resources/VisitationBookings/VisitationBookingsResource.php` | `pemesanan-kunjungan` |
| `app/Filament/Admin/Resources/WorkOrders/WorkOrdersResource.php` | `order-kerja` |

**Do not touch** `app/Filament/Admin/Resources/Vendors/VendorResource.php` — `vendors` is a kept loanword, out of scope.

- [ ] **Step 3: Change the slug on each Page file (values already exist, just change them)**

| File | Old (current) | New |
|---|---|---|
| `app/Filament/Admin/Pages/OrdersReport.php` | `orders-report` | `laporan-pesanan` |
| `app/Filament/Admin/Pages/PasswordReauthentication.php` | `password-reauthentication` | `verifikasi-ulang-kata-sandi` |
| `app/Filament/Admin/Pages/FinanceReports.php` | `finance-reports` | `laporan-keuangan` |
| `app/Filament/Admin/Pages/VendorPerformanceReport.php` | `vendor-performance-report` | `laporan-kinerja-vendor` |
| `app/Filament/Admin/Pages/ReceiptsReport.php` | `receipts-report` | `laporan-kwitansi` |
| `app/Filament/Admin/Pages/InAppNotifications.php` | `in-app-notifications` | `notifikasi-aplikasi` |
| `app/Filament/Admin/Pages/OutgoingPaymentsReport.php` | `outgoing-payments-report` | `laporan-pembayaran-keluar` |
| `app/Filament/Admin/Pages/RenewalPeriodReport.php` | `renewal-period-report` | `laporan-periode-perpanjangan` |

**Do not touch** `app/Filament/Admin/Pages/FeatureGateAdmin.php` (`feature-gates` slug) — internal engineering concept, out of scope per Global Constraints.

- [ ] **Step 4: Rename the 3 plain-route admin controller paths**

Read `routes/web.php` lines 520-640. Change:

```php
Route::get('/admin/finance/exports', FinanceExportController::class)
```
to
```php
Route::get('/admin/laporan-keuangan/ekspor', FinanceExportController::class)
```

```php
Route::post('/admin/payments/manual-verifications/{paymentVerification}/verify', VerifyManualPaymentController::class)
```
to
```php
Route::post('/admin/pembayaran/verifikasi-manual/{paymentVerification}/verifikasi', VerifyManualPaymentController::class)
```

```php
Route::post('/admin/payments/reversals/{reversalType}', RecordPaymentReversalController::class)
```
to
```php
Route::post('/admin/pembayaran/pembalikan/{reversalType}', RecordPaymentReversalController::class)
```

Keep each route's `->name(...)` unchanged if one exists — read the real lines first to confirm.

- [ ] **Step 5: Update literal-reference files**

For each slug below, real literal references were confirmed this session — read each file and update the old slug string to the new one:

- `audit-events` → `log-audit`: `tests/browser/e2e-admin-vendor.spec.ts`
- `faq-articles` → `artikel-faq`: `tests/Feature/Filament/Admin/Faq/FaqArticleAuthorizationCharacterizationTest.php`, `tests/Feature/Filament/Admin/Faq/FaqArticleListPageTest.php`
- `reconciliations` → `rekonsiliasi`: `tests/browser/e2e-admin-vendor.spec.ts`, `tests/Feature/FinancialLedger/ResolveReconciliationExceptionTest.php`, `tests/Feature/Filament/ReconciliationAdminTest.php`, `tests/Feature/FinancialLedger/RunReconciliationTest.php`
- `renewal-orders` → `pesanan-perpanjangan`: `tests/browser/e2e-renewal-external.spec.ts`, `tests/Feature/Filament/MarkExternalRenewalActionTest.php`
- `service-definitions` → `definisi-layanan`: `tests/Feature/Filament/Admin/MasterDataNavigationTest.php`
- `site-settings` → `pengaturan-situs`: `tests/Feature/SiteSettings/EditSiteSettingsSmokeTest.php`
- `cemeteries` → `pemakaman` (admin, distinct from the public route Task 1 already handled): re-run `grep -rln "admin/cemeteries" tests/` to find its real reference(s) and update.

For every OTHER slug in Steps 2-3's tables not listed above, run `grep -rln "<old-slug>" tests/ docs/ app/ --include="*.php" --include="*.ts" --include="*.md" | grep -v "app/Filament/Admin"` to confirm zero literal references exist (matches this session's finding that most admin resources are accessed only via `Resource::getUrl()`, which is rename-safe). If a hit turns up that wasn't in this list, update it — the research this plan is based on may not have caught every file.

- [ ] **Step 6: Run affected tests against real Postgres, run doc gates, commit**

Run each Feature test file listed in Step 5 individually against real Postgres (same docker recipe as Task 1 Step 7). For `tests/browser/e2e-admin-vendor.spec.ts` and `tests/browser/e2e-renewal-external.spec.ts` (Playwright, not PHPUnit) — read this plan's own established browser-test-running convention from an earlier session plan if one exists in this worktree's git history (`git log --all --oneline | xargs -I{} git show {} -- 'docs/superpowers/plans/*e2e*'` or similar), or if none is directly reusable, note in your report that these 2 browser test files' assertions were updated to the new slug strings but not executed on this host (this host's Playwright execution capability should be confirmed before claiming a run — do not fabricate a browser-test run that didn't happen).

```bash
bash ci/verify-docs.sh
git add app/Filament/Admin database/ routes/web.php tests/
git commit -m "feat(routes): indonesianize admin panel Filament resource/page slugs"
```

---

### Task 3: Vendor panel Filament slugs

**Files:**
- Modify: `app/Filament/Vendor/Resources/ServiceAreas/ServiceAreaResource.php`, `app/Filament/Vendor/Resources/VendorAvailabilities/VendorAvailabilityResource.php`, `app/Filament/Vendor/Resources/VendorListings/VendorListingResource.php`, `app/Filament/Vendor/Resources/VendorOrders/VendorOrderResource.php`, `app/Filament/Vendor/Resources/WorkOrders/WorkOrdersResource.php`
- Modify: the vendor "evidence", "payouts", and "profile" Page classes (confirm exact file paths in Step 1 — not verified against real files yet)
- Test: `tests/browser/e2e-admin-vendor.spec.ts`

**Interfaces:**
- Consumes: nothing from other tasks.
- Produces: nothing consumed by later tasks. Independent of Tasks 1-2 (disjoint files under `app/Filament/Vendor/`, confirmed no overlap).

- [ ] **Step 1: Confirm the real file paths and current slug values**

Run `find app/Filament/Vendor -iname "*.php" | grep -v "/Concerns/\|/Support/"` to get the full real file list (5 Resources already confirmed above this session — `ServiceAreaResource.php`, `VendorAvailabilityResource.php`, `VendorListingResource.php`, `VendorOrderResource.php`, `WorkOrdersResource.php` — plus Page classes for "evidence upload list", "payouts", and "profile" that were NOT independently verified by file path this session; find them now via `grep -rln "class.*extends Page\|class.*Page$" app/Filament/Vendor` and confirm each one's current `$slug` value or auto-derivation state the same way Task 2 Step 1 did for the admin panel).

- [ ] **Step 2: Add/change slugs**

| Resource/Page (confirm real path in Step 1) | New slug |
|---|---|
| `VendorOrderResource.php` | `pesanan` |
| Evidence upload Page (confirm path) | `bukti` |
| Transaction history Page (confirm path) | `transaksi` |
| Payouts Page (confirm path) | `pencairan` |
| `VendorListingResource.php` | `produk` |
| `ServiceAreaResource.php` | `area-layanan` |
| `VendorAvailabilityResource.php` | `kalender` |
| Profile Page (confirm path) | `profil` |
| `WorkOrdersResource.php` | `order-kerja` |

Follow the same add-vs-change pattern Task 2 established: if the class already has an explicit `$slug`, change its value; if it relies on auto-derivation, add the property.

- [ ] **Step 3: Update `tests/browser/e2e-admin-vendor.spec.ts`**

This file has the heaviest literal-reference concentration of the whole plan (confirmed this session: `orders` 7 refs, `evidence` 2, `transactions` 6, `payouts` 3, `products` 2, `service-areas` 1, `calendar` 2, `profile` 2 — re-verify exact counts via `grep -c "vendor/orders\|vendor/evidence\|vendor/transactions\|vendor/payouts\|vendor/products\|vendor/service-areas\|vendor/calendar\|vendor/profile" tests/browser/e2e-admin-vendor.spec.ts` before editing, since Task 2 already touched some `admin/...` paths in this same file and you need the current, not stale, count). Update every `page.goto('/vendor/<old-slug>...')` and any assertion string containing the old slug to the new one.

- [ ] **Step 4: Run doc gates, note test-execution limits, commit**

```bash
bash ci/verify-docs.sh
```

Note in your report (per Task 2 Step 6's same discipline): whether `tests/browser/e2e-admin-vendor.spec.ts` was actually executed against a real browser on this host, or only had its literal strings updated without a run — do not claim PASS for a check that wasn't executed.

```bash
git add app/Filament/Vendor tests/browser/e2e-admin-vendor.spec.ts
git commit -m "feat(routes): indonesianize vendor panel Filament resource/page slugs"
```

---

### Task 4: Final sweep and doc gate

**Files:**
- Any file discovered in Step 1's sweep (unknown until run)

**Interfaces:**
- Consumes: the final state of Tasks 1-3 (must run last).
- Produces: nothing.

- [ ] **Step 1: Sweep for any remaining literal reference to a renamed path**

For every old path segment renamed in Tasks 1-3, run:

```bash
for old in cemeteries "memorial/{" audit-events booking-orders "care-plans" "cemetery-visitation-policies" faq-articles grave-plots "in-app-notifications" launch-cities marketplace-orders memorial-profiles moderation-cases "orders-report" "outgoing-payments-report" password-reauthentication pre-need-cases reconciliations renewal-orders "renewal-period-report" service-definitions service-packages site-settings subscriptions visitation-bookings work-orders "vendor/orders" "vendor/evidence" "vendor/transactions" "vendor/payouts" "vendor/products" "vendor/service-areas" "vendor/calendar" "vendor/profile"; do
  echo "=== $old ==="
  grep -rln "$old" --include="*.php" --include="*.md" --include="*.ts" --include="*.blade.php" . 2>/dev/null | grep -v -E "vendor/|node_modules/|\.git/"
done
```

Read every file this produces that Tasks 1-3 didn't already account for. For each real hit:

- If it's a doc describing CURRENT state (e.g. `docs/product/information-architecture.md` if it names the old route tree as current, `docs/contracts/openapi.yaml` if it documents these paths, any README) — update it.
- If it's a dated historical log entry (`docs/planning/sprint-plan.md`'s deploy-verification rows, `.kiro/specs/*/tasks.md`'s dated completion notes) — leave it untouched, per this plan's Global Constraints. List which files you deliberately left untouched and why, in your task report.
- If you are genuinely unsure which category a hit falls into, leave it and flag it explicitly in your report rather than guessing.

- [ ] **Step 2: Run the full doc gate**

```bash
bash ci/verify-docs.sh
```

Must show `RESULT: ALL DOC GATES PASS` — Gate 4 specifically checks markdown link resolution, which would catch a stale internal doc link this sweep missed.

- [ ] **Step 3: Commit**

```bash
git add -A
git commit -m "docs: final sweep for stale references after URL indonesianization"
```

## Verification

| Task | Done when |
|---|---|
| 1 | `/pemakaman`, `/pemakaman/{slug}`, `/kenangan/{profileId}` serve the real pages; `/cemeteries`, `/cemeteries/{slug}`, `/memorial/{profileId}` return real 301s to the new paths; redirect tests pass against real Postgres; the 2 current-state docs updated |
| 2 | All 24 admin Resources + 8 admin Pages + `SiteSettings`'s page + 3 plain admin routes carry their new Indonesian slugs; `feature-gates` and `vendors` confirmed untouched; the 7 known-literal-reference test files updated and passing; doc gates pass |
| 3 | All ~9 vendor Resources/Pages carry their new Indonesian slugs; `tests/browser/e2e-admin-vendor.spec.ts` fully updated; doc gates pass |
| 4 | Full-repo sweep finds no stale reference to a renamed path in any current-state doc; historical log entries deliberately and explicitly left untouched; `ci/verify-docs.sh` passes clean |

Final whole-branch review checks: does Task 1's redirect mechanism actually work end-to-end (test proof, not just code review)? Do all 4 tasks' `git diff --stat` show zero accidental file overlap (each touches a disjoint file set)? Does any task's diff accidentally touch a route NAME instead of just the path string? Is every doc-update decision (touched vs. deliberately-left-as-historical) defensible on inspection?

## Execution

Execute via `superpowers:subagent-driven-development` — fresh implementer subagent per task, task-scoped review, one final whole-branch review before PR. Standing execution mode for this session; do not ask the user to choose between subagent-driven and inline execution. Tasks 1-3 are file-independent and could be dispatched in any order; Task 4 must run last since it sweeps for what the first 3 missed.
