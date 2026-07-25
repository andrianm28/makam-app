# Requirements — Platform Document Vault

**Authority:** K6 private file contract; `AGENTS.md` §Authentication and uploads, §Authorization and files; ADR-0023; `docs/security/file-upload-pipeline.md`.

**Status:** Foundation P0. Consumed by booking Step 7, marketplace evidence, care evidence, memorial media, and renewal import. Blocks booking Step 7. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. Every untrusted file enters **private quarantine** on upload. There is no path that writes directly to accepted storage.
2. A quarantined file cannot be used, downloaded, previewed, thumbnailed, or referenced in a list view before validation and malware-scan acceptance.
3. Validation covers declared type, actual content type, size, and extension mismatch. MIME spoofing is rejected.
4. Malware scanning is **fail-closed**: a scanner outage leaves the file `pending`, never `accepted`.
5. Acceptance is an explicit state transition producing an auditable event.
6. Signed URLs for deceased documents expire within **five minutes** and are single-purpose.
7. A signed URL is never issued before the accepted state.
8. Every restricted-file access is audited: actor, purpose, record, timestamp, and outcome.
9. Access is purpose-scoped: holding a role is insufficient without a legitimate record relationship.
10. Documents are stored on S3-compatible private storage with encryption; no public bucket or public object ACL exists.
11. Deletion and retention follow approved policy while preserving audit and required evidence.
12. Files are never attached to email or WhatsApp; external channels receive an authenticated link only.
13. Upload progress, cancellation, and retry are supported without losing the parent draft or record.
14. Import files (grave registry) use the same quarantine pipeline as customer documents.

## Negative criteria

- No preview, thumbnail, or download of an unscanned file.
- No signed URL longer than five minutes for a deceased document.
- No accepted state while the scanner is unavailable.
- No private file on an external channel as an attachment.
- No file access without an audit record.
- No local always-on scanner on the combined 2/4 host (`AGENTS.md` capacity constraint).
