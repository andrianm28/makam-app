# Tasks — Platform Document Vault

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Implement quarantine-first upload with no direct path to accepted storage. _Requirements: 1_
- [ ] Implement type/content/size/extension validation including MIME-mismatch rejection. _Requirements: 3_
- [ ] Implement pluggable scanner adapter; deterministic mock for development. _Requirements: 4_
- [ ] Implement fail-closed promotion: positive verdict required, absence cannot promote. _Requirements: 4, 5_
- [ ] Implement copy-then-verify-then-delete promotion between private prefixes. _Requirements: 1, 5_
- [ ] Implement purpose-scoped signed URLs with a 300-second maximum. _Requirements: 6, 9_
- [ ] Block signed-URL issuance for any non-accepted state. _Requirements: 7_
- [ ] Implement append-only access audit for every grant and use. _Requirements: 8_
- [ ] Implement upload progress, cancel, and retry without losing the parent draft. _Requirements: 13_
- [ ] Route grave-registry import files through the same pipeline. _Requirements: 14_
- [ ] Add tests: EICAR, MIME spoof, oversize, scanner outage, no-URL-before-accepted. _Requirements: 3, 4, 7_
- [ ] Add tests: cross-record and cross-purpose access denial. _Requirements: 9_
- [ ] Add tests: no signed URL exceeds five minutes. _Requirements: 6_

## Design system

Upload UI is consumed by other specs, but the **state machine it renders** is owned here. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.7 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- `idle → uploading` (determinate progress, cancellable) `→ scanning` (`pending`, indeterminate) `→ accepted` (`success`) `→ rejected` (`danger` + reason + retry).
- A quarantined file shows **filename, type icon, and size only** — no preview, no thumbnail, ever.
- Scanner outage renders `pending` with an honest message, **never `accepted`** (§6.5).
- Surface the five-minute signed-URL validity in the UI so a user is not surprised by a dead link.
- Progress uses `role="progressbar"`; state changes announce `aria-live="polite"` (§7.4).
- Required states per §6: loading · empty ("Belum ada dokumen") · validation error (per-file, never clearing the others) · authorization (no existence leak) · provider unavailable · duplicate-safe (re-upload of the same file) · pending · success (quiet) · support · responsive.

## NOT TESTED

Nothing here is implemented. **No S3-compatible object storage is provisioned** — `sprint-plan.md` OQ-4 blocks this spec, and local MinIO on the 2/4 host is forbidden. No malware scanner is chosen (OQ-7). The K6 contract is external and its actual interface has not been seen.
