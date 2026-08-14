# Vendor + Listing Example-Data Bootstrap

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Make the marketplace operable in fresh environments by seeding example `vendors` and `vendor_listings` rows — the missing bootstrap that blocks the add-to-cart → checkout UAT flow (verified: `vendors`/`vendor_users`/`vendor_listings` are create-only migrations with zero rows on dev).

**Architecture:** A new `App\Support\ExampleData\VendorListingExampleData` generator (the established procedural pattern — `CemeteryExampleData`/`VendorExampleData` precedent) that deterministically produces vendors + listings, called from a new data migration (the every-environment path; CI/deploy never run `db:seed`).

## Global Constraints

- Follow the `App\Support\ExampleData\*` pattern exactly (deterministic, no random/time, honesty framing: synthetic names, "contoh" markers).
- Canonical product codes untouched; listings reference existing `products` rows by id.
- `vendors` table: `id` (uuid), `name`, `is_active` — the only required columns.
- `vendor_listings`: `vendor_id` (uuid FK), `product_id` (bigint FK), `price_minor`, `price_version` (default 1), `availability_mode` (closed list), `stock_quantity` (nullable), `production_lead_time_days` (nullable), `cancellation_policy` (nullable), `evidence_requirement` (closed list), `is_active`.
- phpstan hard gate; full suite green (2300 SQLite / 2362 PG18 baseline).
- No vendor-panel/L10 changes; no L7 payment changes.

---

### Task 1: `VendorListingExampleData` generator + unit test

**Files:**
- Create: `app/Support/ExampleData/VendorListingExampleData.php`
- Create: `tests/Unit/Support/ExampleData/VendorListingExampleDataTest.php`

**Interfaces:**
- Produces:
  - `public static function vendors(): array` — `list<array{0: string, 1: string}>` (`[name, is_active]`); synthetic names (e.g. "Toko Bunga Contoh 1".."Toko Bunga Contoh 5") — 5 vendors.
  - `public static function listings(): array` — `list<array{0: int, 1: int, 2: int, 3: string, 4: ?int, 5: ?int, 6: ?string, 7: string}>` — one per product code (9 rows): `[product_code_index, vendor_index, price_minor, availability_mode, stock_quantity, lead_time_days, cancellation_policy, evidence_requirement]` — derived deterministically from indices (price from `VendorExampleData::basePrice($code)` in minor units; availability_mode/evidence from the closed lists by index).
  - `public static function seed(): void` — creates the 5 vendors (uuid ids) + 9 listings resolving product ids from `products.code`, vendor ids from the created rows. Idempotent guard: skip if any vendor name already exists (migrations run once, but guard like the seeder precedent).

- [ ] **Step 1: Failing test** — determinism (same twice), 5 vendors / 9 listings, every listing's product code exists in `ProductCode::KNOWN_CODES`, closed-list values valid, price matches `VendorExampleData::basePrice($code) * 100`.
- [ ] **Step 2: Verify fail** — class not found.
- [ ] **Step 3: Implement** per the pattern (read `VendorExampleData.php` fully first).
- [ ] **Step 4: Verify pass** — `php artisan test --filter=VendorListingExampleDataTest`.
- [ ] **Step 5: Commit** — `feat: add vendor listing example-data generator`.

### Task 2: Seed migration + integration verification

**Files:**
- Create: `database/migrations/2026_08_14_100000_seed_vendors_and_listings.php`
- Test: extend the UAT verification (below) + `tests/Feature/Domain/Marketplace/VendorListingBootstrapTest.php`

- [ ] **Step 1: Migration** — calls `VendorListingExampleData::seed()` (the every-environment path); `down()` deletes by the synthetic names/slugs.
- [ ] **Step 2: Integration test** — after migrate, `vendors` = 5, `vendor_listings` = 9; `ProductDetail::firstActiveListing()` finds a listing for every product; the add-to-cart action works end-to-end (add → cart has item → conflict flow intact).
- [ ] **Step 3: Verify** — full suite + pint + phpstan + verify-docs.
- [ ] **Step 4: Commit** — `feat: seed vendor and listing example data so the marketplace is operable`.

### Task 3: Dev deploy + browser UAT re-run

- [ ] **Step 1:** Merge to trunk, deploy to dev (migrate applies the new seed).
- [ ] **Step 2:** Re-run the UAT browser journey (marketplace browse → add → cart → checkout) — must now complete end-to-end.
- [ ] **Step 3:** Report the UAT result honestly.
