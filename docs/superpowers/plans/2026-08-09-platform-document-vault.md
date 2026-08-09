# Platform Document Vault — Implementation Plan (Lane L1, Wave 1)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement `platform-document-vault` (`.kiro/specs/platform-document-vault/`) as a real `app/Platform/DocumentVault/**` module implementing the K6 private-file contract: quarantine-first upload, pluggable fail-closed scanner, copy-then-verify-then-delete promotion, purpose-scoped signed URLs (≤ 300 s), and append-only access audit — with no S3-compatible object storage and no always-on scanner on the combined 2/4 host.

**Architecture:** `DocumentVaultAdapter` (overview.md §5) is the single boundary. Consumers (booking Step 7, marketplace evidence, care evidence, memorial media, renewal import) reference documents by id and request purpose-scoped access; they never touch storage directly. The module is provider-neutral behind two interfaces — `ObjectStorage` and `MalwareScanner` — so the combined host runs a `LocalPrivateStorage` (filesystem under `storage/app/private/documents`, never publicly served) and a deterministic `MockScanner`, while production would swap in real object storage + a real fail-closed scanner. The state machine (`UPLOADING → QUARANTINED → SCANNING → ACCEPTED | REJECTED`) is enforced in a single promotion Action with a database-level closed-list constraint, mirroring `platform-audit`'s "reject before the query is even built" pattern.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, Filament 5, PostgreSQL 18, Pest/PHPUnit. S3-compatible client is NOT added in this lane (OQ-4 undecided); storage is behind `ObjectStorage`, dev-provided as local-filesystem — swap is config-only per ADR-0033's provider-neutrality precedent.

---

## Current state — read this before planning any change

### What is already built

- `app/Platform/DocumentVault/` contains only `.gitkeep` — the module does not exist.
- No `documents`/`document_scans`/`document_access_events`/`signed_url_grants` tables exist (verified: `grep -rn "document" database/migrations/` finds only `docs/` and the audit module's own tables).
- The discipline this module extends is already established and tested: `Audit::record()`/`Audit::wrap()` (`app/Platform/Audit/Audit.php`) is the one write API for `audit_events`; `Outbox::record()` (`app/Platform/Outbox/Outbox.php`) the one write API for `outbox_events`; `OutboxClassification` and `SensitiveActions` are the closed-list precedents for this module's state/action enums.
- `document.accessed.v1` is already in `event-catalog.md` (`:24`, "Sensitive event", classification RESTRICTED) and `OutboxQueueRouter` already routes nothing for it — this lane wires both the event name and a route.
- `docs/security/file-upload-pipeline.md` v0.4 defines the canonical state machine (`INITIATED → UPLOADED_TO_QUARANTINE → VALIDATING → SCANNING → ACCEPTED_PRIVATE | REJECTED | QUARANTINED_FOR_REVIEW | DELETED`), the scanner interface (`scan(fileReference) -> CLEAN | INFECTED | SUSPICIOUS | ERROR`), and the dev/staging scanner profile (§9: deterministic mock for dev, real scanner required for staging release verification, mock is never production evidence).

### Status / NOT TESTED

`platform-document-vault/tasks.md:30-32` is the authority: "Nothing here is implemented. **No S3-compatible object storage is provisioned** — `sprint-plan.md` OQ-4 blocks this spec, and local MinIO on the 2/4 host is forbidden. No malware scanner is chosen (OQ-7). The K6 contract is external and its actual interface has not been seen."

This lane does **not** resolve OQ-4 (provider decision) or OQ-7 (production scanner). It builds the module behind interfaces so those decisions stay config-only, and it uses the **filesystem fallback + mock scanner** that the capacity constraint explicitly permits (`file-upload-pipeline.md` §9: "development may use a deterministic mock scanner only for application-flow development").

### What the spec requires (AC → design mapping)

| AC | Requirement (abridged) | Design surface |
|---|---|---|
| 1 | Quarantine-first, no direct-to-accepted path | `UploadDocument` Action writes only to `quarantine/` prefix; accepted storage is written only by the promotion Action |
| 2 | No use/download/preview/thumb before acceptance | No read route reads `quarantine/`; all read paths check state first |
| 3 | Declared-type/content-type/size/extension validation, MIME-spoof reject | `DocumentValidator` (finfo + allowlist + extension cross-check + size cap) |
| 4 | Scanner unavailable → stays `pending`, never `accepted` (fail-closed) | Promotion requires `CLEAN` verdict; `ERROR` verdict leaves `SCANNING`/`pending`, queued retry |
| 5 | Explicit accepted transition, auditable | `PromoteDocument` emits `Audit::record()` + `document.accepted` outbox event |
| 6 | Signed URL ≤ 300 s, single purpose | `IssueSignedUrl` Action; grant row with `expires_at` (300 s max), `purpose`, consumed flag |
| 7 | No signed URL before accepted | `IssueSignedUrl` guards `state === ACCEPTED` |
| 8 | Audit actor/purpose/record/timestamp/outcome on every restricted access | Every grant issuance + every access writes `document_access_events` (append-only) |
| 9 | Role AND record relationship both required | `DocumentAccessPolicy` consults `ScopeAssignment` / record-relationship via identity; role alone insufficient |
| 10 | S3-compatible private storage with encryption; no public bucket/ACL | `ObjectStorage` interface; local fallback keeps everything under `storage/app/private`, never publicly served |
| 11 | Deletion/retention per policy, preserve audit + evidence | `RetainDocument` respects legal-hold/evidence; delete is logical (state), audit preserved |
| 12 | No files attached to email/WhatsApp — authenticated link only | Nothing in this lane sends; contract exposed to notifications lane (L2) |
| 13 | Upload progress/cancel/retry without losing parent draft | `UploadDocument` accepts a client `upload_id` resume token; Livewire wire-state on top |
| 14 | Grave-registry import routed through same pipeline | `UploadDocument` is document-kind-aware; `GRAVE_IMPORT` kind uses the same quarantine |

## NOT TESTED (this lane)

- Real S3-compatible storage end-to-end (OQ-4 undecided — interface contract-tested against the filesystem adapter only).
- Real malware scanner (OQ-7 undecided — `MockScanner` is deterministic; `file-upload-pipeline.md` §9 explicitly forbids counting mock as production evidence; a real-scanner staging verification is a later HUMAN gate).
- Upload progress/cancel UI wire-up for a consuming spec (booking Step 7 is out of scope here; the platform Action + Livewire-ready events are built, the screen is not).

## Global Constraints

- One write API per table. `UploadDocument`, `PromoteDocument`, `IssueSignedUrl`, `RecordDocumentAccess` are the ONLY classes that write `documents`/`document_scans`/`signed_url_grants`/`document_access_events`. No model `::create()`/`save()`/`update()` bypass — same rule `Audit::record()`/`Outbox::record()` enforce (`AGENTS.md` §Architecture: "Keep domain logic outside…"; `Audit.php:30-33` precedent).
- Append-only audit: `document_access_events` has no update/delete path; app DB role gets no UPDATE/DELETE grant (the `revoke-audit-mutations.sql` precedent at `app/Platform/Audit/sql/`).
- Restricted data never leaves the module: no document content in outbox payloads, audit metadata, logs, or email/WhatsApp (AC12, `AGENTS.md` §Authorization and files, `outbox-event-contract.md` rule 3). Outbox carries only the document reference + kind + state.
- Signed URLs: 300-second hard maximum for deceased documents (AC6), single-purpose, generated only post-acceptance (AC7), every issuance audited (AC8).
- Fail-closed: no positive scanner verdict ⇒ no promotion, no signed URL, no use (AC4, AC7).
- No public bucket, no public object ACL, no `storage:link`-exposed document path (AC10). Local fallback lives under `storage/app/private/` (never served by nginx or Laravel's public disk).
- No local always-on scanner and no MinIO on the combined host (`AGENTS.md` capacity constraint; `file-upload-pipeline.md` §9).
- Scanner/MIME data stays out of error trackers and Pulse/Horizon tags (`AGENTS.md` §Observability).
- Capacity: work in this lane happens in a worktree; CI/test runs staggered per the Wave 0 S4-T9 baseline (4 concurrent worktrees max).
- `SensitiveActions` grows ONLY `DOCUMENT_DELETE` (restricted-file deletion) in this lane — a financial/security-relevant change is human-gated at the merge boundary per `AGENTS.md` §Infrastructure-agent execution. Everything else this lane audits uses non-sensitive actions.

## File Structure

New files under `app/Platform/DocumentVault/`:

| File | Responsibility |
|---|---|
| `Contracts/ObjectStorage.php` | `put(prefix, key, stream, meta)`, `copy(prefixFrom,keyFrom,prefixTo,keyTo)`, `delete(prefix,key)`, `temporaryUrl(key, expiresAt, options)` |
| `Contracts/MalwareScanner.php` | `scan(reference) -> ScanVerdict (CLEAN|INFECTED|SUSPICIOUS|ERROR)` |
| `Contracts/StoragePathResolver.php` | maps document kind → prefix policy (quarantine/accepted/expired) |
| `DocumentState.php` | closed-list enum `UPLOADING|QUARANTINED|SCANNING|ACCEPTED|REJECTED|EXPIRED|DELETED` |
| `DocumentKind.php` | closed-list enum `KTP|KK|DEATH_CERTIFICATE|PAYMENT_PROOF|AGREEMENT|CERTIFICATE|VENDOR_EVIDENCE|PRODUCT_IMAGE|GRAVE_IMPORT` |
| `ScanVerdict.php` | closed-list enum `CLEAN|INFECTED|SUSPICIOUS|ERROR` |
| `DocumentValidator.php` | AC3: declared vs actual type, size cap, extension cross-check, MIME-spoof rejection |
| `Actions/UploadDocument.php` | AC1/AC13/AC14: quarantine-first write, kind-aware validation, resume token, outbox `document.uploaded` |
| `Actions/ScanDocument.php` | AC4: runs scanner, records `document_scans` row, leaves `SCANNING` on ERROR, sets verdict |
| `Actions/PromoteDocument.php` | AC1/AC5: copy-to-accepted → verify checksum → delete quarantine → state ACCEPTED → audit + outbox `document.accepted`; rejects without CLEAN |
| `Actions/IssueSignedUrl.php` | AC6/AC7/AC8/AC9: acceptance guard, purpose-scoped grant (≤300 s), policy check, audit grant |
| `Actions/RecordDocumentAccess.php` | AC8: append-only access event (actor, purpose, outcome) |
| `Actions/RetainDocument.php` | AC11: logical delete with evidence/legal-hold guard, audit, outbox `document.deleted` |
| `Adapters/LocalFilesystemObjectStorage.php` | filesystem implementation under `storage/app/private/documents` |
| `Adapters/MockScanner.php` | deterministic dev scanner (EICAR hash → INFECTED; size spike → SUSPICIOUS; else CLEAN) |
| `Policies/DocumentAccessPolicy.php` | AC9: role AND record relationship required |
| `Models/Document.php`, `Models/DocumentScan.php`, `Models/DocumentAccessEvent.php`, `Models/SignedUrlGrant.php` | Eloquent models with `$guarded = ['*']` and immutable `document_access_events` |
| `DocumentVaultServiceProvider.php` | binds `ObjectStorage` → LocalFilesystem, `MalwareScanner` → MockScanner in dev; config-driven in prod |
| `sql/revoke-document-mutations.sql` | append-only grants for `document_access_events` |
| `Jobs/ScanDocumentJob.php` | dispatches scan on the `media` queue |

Migrations (all additive, numbered `2026_08_09_*`): `create_documents_table`, `create_document_scans_table`, `create_document_access_events_table`, `create_signed_url_grants_table`. Outbox event names added to `event-catalog.md` as `document.uploaded.v1`, `document.accepted.v1`, `document.deleted.v1`; `document.accessed.v1` already exists.

---

## Task 1: `documents` + `document_scans` migrations

**Files:** `database/migrations/2026_08_09_100000_create_documents_table.php`, `..._100010_create_document_scans_table.php`

- `documents`: `id` (uuid pk), `document_kind` (closed-list enum → DB CHECK, mirror `OutboxClassification` precedent), `state` (DB CHECK closed list), `owner_type`/`owner_id` (polymorphic record reference — booking draft, grave record, order, case), `original_filename` (display only, never a storage key authority), `storage_prefix`, `storage_key` (random opaque key, `Storage::random()`-style, never client-derived), `size_bytes`, `mime_declared`, `mime_verified`, `checksum_sha256`, `client_upload_id` (resume token, AC13), `scanner_required` (bool), `retention_until` (nullable), `created_at`/`updated_at`. Partial unique index on `(client_upload_id)` where non-null (resume safety, AC13).
- `document_scans`: `id`, `document_id` (FK restrict), `scanner_name`, `scanner_engine_version`, `verdict` (DB CHECK), `evidence` (jsonb — e.g. EICAR hash, reason), `attempt`, `scanned_at`. One row per scan attempt, append-only.
- CHECK constraints on `document_kind` and `state` at the DB level (`AGENTS.md` §Database: closed-list enforcement; ServiceCatalog finding F11 precedent).
- `updated_at` maintained by Laravel; no destructive `down()`.

- [ ] **Step 1:** Write both migrations following the `2026_07_26_*` house style (doc-block rationale, `schema->create`, closed-list CHECKs, index naming `documents_{column}_index`).
- [ ] **Step 2:** Enum classes `DocumentKind`, `DocumentState`, `ScanVerdict` matching the DB CHECK lists byte-for-byte.
- [ ] **Step 3:** Tests: DB rejects unknown `document_kind`/`state`/`verdict` values; partial unique index rejects duplicate live `client_upload_id`; FK restrict prevents scan row orphaned by document delete.

---

## Task 2: `document_access_events` + `signed_url_grants` migrations

**Files:** `database/migrations/2026_08_09_100020_create_document_access_events_table.php`, `..._100030_create_signed_url_grants_table.php`

- `document_access_events` (append-only): `id`, `document_id` (FK restrict), `actor_ref`, `actor_role`, `purpose` (closed-list enum: `VIEW|DOWNLOAD|UPDATE|DELETE|GRANT`), `outcome` (`allowed|denied|failed` — mirror `AuditOutcome`), `ip_address`, `occurred_at`. No `updated_at`. No UPDATE/DELETE grant for the app role (revoke SQL applied in Task 8).
- `signed_url_grants`: `id`, `document_id`, `purpose` (single-purpose, AC6), `token` (opaque), `expires_at` (migration CHECK: `expires_at <= created_at + interval '5 minutes'` — DB-enforced 300 s max, AC6), `consumed_at` (nullable), `created_at`. One grant row per issuance; issuance is the audited event.

- [ ] **Step 1:** Write both migrations; the 5-minute cap is enforced at the DB level (CHECK on `expires_at` vs `created_at`), not only in the Action.
- [ ] **Step 2:** `DocumentAccessEvent` model with `$guarded = ['*']`, `$casts`, and a class-level doc block stating the append-only rule (mirror `AuditEvent`'s).
- [ ] **Step 3:** Tests: `document_access_events` row is immutable after insert (update/delete blocked at the DB by revoke SQL in Task 8); grant `expires_at` beyond 300 s rejected by CHECK.

---

## Task 3: Interfaces, validator, and adapters

**Files:** `Contracts/ObjectStorage.php`, `Contracts/MalwareScanner.php`, `Contracts/StoragePathResolver.php`, `DocumentValidator.php`, `Adapters/LocalFilesystemObjectStorage.php`, `Adapters/MockScanner.php`, `StoragePathPolicy.php`

- `ObjectStorage` interface: `put`, `copy`, `delete`, `temporaryUrl`. `temporaryUrl` returns the platform-generated signed URL (Local adapter produces a 300 s URL that resolves to the private route `GET /internal/documents/{id}/download/{token}` guarded by `RecordDocumentAccess`; a real S3 adapter would produce a real presigned URL — swap is config-only, ADR-0033 provider-neutrality precedent).
- `DocumentValidator`: finfo `mime_content_type` on the stream (actual type), extension allowlist per `DocumentKind`, max size per kind (identity docs small cap; grave import larger cap), extension↔actual-type cross-check; rejects MIME spoofing with a per-kind reason. No executable/script formats for identity documents (`file-upload-pipeline.md` §5).
- `LocalFilesystemObjectStorage`: writes under `storage/app/private/documents/{kind}/{prefix}/{key}`. `quarantine/` and `accepted/` prefixes are distinct directories; the adapter has no API that writes to `accepted/` directly except `copy` (so AC1's "no direct path to accepted storage" holds at the adapter boundary too).
- `StoragePathPolicy`: only the promotion Action may reference the `accepted/` prefix.
- `MockScanner`: deterministic — SHA-256 hash of stream equals the EICAR string's hash → `INFECTED`; bytes > kind cap → `SUSPICIOUS`; else `CLEAN`. Reads a `scanner-outage` switch (config/env) to return `ERROR` for outage tests (AC4 fail-closed).

- [ ] **Step 1:** Interfaces + value objects (`ScanVerdict`).
- [ ] **Step 2:** `LocalFilesystemObjectStorage` + `MockScanner` + `StoragePathPolicy`.
- [ ] **Step 3:** `DocumentValidator` with finfo-based MIME verification.
- [ ] **Step 4:** Tests: adapter put/copy/delete round-trip under private root; validator rejects spoofed extension (`foo.pdf` containing a zip), oversized file, disallowed kind; mock scanner verdicts incl. EICAR `INFECTED` and `ERROR` outage switch.

---

## Task 4: `UploadDocument` Action (AC1, AC3, AC13, AC14)

**Files:** `Actions/UploadDocument.php`, `Jobs/ScanDocumentJob.php`, `DocumentVaultServiceProvider.php`, `Models/Document.php`

- Signature: `upload(DocumentKind $kind, UploadedFile|Stream $file, string $ownerType, int|string $ownerId, ?string $clientUploadId, array $meta) : Document`
- Behaviour, in ONE `DB::transaction()`:
  1. If `$clientUploadId` matches an existing `documents` row → resume (update storage, keep state, return existing) — idempotent resume, parent draft never lost (AC13).
  2. `DocumentValidator::assertValid($kind, $file)` — throws a validation result with per-file reason; rejection never clears sibling files.
  3. Store the object ONLY under the `quarantine/` prefix via `ObjectStorage::put` with a random opaque `storage_key` (never the original filename).
  4. Create the `documents` row in `QUARANTINED` state.
  5. `Outbox::record('document.uploaded.v1', 1, 'document', $document->id, ['kind' => ..., 'state' => 'quarantined'], OutboxClassification::Confidential, idempotencyKey: "upload:{$document->id}")` — reference + kind only, never content (`outbox-event-contract.md` rule 3).
  6. `ScanDocumentJob::dispatch()->onQueue('media')` (queue-and-outbox §2; import scans are `media` not `imports` so they don't starve the batch queue).
- Document kind `GRAVE_IMPORT` routes through the identical path (AC14); the larger size cap + `scanner_required` are kind-derived.

- [ ] **Step 1:** Implement the Action + resume logic.
- [ ] **Step 2:** `ScanDocumentJob` on the `media` queue.
- [ ] **Step 3:** Provider registration (interface→adapter bindings).
- [ ] **Step 4:** Tests: quarantine-first (no row ever starts `ACCEPTED`); direct `Document::create(['state' => 'accepted'])` still impossible via state-machine (enum + promotion-only write API); resume returns the same row; grave-import kind passes validation; outbox row contains no content key.

---

## Task 5: `ScanDocument` + `PromoteDocument` (AC4, AC5, AC11)

**Files:** `Actions/ScanDocument.php`, `Actions/PromoteDocument.php`, `Actions/RetainDocument.php`, `Models/DocumentScan.php`

- `ScanDocument::scan(Document $document)`:
  - If `scanner_required=false` for the kind (allowed only for non-restricted kinds), skip to verdict CLEAN.
  - Else run `MalwareScanner::scan(reference)`. Write a `document_scans` row (scanner name, engine version, verdict, evidence, attempt++). On `CLEAN` → state `SCANNING` (awaiting promotion) with a positive verdict recorded. On `INFECTED`/`SUSPICIOUS` → state `REJECTED` (INFECTED) or `SCANNING` + `suspicious` flag (SUSPICIOUS → restricted review). On `ERROR` → state stays `SCANNING`, row re-queued with bounded backoff — **never `ACCEPTED`** (AC4 fail-closed).
  - Audit `DOCUMENT_SCAN` on verdict change (outcome allowed for CLEAN, denied for INFECTED/SUSPICIOUS).
- `PromoteDocument::promote(Document $document)`:
  - Guard: requires a recorded `CLEAN` verdict on the latest scan AND `state === SCANNING`. Absence of a verdict cannot promote (AC4; "copy-then-verify-then-delete" `file-upload-pipeline.md` §2).
  - In ONE transaction: `ObjectStorage::copy(quarantine → accepted)`, verify checksum (SHA-256 of accepted object == recorded `checksum_sha256`), only then `ObjectStorage::delete(quarantine)`, set `state = ACCEPTED`, `Audit::record('DOCUMENT_ACCEPTED', ..., reason: kind===deceased-doc ? 'accepted after clean scan' : null)`, `Outbox::record('document.accepted.v1', ..., idempotencyKey: "promote:{$document->id}")`.
  - The transition is the auditable event (AC5). If copy or checksum verify fails, state stays `SCANNING`, nothing promoted, failure observable (design.md §Observability: promotion failures).
- `RetainDocument::retain(Document $document, string $reason)`: logical delete — sets `state = DELETED` and `retention_until`, preserves audit + evidence (AC11). Refuses while an evidence/legal-hold flag is set. Audits `DOCUMENT_DELETE` (now on `SensitiveActions`) with mandatory reason.

- [ ] **Step 1:** `ScanDocument` incl. scanner-outage behavior.
- [ ] **Step 2:** `PromoteDocument` copy→verify→delete.
- [ ] **Step 3:** `RetainDocument` + `SensitiveActions::ACTIONS` += `DOCUMENT_DELETE`.
- [ ] **Step 4:** Tests: no promotion without CLEAN verdict; scanner outage (ERROR) leaves `SCANNING` and never `ACCEPTED`; quarantine object gone only after accepted copy verified; checksum mismatch blocks promotion; deletion preserves audit rows and evidence; `DOCUMENT_DELETE` without reason throws `AuditReasonRequiredException`.

---

## Task 6: `IssueSignedUrl` + `RecordDocumentAccess` + policy (AC6, AC7, AC8, AC9)

**Files:** `Actions/IssueSignedUrl.php`, `Actions/RecordDocumentAccess.php`, `Policies/DocumentAccessPolicy.php`, `Models/SignedUrlGrant.php`

- `DocumentAccessPolicy::canView(ActorContext $actor, Document $document)`: role AND record relationship both required (AC9). Role: admin/vendor/operator/customer per matrix; relationship: actor must hold a `ScopeAssignment` or an explicit record link (owner match / case assignment / vendor order) — a role alone returns `denied`. Uses `ScopeAssignmentResolver` / `ActorContext` from `platform-identity-and-access`; no existence leak on denial.
- `IssueSignedUrl::issue(ActorContext $actor, Document $document, string $purpose) : SignedUrlGrant`:
  - Guard 1: policy passes, else audit `DOCUMENT_ACCESS_DENIED` and return a denial (never "no such document" — AC9 no existence leak).
  - Guard 2: `state === ACCEPTED`, else no URL (AC7) + audit.
  - Create `signed_url_grants` row with `expires_at = now + min(300s, purpose-capped)` (deceased documents hard 300 s, AC6), single purpose.
  - `Audit::record('DOCUMENT_ACCESS_GRANT', subject: document, outcome: allowed, purpose in metadata)` (AC8: actor, purpose, record, timestamp, outcome).
  - Return the grant; the signed URL is resolved via `ObjectStorage::temporaryUrl`.
- `RecordDocumentAccess::record(...)`: append-only `document_access_events` row on every actual access (route middleware for the private download route). `document.accessed.v1` outbox event (RESTRICTED, `event-catalog.md:24`) — idempotent per access event.

- [ ] **Step 1:** Policy with role+relationship check.
- [ ] **Step 2:** `IssueSignedUrl` with acceptance guard, purpose cap, audit.
- [ ] **Step 3:** `RecordDocumentAccess` + `document.accessed.v1` wiring.
- [ ] **Step 4:** Tests: no URL before accepted; URL expiry never > 300 s; cross-record access denied (actor unrelated to record, even with a role); cross-purpose denied; every issuance + every access has an audit/access row; denial produces no existence leak (identical response shape).

---

## Task 7: Private download route + state rendering contract

**Files:** `routes/web.php` (internal group), a Livewire-ready state helper, `resources/views/platform/document-vault/` partials

- Route `GET /internal/documents/{document}/download/{token}` → middleware: verify grant token, not consumed, not expired, policy passes → stream from `accepted/` via `ObjectStorage` with `Content-Disposition: attachment` (`file-upload-pipeline.md` §6). `RecordDocumentAccess::record` runs on every hit; the token is single-use (consume on first successful use).
- A quarantined/scanning/rejected file renders **filename, type icon, size only** — no preview, no thumbnail, ever (tasks.md §Design system). State machine mapping for consumers: `idle → uploading (determinate, cancellable) → scanning (pending, indeterminate) → accepted (success) → rejected (danger + reason + retry)`.
- The 300 s URL validity is surfaced in the UI copy so a dead link is never a surprise (tasks.md §Design system).

- [ ] **Step 1:** Private route + token verification + single-use consume.
- [ ] **Step 2:** Blade partials for the state machine (state-mapping only; the consuming screens are owned by their own specs).
- [ ] **Step 3:** Tests: valid token streams attachment; consumed/expired/foreign token 404s; token access writes an access row; no public URL serves a quarantined file.

---

## Task 8: Append-only enforcement + provider registration + doc updates

**Files:** `sql/revoke-document-mutations.sql`, `DocumentVaultServiceProvider.php`, `docs/contracts/event-catalog.md`, `.kiro/specs/platform-document-vault/{tasks.md,traceability-matrix.md}`

- Revoke UPDATE/DELETE on `document_access_events` from the app DB role (mirror `app/Platform/Audit/sql/revoke-audit-mutations.sql`).
- Provider registration: `DocumentVaultServiceProvider` binds `ObjectStorage` and `MalwareScanner` from config; dev default = LocalFilesystem + MockScanner; staging verification requires the real-scanner path (env-driven) per `file-upload-pipeline.md` §9.
- Docs: add `document.uploaded.v1` / `document.accepted.v1` / `document.deleted.v1` to `event-catalog.md` (v0.4 → v0.5); mark the platform-document-vault tasks closed per this plan's traceability.

- [ ] **Step 1:** Revoke SQL + apply-in-CI check.
- [ ] **Step 2:** Provider bindings.
- [ ] **Step 3:** Doc updates (event-catalog, tasks.md checkboxes).
- [ ] **Step 4:** Test: app role update/delete of `document_access_events` fails.

---

## Task 9: Review slices, fix wave, re-review

### 9a. Task-scoped review slices (dispatched concurrently)

Per `AGENTS.md` §Development methodology two-tier review. Dispatch 3 reviewers:
1. **Security/access slice** — AC6–AC9, AC11, AC12: signed-URL lifetime, purpose binding, role+relationship policy, no existence leak, append-only access audit, no attachment path.
2. **Storage/scanner slice** — AC1–AC5, AC10, AC14: quarantine-first, copy-then-verify-then-delete, fail-closed promotion, no public path, kind-aware validation, import routing.
3. **Tests/UX-state slice** — task-design contract: EICAR/MIME/oversize/outage/no-URL-before-accepted tests, cross-record denial, no-URL->300 s, upload progress/cancel/retry contract, state-machine rendering.

Each reviewer reports Critical/Important/Minor findings + "confirmed correct" list, per the retrofit plan format.

### 9b. Bounded fix wave

Triage findings; Critical and Important get one bounded fix wave with regression tests; Minor is ledgered unless trivial. Each fix in its own commit.

### 9c. Scoped re-review

Re-review the changed seams against the wave findings; confirm no new breakage.

### 9d. Documentation correction

Propagate any AC overclaim corrections to `tasks.md`/`traceability-matrix.md`/`screen-inventory.md`, following the `Ruling B` append-correction precedent.

---

## Task 10: Finish the branch

- [ ] Merge to trunk `docs/design-system-and-planning` via PR against the Wave 1 review checkpoint.
- [ ] Update `sprint-plan.md` row **S6** (`platform-document-vault`) to mark the build complete with PR + CI run, and append the OQ-4/OQ-7 NOT-TESTED note.
- [ ] Update `docs/planning/retrofit-backlog.md` §2 if this lane surfaces findings.
- [ ] Verify `composer dump-autoload`, static analysis, and the full suite on PostgreSQL 18 in CI (staggered per Wave 0 capacity baseline).

## Verification

- [ ] `vendor/bin/pest` (or the repo's test runner) green on PostgreSQL 18, including the new `tests/Feature/DocumentVault/` suite.
- [ ] EICAR, MIME-spoof, oversize, scanner-outage, no-URL-before-accepted, cross-record denial, ≤300 s URL, append-only access-audit, idempotent resume tests all present and non-vacuous.
- [ ] No restricted content in any outbox payload, audit metadata, or log (asserted in tests).
- [ ] `grep -rn "document_access_events" --include="*.sql"` shows the revoke; app role cannot UPDATE/DELETE.
- [ ] Static analysis + lint clean; Blade content-survival gate (CI gate 13) passes.
