# Tasks — Platform Document Vault

- [ ] Implement quarantine-first upload with no direct path to accepted storage.
- [ ] Implement type/content/size/extension validation including MIME-mismatch rejection.
- [ ] Implement pluggable scanner adapter; deterministic mock for development.
- [ ] Implement fail-closed promotion: positive verdict required, absence cannot promote.
- [ ] Implement copy-then-verify-then-delete promotion between private prefixes.
- [ ] Implement purpose-scoped signed URLs with a 300-second maximum.
- [ ] Block signed-URL issuance for any non-accepted state.
- [ ] Implement append-only access audit for every grant and use.
- [ ] Implement upload progress, cancel, and retry without losing the parent draft.
- [ ] Route grave-registry import files through the same pipeline.
- [ ] Add tests: EICAR, MIME spoof, oversize, scanner outage, no-URL-before-accepted.
- [ ] Add tests: cross-record and cross-purpose access denial.
- [ ] Add tests: no signed URL exceeds five minutes.

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
