# P2 — Complete Admin Data Management Design

**Date:** 15 Aug 2026
**Status:** Draft (approved by user 15 Aug 2026, pending written review)
**Scope:** Complete admin DATA management per roadmap P2 — entity CRUD gaps (package versions/items, product variants, vendors) plus site/support config (site settings, feature-gate management, launch cities).
**Depends on:** P1 (admin order management, merged `b075fc3`, deployed to dev).

## 1. Goal

Every data entity and every site/support configuration has an admin surface — list, view, edit, audit — gated by the 4 back-office roles. Site settings become DB-backed with env fallback (secrets stay env-only). Feature gates become operable from the panel with activation evidence + recent re-authentication. Launch cities become admin-managed while the five canonical cities remain the seeded baseline.

## 2. In scope

1. **ServicePackageResource** — package versions (`ServicePackageVersion`) + version items (`ServicePackageItem`) management; the existing `PackagesRelationManager` on CemeteryResource stays (package header only).
2. **ProductResource extension** — `VariantsRelationManager` (product variant CRUD).
3. **VendorResource** — vendor CRUD + relation managers: members (`VendorUser`), listings (`VendorListing`), availability (`VendorAvailability`).
4. **SiteSettingsResource** — single-record settings page: service hours, support contact, marketplace badan-usaha ref, payment merchant/badan-usaha ref; `SiteSetting` model + `SettingsService` (config → env → DB → default fallback).
5. **FeatureGateAdmin page** — all gates with state/evidence/effective_at; open/close actions via the existing `GateActivationRecorder` with required activation evidence + recent re-auth (AGENTS.md: gate actions are recent-reauth actions).
6. **LaunchCityResource** — launch cities CRUD + activate + reorder; `LaunchCity` model + seed of the five canonical cities; public flow (wizard city list, `BookingDraft` city validation) reads from the table.

## 3. Out of scope

- Grave/plot inventory (P3), memorial/QR/visitation (P4), certificates/pre-need/operator dashboard (P5), FIN-DEC decisions (P6).
- Vendor-side panel changes (vendor panel stays vendor-owned).
- Secrets management (env-only; the settings surface holds non-secret provisioning identifiers only).
- Notification channel configuration (P5/P6).
- New event names or outbox records — settings/gate/city writes are audited (`Audit::wrap`, `AuditSource::Panel`) but emit no domain events this phase.

## 4. Architecture

All resources follow the established Filament 5 pattern (Resource + `Pages/` + `Schemas/` + `Tables/` + `RelationManagers/`), under `MasterDataAdminAuthorizerContract` access gate + `auditRoleFor` (P1 precedent), every write in `Audit::wrap` with `AuditSource::Panel`.

### 4.1 ServicePackageResource

- Table: package name, cemetery, class label, availability status, active, sort order; filter by cemetery.
- View: `VersionsRelationManager` (version number, status published/draft/superseded, price minor, effective at) + `VersionItemsRelationManager` (item lines per version: service definition, quantity, unit price).
- Write invariants: publication keeps exactly one current published version per package; superseded versions immutable — route all writes through the existing `ServicePackageVersion` domain rules (verify the publication seam exists; if the domain has no publication action, the resource enforces the invariant with audit + the same honest error surface).
- Delete: version delete blocked once superseded/published; package delete blocked while versions exist.

### 4.2 ProductResource extension

- `VariantsRelationManager`: variant name/sku/price/is_active CRUD under a product, audit-wrapped.

### 4.3 VendorResource

- Table: name, active, listings count; search.
- Create/edit: name + is_active (the model's only writable fields).
- Relation managers: `MembersRelationManager` (VendorUser add/revoke — vendor panel logins), `ListingsRelationManager` (VendorListing CRUD: price, availability mode, stock, lead time, cancellation policy, evidence requirement, active), `AvailabilityRelationManager` (VendorAvailability).
- Delete: blocked while listings/members exist (restrict).

### 4.4 SiteSettingsResource

- `SiteSetting` model: `key` (string, unique), `value` (text), `updated_by_ref`, `updated_at`; no `created_at` (key-value rows are upserted).
- `SettingsService::setting(string $key, mixed $default = null): mixed` — resolution order: `config("site.$key")` → `env($key)` → DB row → `$default`. Secrets remain env-only; the provisioning refs (payment merchant/badan-usaha, marketplace badan-usaha) are non-secret identifiers per FIN-DEC and may live in DB.
- Resource: single-record edit page (one row in the table), sections: Jam layanan, Kontak dukungan, Marketplace badan usaha ref, Payment merchant ref + badan usaha ref. Save = validate per key + upsert + `Audit::wrap` (action `SITE_SETTING_UPDATED`, `auditRoleFor`).
- Consumers switched to `SettingsService`: payment guard condition 6 (`config('payment.merchant_ref')` → settings with env fallback), marketplace `badan_usaha_ref`. FAQ claims about service hours read from settings (verify the FAQ content seam; document it if the seam is deferred).
- Keys (canonical, in code as constants): `service_hours`, `support_phone`, `support_whatsapp`, `support_email`, `marketplace_badan_usaha_ref`, `payment_merchant_ref`, `payment_badan_usaha_ref`.

### 4.5 FeatureGateAdmin page

- Table of all `FeatureGate` rows: gate_id, capability, type, owner, state, evidence_reference, effective_at.
- Actions per gate: open/close with a required activation-evidence textarea, routed through `GateActivationRecorder` (existing seam: evidence + audit + effective_at). Gate actions under `ReauthenticationGuard` (recent re-auth) and an **admin-only** role gate (view for the 4 back-office roles, transitions admin-only).
- Failure surface: recorder exceptions surface as notifications; no state change on failure; re-auth lapse redirects to the MFA challenge (P1 pattern).

### 4.6 LaunchCityResource

- `LaunchCity` model: `code` (unique), `label`, `is_active`, `sort_order`, timestamps.
- Seed migration: the five canonical cities (Jakarta, Bogor, Depok, Tangerang, Bekasi) — AGENTS.md baseline preserved as seed.
- Resource: table (code, label, active, sort order), CRUD, activate/deactivate, reorder (swap pattern — no Filament `reorderable()`).
- Public wiring: `CemeteryPublicQuery::launchCities()` reads active `LaunchCity` rows ordered by sort_order (fallback to the canonical constants when the table is empty — seed guarantees it isn't); `BookingDraft`'s city validation asserts the code exists in `LaunchCity` (fallback: canonical constants). Delete blocked for cities referenced by any booking draft or order (restrict, honest error).
- **AGENTS.md deviation note (approved by user as product owner, 15 Aug 2026):** "Launch locations include Jakarta, Bogor, Depok, Tangerang, and Bekasi" — the five remain the seeded baseline and the launch set; admin extension beyond them is a product decision enabled by this resource and recorded here.

## 5. Data flow

Admin action → `ActorContext` from admin → `MasterDataAdminAuthorizerContract` (resource gate) + per-action role gate → domain seam (existing rules: package-version publication, gate recorder, settings validation, city validation) → `Audit::wrap` write → notification + refresh. Public flows read the new seams (settings service, launch city table) with env/constant fallbacks so behavior is unchanged until an admin value exists.

## 6. Error handling

- Domain exceptions → Filament notifications; no 500s, no partial writes (audit-wrapped).
- Gate actions fail closed on re-auth lapse → MFA challenge redirect.
- Settings saves validate per key; field errors inline.
- City delete blocked with clear copy when referenced.
- Version publication conflicts (two current published versions) refused with the domain's honest error.

## 7. Testing

- Feature: each resource — access matrix (4 roles admitted, vendor/guest denied), CRUD, audit rows, relation-manager writes.
- Domain: `SettingsService` fallback chain; gate recorder composition (evidence required, re-auth, honest failure); `LaunchCity` validation in `BookingDraft` (admin-added city passes; unknown fails) + `launchCities` ordering/active filtering.
- Invariants: single current published package version; superseded immutable.
- Browser (dev, Playwright): resource smokes; settings save reflected (service hours visible); gate open with evidence + re-auth prompt; city added → public wizard lists it; vendor + member created.

## 8. Delivery

One plan, lanes: L1 settings + gates; L2 launch cities (domain seam + resource + public wiring — lands first since `BookingDraft` validation is shared); L3 package versions/items + product variants; L4 vendor resource. Per-lane review loops, dependency-ordered merges, deploy to dev, browser UAT, whole-branch review + bounded fix wave — the P1 rhythm.
