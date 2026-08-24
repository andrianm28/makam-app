# URL Path Indonesianization — Design Spec

## Context

Today `makam.co.id`'s URL paths are an inconsistent mix of Indonesian and
English. Of the app's public routes (`routes/web.php`), 19 segments are
already Indonesian (`/perpanjangan`, `/pemesanan-makam`, `/akun`, `/masuk`,
`/daftar`, `/keluar`, `/kunjungan`, `/langganan`, `/riwayat-perawatan`,
`/sertifikat`, `/privasi`, `/syarat-ketentuan`, `/bantuan`, `/pembayaran/*`,
`/marketplace/keranjang`, `/marketplace/pesanan`, `/marketplace/produk`) and
6 are English (`/admin`, `/marketplace`, `/faq`, `/cemeteries`, `/memorial`,
`/reset-password`/`/lupa-password`). The Filament admin and vendor panels
layer another ~47 English resource/page slugs on top of `/admin` and
`/vendor` (`booking-orders`, `renewal-orders`, `moderation-cases`, `orders`,
`evidence`, `transactions`, etc.).

The user's decision, made through a brainstorming pass this session: bring
the whole system to consistent Indonesian, since this is a domestic-only,
Indonesian-language death-services product — Indonesian paths fit the
product's register and audience better than English, and naming should
match how a formal Indonesian institutional site (closer to how gov.id-style
sites read) would be built. A small set of words are kept as naturalized
loanwords rather than translated, because translating them would read as
*more* foreign to an Indonesian audience, not less: `admin`, `marketplace`,
`faq`, `password` (hence `reset-password`/`lupa-password` stay unchanged),
`vendor`, and the purely internal engineering concept `feature-gates`.

The site is live and real. `makam.co.id`'s public pages are presumably
indexed and may be bookmarked or linked externally, so the 2 public-route
renames need permanent redirects from the old paths. The admin/vendor
panels are authenticated-staff-only, never search-indexed, and navigated
via in-app links rather than bookmarked raw URLs — redirects there add
real work (each Filament resource can have list/create/edit/view
sub-routes) for negligible benefit, so those get a clean cutover instead.

## Problem Statement

Bring every URL path in the live application to consistent Bahasa
Indonesia, except for the handful of genuinely naturalized loanwords named
above, without breaking any existing public bookmark, external link, or
search-engine ranking, and without silently touching functionality this
plan doesn't own (payment-provider callback URLs, health-check endpoints).

## Solution

Rename the in-scope path segments to their Indonesian equivalents. For the
2 public-facing renames, add explicit `Route::permanentRedirect()` entries
from the old paths so external links/bookmarks/SEO keep working. For the
~47 admin/vendor Filament slugs, change each resource's `getSlug()` (or add
one where none exists) with no redirect — internal panel navigation updates
itself the moment the change ships, since Filament generates every internal
link through `Resource::getUrl()`.

Update every literal hardcoded reference to a changed path (Blade links,
Playwright `page.goto()` calls, Feature test URLs, docs that cite the URL
as fact) to the new path. Route **names** (`->name('...')`, already English
internally, e.g. `perpanjangan.index`) do not change — only the path
strings — so the overwhelming majority of internal `route('name')` callers
need zero changes.

## Scope

### In scope — public routes (`routes/web.php`)

| Old | New | Redirect? |
|---|---|---|
| `/cemeteries`, `/cemeteries/{cemeterySlug}` | `/pemakaman`, `/pemakaman/{cemeterySlug}` | Yes, permanent |
| `/memorial/{profileId}` | `/kenangan/{profileId}` | Yes, permanent |

`/m/{token}` (the QR-scan shortlink) is a compact token prefix, not a
translatable word — unchanged. Every other public route (all 19 already
Indonesian, plus `/admin`, `/marketplace`, `/faq`, `/reset-password`,
`/lupa-password`, `/preneed`, `/health/*`, `/internal/documents/...`) is
explicitly **unchanged** by this plan.

### Out of scope, deliberately

- `/pembayaran/kembali`, `/pembayaran/batal` — already Indonesian, no
  rename needed under this plan's direction (this removes the earlier
  concern about SumoPod-registered callback URLs entirely — nothing to
  coordinate, since the path text isn't changing).
- `/health/live`, `/health/ready` — infra/ops convention, may be consumed
  by external monitoring by string convention; never touched.
- `/internal/documents/{document}/download/{token}` — signed-URL download
  engine, consumed programmatically, not a page a human visits directly.
- The support email `bantuan@makam.co.id` (`app/Support/ContactInfo.php`)
  — a real, live business email address is a separate, higher-stakes
  decision than a URL path and is explicitly not touched by this plan.

### In scope — Admin panel (`app/Filament/Admin/`)

All resource/page slugs get an Indonesian `getSlug()` (adding one where a
class currently relies on Filament's auto-derived English default), **no
redirects**:

`agreements`→`persetujuan`, `audit-events`→`log-audit`,
`booking-orders`→`pesanan-pemakaman`, `care-plans`→`rencana-perawatan`,
`cemeteries`→`pemakaman`, `cemetery-visitation-policies`→
`kebijakan-kunjungan-pemakaman`, `certificates`→`sertifikat`,
`faq-articles`→`artikel-faq`, `finance-reports`→`laporan-keuangan`,
`finance/exports`→`laporan-keuangan/ekspor`, `grave-plots`→`petak-makam`,
`in-app-notifications`→`notifikasi-aplikasi`,
`launch-cities`→`kota-peluncuran`, `login`→`masuk`, `logout`→`keluar`,
`marketplace-orders`→`pesanan-marketplace`,
`memorial-profiles`→`profil-kenangan`, `moderation-cases`→`kasus-moderasi`,
`orders-report`→`laporan-pesanan`,
`outgoing-payments-report`→`laporan-pembayaran-keluar`,
`password-reauthentication`→`verifikasi-ulang-kata-sandi`,
`payments/manual-verifications/{id}/verify`→
`pembayaran/verifikasi-manual/{id}/verifikasi`,
`payments/reversals/{type}`→`pembayaran/pembalikan/{type}`,
`pre-need-cases`→`kasus-preneed`, `products`→`produk`,
`receipts-report`→`laporan-kwitansi`, `reconciliations`→`rekonsiliasi`,
`renewal-orders`→`pesanan-perpanjangan`,
`renewal-period-report`→`laporan-periode-perpanjangan`,
`service-definitions`→`definisi-layanan`,
`service-packages`→`paket-layanan`, `site-settings`→`pengaturan-situs`,
`subscriptions`→`langganan`,
`vendor-performance-report`→`laporan-kinerja-vendor`,
`visitation-bookings`→`pemesanan-kunjungan`, `work-orders`→`order-kerja`.

Kept unchanged (naturalized loanword / pure internal concept):
`feature-gates`, `vendors`.

### In scope — Vendor panel (`app/Filament/Vendor/`)

`login`→`masuk`, `logout`→`keluar`, `orders`→`pesanan`, `evidence`→`bukti`,
`transactions`→`transaksi`, `payouts`→`pencairan`, `products`→`produk`,
`service-areas`→`area-layanan`, `calendar`→`kalender`, `profile`→`profil`,
`work-orders`→`order-kerja`.

## Implementation Decisions

- **Mechanism for public routes:** explicit `Route::permanentRedirect('/old', '/new')` calls, one per old path, registered in `routes/web.php` alongside the renamed route. No redirect-table abstraction — 2 explicit entries is simpler and matches this codebase's established YAGNI discipline (see `App\Platform\Outbox\Outbox`'s own doc block reasoning against premature abstraction for a finite, known list).
- **Mechanism for Filament panels:** each Resource/Page class gets (or updates) a `protected static ?string $slug` / `getSlug()` override to the Indonesian value. No redirect registration — internal panel navigation self-heals via `Resource::getUrl()`.
- **Route names stay English and unchanged.** Only the path string changes. This is the reason the blast radius is small: any caller using `route('name')` needs zero changes.
- **Literal-string references get updated, not left stale.** Every hardcoded path string found in Blade views, Livewire `redirect()` calls that pass a raw string instead of `route()`, Feature/browser test assertions and `page.goto()`/`->get()` calls, and any doc (`docs/testing/release-gates.md`, `docs/contracts/notification-matrix.md`, ADRs) that cites a URL as fact, gets updated to the new path in the same task that renames it.
- **Testing convention:** for the 2 redirected public routes, add a test asserting the old path issues a `301` to the new path and the new path serves the real page. For the Filament slugs, no redirect test is needed (deliberately no redirect); existing Feature/Filament tests that assert on the resource's URL get their literal path updated.

## Testing Decisions

- Public-route rename: extend/add a Feature test per renamed route asserting (a) the new path renders the expected page, (b) the old path returns a `301` to the new path.
- Filament slug renames: update existing Feature/Filament resource tests' literal path assertions in the same commit as the slug change; `tests/browser/e2e-admin-vendor.spec.ts`'s hardcoded `page.goto()` calls for the vendor panel (the highest-literal-reference-count file found in research — `orders`, `evidence`, `transactions`, `payouts` slugs all hit it) get updated together, since they're the same file.
- No new test infrastructure needed — this plan extends existing Feature/browser test files, following this codebase's established convention (confirmed via `tests/Feature/Filament/**` and `tests/browser/e2e-admin-vendor.spec.ts` precedent from earlier this session).
- Doc-gate: `bash ci/verify-docs.sh` must pass on every task (checks markdown link resolution among other things — any doc README/spec that links to an old path by its literal URL would be caught).

## Out of Scope

- `/pembayaran/kembali`, `/pembayaran/batal` (already Indonesian, no rename).
- `bantuan@makam.co.id` email address.
- `/health/*`, `/internal/documents/...` (infra/programmatic, not human-navigated pages).
- Any UI copy/label translation — this plan is URL paths only; Blade/Livewire visible text is already Indonesian throughout the app and untouched here.
- Database content, model names, PHP namespaces, event names (`renewal.submitted.v1` etc.) — internal code stays English per this codebase's existing convention; only the externally-visible URL surface changes.

## Further Notes

- Redirect asymmetry is deliberate, not an oversight: public routes get redirects (real external users, SEO), Filament panel routes don't (internal-only, no external indexing, in-app navigation self-heals).
- `feature-gates` and `vendors`/`vendor` are flagged loanword judgment calls, not silent decisions — the user reviewed and approved this reasoning during brainstorming.
- Total real touch surface is smaller than the ~53 total slug renames suggests: the large majority of Filament resource references go through `Resource::getUrl()` (rename-safe), and research found real literal-reference hits concentrated in roughly 15 of the ~53 renamed slugs, mostly in `tests/browser/e2e-admin-vendor.spec.ts` and a handful of Feature tests/docs.
