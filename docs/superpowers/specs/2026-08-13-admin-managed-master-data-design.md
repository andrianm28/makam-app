# Admin-Managed Master Data — Design

**Date:** 13 Aug 2026
**Status:** Approved (user, 13 Aug 2026)
**Scope:** All example entities (cemeteries, products/vendors, service definitions + prices) become admin-managed via Filament CRUD. Generators remain the dev/test bootstrap.

## Goal

Give admins real CRUD control over the master-data entities (cemeteries + packages, products + vendor pricing, service definitions + prices) through the Filament admin panel — satisfying the ledgered backlog items AC10 ("admin manages cities/content/facilities/prices/capabilities") and AC8 ("admin editor UI for the catalogue"). The deterministic generators (`App\Support\ExampleData\*`) stay as the dev/test bootstrap so CI and fresh environments remain deterministic; the admin becomes the source of truth for real data.

## Architecture

Three new Filament admin resources under `app/Filament/Admin/Resources/`, following the existing `FaqArticles/` structure exactly (Resource + Schemas + Pages + Tables subdirectories):

| Resource | Manages | Key fields |
|----------|---------|------------|
| `CemeteryResource` | `cemeteries` + `cemetery_packages` (relation manager) | name, slug, city, type, address, coordinates, facilities, price min/max/source, publication status |
| `ProductResource` | `products` (vendor name, base price, photo) | product code (read-only — canonical catalog), vendor name, base price, photo path |
| `ServiceDefinitionResource` | `service_definitions` + `price_versions` (relation manager) | code (read-only), fulfillment owner, requires schedule/manual, prices |

**Data ownership model:** the generators remain the dev/test bootstrap (deterministic, CI-green, fresh environments usable immediately); the admin is the source of truth for real data. Admins can create/edit/delete/publish rows via Filament; bootstrap rows are editable like any other.

## Data flow & invariants

- **Bootstrap → admin handoff:** fresh environments get generator-seeded rows via migrations (unchanged); admin edits go through the models with money/pricing writes audited and — where the domain demands — routed through existing domain Actions (e.g. price_versions via `RecordServiceDefinitionPriceVersion`) to preserve append-only versioning.
- **Canonical fields are read-only in the admin:** product codes, service codes, cemetery slugs, city/type closed lists — no inventing new codes/labels (AGENTS.md: "do not invent alternate labels").
- **Publication status:** cemeteries use the existing `CemeteryPublicationStatus` closed list; publish/unpublish respects `scopePublished` (a draft cemetery's records stay unreachable).
- **Slug uniqueness** enforced by the DB unique index, surfaced cleanly in the resource.
- **FK delete protection:** deleting a cemetery with grave records is blocked by the RESTRICT FK; the resource surfaces an honest error, never a 500.
- **Version semantics:** product base-price edits increment `price_version` (the migration's `price_version => 2` semantics hold for admin edits too).
- **Error handling:** FK violations, unique collisions, version conflicts surface as field-keyed Filament notifications.

## Authorization & audit

- Each resource's `canAccess()` uses the four back-office roles (admin/restricted_admin/operator/finance) via `IdentityAccess` — same pattern as `FinanceReports::canAccess()`.
- Every create/update/delete records an audit row via `Audit::record()` (the Audit platform's `record()`/`wrap()` seam).

## Testing

- Feature tests per resource (FAQ resource test pattern): role-gated access (four roles yes, bare customer no — regression-proofing the L9 panel-gate); CRUD happy paths; audited-write assertions; canonical-field read-only; FK-delete and unique-collision errors.
- Domain-invariant tests: publish/unpublish respects `scopePublished`; price edit increments `price_version`; price_versions route through the append-only Action; slug/code immutability.
- Determinism preserved: bootstrap generators + full suite stay green (SQLite + PG18); no test depends on admin-created state.
- Browser coverage via HTTP feature tests (`withoutVite()`), consistent with existing Filament test approach.

## Risks

1. Filament 5 API specifics — the FaqArticles resource is the ground-truth pattern.
2. Relation managers (packages, price_versions) are fiddly; bounded scope (list + inline create/edit).
3. Audit integration — every write records an audit row without double-recording.
4. Admin writes must route through domain Actions where they exist (append-only price_versions).
5. Scope size (~20+ files) — the plan decomposes into per-resource tasks with reviews.

## Out of scope

- Canonical catalog content (FAQ articles/categories, feature gates, notification templates, marketplace product codes, service codes) — unchanged.
- The generators' determinism contract — unchanged (they remain the bootstrap).
- Vendor portal / operator panel — unchanged.
