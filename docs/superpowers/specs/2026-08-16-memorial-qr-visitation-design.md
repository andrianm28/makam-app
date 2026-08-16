# P4 — Memorial + QR + Visitation Booking Design

**Date:** 16 Aug 2026
**Status:** Draft (approved by user 16 Aug 2026, pending written review)
**Scope:** The memorial+QR module (privacy-gated, G-MEM-01) and the visitation-booking module (per-cemetery bookable/info-only modes) with full public surfaces, per the roadmap P4 ("Memorial + QR + Visitation booking (parallel-safe)") and the kiro specs `memorial-and-qr` (AC1–AC8) + `visitation-booking` (AC1–AC7).
**Depends on:** P3 (plot inventory + reservation — the grave-record/plot foundation the memorial links to and visitation navigates).

## 1. Goal

Visitors can book a cemetery visitation (mode-aware, capacity-safe, duplicate-proof) and resolve a memorial QR token to a privacy-controlled projection (private-by-default, consent-gated editors, moderated content, opaque revocable tokens). Both modules obey the gated-module discipline: G-MEM-01 closed → the public memorial surface is honest-fail-closed; the family/admin/moderation surfaces are fully built and tested.

## 2. In scope

1. **Visitation domain**: `CemeteryVisitationPolicy` (recurring weekday operating hours, daily capacity), `VisitationBlackoutDate` (with required visible reason), `VisitationBooking` (statuses requested/confirmed/cancelled/no_show, idempotency key, reference), `VisitationDateCapacity` (per-date booked-count ledger), `RequestVisitation` action (atomic capacity enforcement + duplicate-safe).
2. **Visitation surfaces**: public per-cemetery visitation page (mode-aware: information-only banner vs bookable form with blackout dates disabled + reasons); operator `VisitationBookingsResource` (per-cemetery scoping, confirm/cancel/no-show, confirmation with reference + instructions + fallback contact).
3. **Memorial domain**: `MemorialProfile` (privacy modes private/family_only/unlisted/public, default private, lifecycle separate from the grave record — AC7), `MemorialEditor` (consent evidence required — AC1), `MemorialContent` + `MemorialMedia` (moderated), `MemorialQrToken` (opaque, revocable, rotatable — AC4/AC5), `ModerationCase` + `AbuseReport` (AC6).
4. **Memorial surfaces**: public resolve route `/m/{token}` (gate-checked, uniform fail-closed response), family content management (private), admin memorial management + moderation queue + QR issuance/rotation (QR image via endroid/qr-code).
5. **Dependency**: endroid/qr-code pinned to the lockfile (pure PHP; encodes only the token URL).

## 3. Out of scope

- Operator dashboard consolidation (P5) — the visitation operator resource is focused (bookings per cemetery), not a dashboard.
- Memorial eulogy/bio authoring beyond moderated content; grief-community features beyond moderation/reporting.
- Deletion/retention scheduling (AC8 policy hooks recorded; the approved policy itself is P6/legal).
- Auto no-show detection (operator-marked this phase).
- Land-rights listing (AGENTS.md — not touched).

## 4. Architecture

### 4.1 Visitation

- `cemetery_visitation_policies`: uuid id, cemetery_id FK restrict, weekday operating-hours template (JSON columns, allowlisted keys), `daily_capacity` (int ≥ 1), timestamps. Writes audited (`CEMETERY_VISITATION_POLICY_UPDATED`), `MasterDataAdminAuthorizerContract`-gated single-record resource per cemetery.
- `visitation_blackout_dates`: policy_id FK cascade, date, `reason` (required, visitor-visible), unique (policy_id, date).
- `visitation_bookings`: uuid id, cemetery_id, policy_id, visit_date, visitor_count, contact_phone, contact_email nullable, accessibility_needs, facility_requests (JSON allowlisted), status, `idempotency_key` unique, reference, timestamps.
- `visitation_date_capacities`: policy_id, date, `booked_count`, unique (policy_id, date) — the atomic capacity ledger.
- `RequestVisitation::__invoke(Cemetery $cemetery, string $visitDate, int $visitorCount, string $contactPhone, ?string $contactEmail, ?string $accessibilityNeeds, array $facilityRequests, string $idempotencyKey, int|string $actorReference, string $actorRole = 'customer', AuditSource $auditSource = AuditSource::Api): VisitationBooking` — transaction: `lockForUpdate` the date-capacity row (upsert) → validate date not blackout (reason surfaced), weekday within operating hours, `booked_count + visitor_count <= daily_capacity` (else `VisitationCapacityExceededException`) → insert booking + increment count + audit `VISITATION_REQUESTED` + outbox `visitation.booked.v1` (new catalogued event). Idempotency: unique key; duplicate → incumbent returned (narrow classifier).
- Operator resource: per-cemetery scope (existing cemetery-scope assignment seam), status transitions (confirm/cancel/no-show) audited, confirmation payload includes reference + instructions + fallback contact.

### 4.2 Memorial

- `memorial_profiles`: uuid id, `grave_record_id` FK restrict (**the only GraveRegistry link — AC7**), `privacy_mode` (default private), published_at/unpublished_at, timestamps. Nothing copied from the grave record.
- `memorial_editors`: profile_id, actor_id, `consent_evidence_ref` (required — document-vault reference; grant without → refused), granted_at, revoked_at.
- `memorial_contents`/`memorial_media`: profile_id, body/storage_ref, `moderation_state` (pending/approved/rejected/hidden), timestamps. Media via the document vault (quarantine → scan → approved).
- `memorial_qr_tokens`: profile_id, `token` (random opaque, never derived — AC4), revoked_at, rotated_at. One active token per profile (partial unique on (profile_id) WHERE revoked_at IS NULL — mutable rows, index releases on revoke).
- `moderation_cases`/`abuse_reports`: profile_id, reported content type/id, reason, status (open/resolved/dismissed).
- Actions: `CreateMemorialProfile` (private default), `GrantMemorialEditor` (consent), `PublishMemorial`/`UnpublishMemorial` (immediate — AC5), `RotateMemorialQrToken` (revoke + new — AC5), `SubmitMemorialContent`, `ModerateMemorialContent`, `ReportMemorialContent`, `ResolveMemorialQr` (gate-checked resolve).
- **Gate discipline (G-MEM-01)**: closed → `/m/{token}` renders the uniform "memorial tidak tersedia" response for unknown/revoked/closed (no enumeration); family/admin surfaces fully built; activation via `GateActivationRecorder` evidence (documented path).
- **Projection allowlist (AC3)**: the public projection renders ONLY approved content/media + the explicit profile fields (deceased name from the grave record? — NO: AC7 forbids copying; the projection's display name comes from approved memorial content or an explicit allowlisted profile field set by the family); private fields never render.
- **QR**: endroid/qr-code encodes the token URL only; the admin/family surface renders the image; the resolve page is the scan target.

## 5. Data flow

Visitation: visitor → cemetery page → policy-mode check (server-side, capability profile) → bookable form → `RequestVisitation` (capacity lock → validate → insert + count + audit + outbox) → confirmation (reference + instructions + fallback contact). Memorial: family profile → consent-gated editors → content submit → moderation → publish → QR token issuance → visitor scans `/m/{token}` → gate check → allowlist projection.

## 6. Error handling

- Capacity exceeded / blackout / closed-day → honest inline errors with reasons; no oversell (row lock).
- Duplicate submission → incumbent confirmation returned (idempotent).
- Memorial gate closed / unknown / revoked token → uniform non-revealing response.
- Consent evidence missing → grant refused; moderation refusals audited.
- All domain exceptions → notifications/inline errors, never 500s.

## 7. Testing

- Visitation: policy/blackout CRUD + audits; request happy path; **capacity no-oversell** (two-connection race on the date ledger, PG-guarded); blackout refusal with reason; operating-hours enforcement; duplicate idempotency; status transitions; operator per-cemetery scoping; public form mode-awareness + confirmation.
- Memorial: private default; consent required; publish/unpublish immediate; token opacity (generated never derived — equivalence test); rotation revokes; moderation lifecycle; report intake; projection allowlist (private fields never render); gate-closed uniform response; QR image renders the token URL.
- Browser (dev): visitation public booking → confirmation; memorial family surface + direct `/m/{token}` navigation; admin moderation + QR issuance.

## 8. Delivery

One plan, two lane-groups (visitation lanes; memorial lanes) — file-disjoint; per-lane review loops; dependency-ordered merges within each group; deploy + browser UAT + whole-branch review per the established rhythm.
