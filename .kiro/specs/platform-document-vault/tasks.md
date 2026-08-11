# Tasks — Platform Document Vault

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Implement quarantine-first upload with no direct path to accepted storage. _Requirements: 1_
- [x] Implement type/content/size/extension validation including MIME-mismatch rejection. _Requirements: 3_
- [x] Implement pluggable scanner adapter; deterministic mock for development. _Requirements: 4_
- [x] Implement fail-closed promotion: positive verdict required, absence cannot promote. _Requirements: 4, 5_
- [x] Implement copy-then-verify-then-delete promotion between private prefixes. _Requirements: 1, 5_
- [x] Implement purpose-scoped signed URLs with a 300-second maximum. _Requirements: 6, 9
- [x] Block signed-URL issuance for any non-accepted state. _Requirements: 7_
- [x] Implement append-only access audit for every grant and use. _Requirements: 8_
- [x] Define the upload progress, cancel, and retry state contract. _Requirements: 13
- [ ] Implement executable upload progress, cancellation, and retry without losing the parent draft in consuming Livewire/browser specs. _Requirements: 13
- [x] Route grave-registry import files through the same pipeline. _Requirements: 14
- [x] Add tests: EICAR, MIME spoof, oversize, scanner outage, no-URL-before-accepted. _Requirements: 3, 4, 7
- [x] Add tests: cross-record and cross-purpose access denial. _Requirements: 9
- [x] Add tests: no signed URL exceeds five minutes. _Requirements: 6

## Design system

Upload UI is consumed by other specs, but the **state machine it renders** is owned here. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.7 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- `idle → uploading` (determinate progress, cancellable) `→ scanning` (`pending`, indeterminate) `→ accepted` (`success`) `→ rejected` (`danger` + reason + retry).
- A quarantined file shows **filename, type icon, and size only** — no preview, no thumbnail, ever.
- Scanner outage renders `pending` with an honest message, **never `accepted`** (§6.5).
- Surface the five-minute signed-URL validity in the UI so a user is not surprised by a dead link.
- Progress uses `role="progressbar"`; state changes announce `aria-live="polite"` (§7.4).
- Required states per §6: loading · empty ("Belum ada dokumen") · validation error (per-file, never clearing the others) · authorization (no existence leak) · provider unavailable · duplicate-safe (re-upload of the same file) · pending · success (quiet) · support · responsive.

## NOT TESTED

- PostgreSQL-only CHECK constraints, triggers, raw mutation controls,
  application-role grants, and concurrency behavior require the CI PostgreSQL
  18 environment. The Task 8 revoke SQL is reference-only until a distinct
  application role and migration role are provisioned.
- No S3-compatible object storage or real malware scanner is provisioned on
  the combined 2/4 host. Development/testing use the local object-storage
  adapter and deterministic mock scanner; staging/production must provide
  `DOCUMENT_VAULT_OBJECT_STORAGE` and `DOCUMENT_VAULT_MALWARE_SCANNER` or the
  provider fails closed — enforced at binding resolution, so bootstrap
  commands (`composer install`/`package:discover`, `config:cache`,
  `route:cache`) still succeed and the vault throws only when actually used.
- The lane's AC13 evidence covers the Blade state contract and server-side
  resume enforcement. Consuming Livewire/browser cancellation, retry, and
  parent-draft preservation are NOT TESTED here and remain deferred to
  consuming specs.
- Production role resolution remains external to this spec and fail-closed;
  the K6 identity contract is not available in this repository.
