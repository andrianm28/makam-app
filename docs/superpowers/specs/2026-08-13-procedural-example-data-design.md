# Procedural Example-Data De-Hardcoding — Design

**Date:** 13 Aug 2026
**Status:** Approved (user, 13 Aug 2026)
**Scope:** Example/dummy data only. Canonical catalogs (marketplace, service, FAQ, feature gates, notification templates) stay exact per AGENTS.md.

## Goal

Remove ALL literal hardcoded example data from the codebase. The dummy fixtures — cemeteries, capability profiles, packages, backfills, grave records, vendor pricing, service operational data — become **procedurally generated** by deterministic generator classes. Canonical reference data (marketplace catalog, service catalog, FAQ, feature gates, notification templates) is spec-mandated exact data and is explicitly out of scope.

## Architecture

One namespace: `App\Support\ExampleData\` — a family of deterministic generator classes over enum vocabularies:

| Generator | Produces | Input vocabulary (algorithm, not data) |
|-----------|----------|----------------------------------------|
| `CemeteryExampleData` (existing, made procedural) | 10 cemeteries, 10 capability profiles, 7 packages, 10 price+photo backfills, 16 grave records | `LaunchCityCode` × `CemeteryType` × index |
| `VendorExampleData` (new) | 9 vendor rows (name, base price, photo path) | `ProductCode` × index |
| `ServiceOperationalExampleData` (new) | fulfillment_owner, requires_schedule, requires_manual_confirmation, first `price_versions` row for all 12 services | `ServiceCode` × rule |

All generators are pure/deterministic: same input always yields the same output. No `random()`, no `time()` in any identity or pricing rule. Migrations remain the every-environment data path (CI/deploy never run `db:seed` — verified in `.github/workflows/ci.yml`, Dockerfile, entrypoint, compose).

**Literal constants that remain (algorithm, not data):** city list (`LaunchCityCode`), type list (`CemeteryType`), product-code list, service-code list, role-assignment rules, and the Indonesian example-word pool used to compose synthetic names.

## Generation rules

### Cemeteries (10: 2 per city × 5 cities)
- Name: `"{Type} {City} {n}"` → "TPU Jakarta 1", "TPS Jakarta 2", … synthetic, never a real place.
- Slug: slugified name, deterministic.
- Address: `"Jl. Contoh Kota {city} No. {n}"` — honest placeholder.
- Coordinates: **NULL** (no fake-precision; user-approved). The backfill no longer fabricates coordinates.
- Price (min/max): deterministic formula from city+index (e.g. `3_000_000 + index * 500_000`).
- Photo path: derived from cemetery index (SVG illustration set, same as today).
- Role by position: last cemetery = `draft`; Jakarta TPS = `all-restricted`; Jakarta TPU + Depok TPU (indices 0 and 4) = package-bearers (preserving the legacy package-cemetery fixture).

### Grave records
- Deterministic count + access-mode pattern per cemetery, driven by role:
  - All-restricted cemetery → every record `LIMITED`/`CLOSED`
  - Draft cemetery → has records (negative fixture reachable)
  - Others → mostly `OPEN`, with specific records missing death-date or due-date (registry-completeness fixtures)
- Names: `"Contoh {word-pool} {n}"` — synthetic Indonesian example names.

### Vendor pricing (9 rows)
- Vendor name: word-pool composition (`{"UD","CV"}` prefix + example-word + example-word), deterministic per product index.
- Base price: deterministic hash-based formula per code.
- Photo path: derived from product code.

### Service operational data (12 services)
- `fulfillment_owner`: deterministic rule from the canonical `FulfillmentOwner::KNOWN_OWNERS` map.
- `requires_schedule` / `requires_manual_confirmation`: from the canonical service definition.
- `price_versions`: first dummy row, price from the deterministic formula.

## Test strategy

- Tests reference by **role/position**, never by generated name. Generator exposes role accessors (`roleCemetery('draft')`, `roleCemetery('all-restricted')`, `roleCemetery('package', 0)`); shared `tests/Support/CemeteryFixture` resolves them.
- Numeric shape assertions stay explicit (10 cemeteries, 9 published, 1 draft, 16 records, 9 vendors) — the fixture-design contract.
- `CemeteryExampleDataTest` extends to cover vendor + service generators (every referenced code exists; roles resolve uniquely; backfill covers every cemetery).
- Price-sensitive tests (renewal fee, quote) set their own price via `Cemetery::update()` instead of relying on a seeded literal.
- Runtime unaffected — the app reads the DB dynamically; only seeds/tests reference specific example rows.

## Migration rewiring

- `190300`, `210000`, `100010` — already shimmed to `CemeteryExampleData`; only the generator's internals change to procedural.
- `200100` (vendor pricing) — becomes a shim calling `VendorExampleData::seed()`.
- `220000` (service operational) — becomes a shim calling `ServiceOperationalExampleData::seed()`.
- No new migrations, no pipeline change. In-place-edit exception extends to `200100`/`220000` (same rows/columns/version semantics; amounts regenerate deterministically (fresh vs already-applied environments may differ — intended); suite is the lock).

## Risks

1. Tests asserting literal names/values must be found and converted to role-based or content-agnostic assertions.
2. Price-version seed feeding the renewal fee screen — if the deterministic formula changes, expected amounts change; tests must not hardcode amounts.
3. Vendor names in marketplace — product page shows vendor name; tests asserting a specific vendor become code-agnostic.
4. Determinism regression — any `random()`/`time()` introduced silently breaks the every-environment invariant; the generator consistency test guards the cross-references, and a full-suite run is the output lock.

## Out of scope (canonical, stay exact)

- Feature gates (`2026_07_26_120400`) — from `assumptions-and-gates.md`
- FAQ categories/articles (`2026_07_26_170400`) — 6 required categories per AGENTS.md
- Marketplace products/variants (`2026_07_26_180200`) — from `marketplace-catalog.md`
- Service definitions (`2026_07_26_180700`) — from `service-catalog.md`
- Notification templates (`2026_08_09_100020`) — from `notification-matrix.md`
