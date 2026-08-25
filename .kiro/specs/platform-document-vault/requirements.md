# Requirements — Platform Document Vault

**Authority:** K6 private file contract; `AGENTS.md` §Authentication and uploads, §Authorization and files; ADR-0023; `docs/security/file-upload-pipeline.md`.

**Status:** Foundation P0. Consumed by booking Step 7, marketplace evidence, care evidence, memorial media, and renewal import. Blocks booking Step 7. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. WHEN a user uploads an untrusted file THE SYSTEM SHALL place it into **private quarantine**. THE SYSTEM SHALL NOT provide any path that writes directly to accepted storage.
2. THE SYSTEM SHALL NOT allow a quarantined file to be used, downloaded, previewed, thumbnailed, or referenced in a list view before it passes validation and malware-scan acceptance.
3. WHEN a file is uploaded THE SYSTEM SHALL validate its declared type, actual content type, size, and extension for mismatch, and SHALL reject MIME-spoofed files.
4. WHILE the malware scanner is unavailable THE SYSTEM SHALL leave the file `pending` and SHALL NOT mark it `accepted` — malware scanning is **fail-closed**.
5. WHEN a file is accepted THE SYSTEM SHALL perform an explicit state transition that produces an auditable event.
6. THE SYSTEM SHALL expire every signed URL issued for a deceased document within **five minutes** and SHALL restrict it to a single purpose.
7. THE SYSTEM SHALL NOT issue a signed URL before a file reaches the accepted state.
8. WHEN a restricted file is accessed THE SYSTEM SHALL audit the actor, purpose, record, timestamp, and outcome.
9. THE SYSTEM SHALL grant file access only when the requester holds both the role and a legitimate relationship to the record; holding a role alone SHALL NOT be sufficient.
10. THE SYSTEM SHALL store documents on S3-compatible private storage with encryption; THE SYSTEM SHALL NOT create a public bucket or public object ACL.
11. THE SYSTEM SHALL apply deletion and retention according to approved policy while preserving audit records and required evidence.
12. THE SYSTEM SHALL NOT attach files to email or WhatsApp messages; external channels SHALL receive an authenticated link only.
13. THE SYSTEM SHALL support upload progress, cancellation, and retry without losing the parent draft or record.
14. WHEN a grave-registry import file is received THE SYSTEM SHALL route it through the same quarantine pipeline used for customer documents.

## Negative criteria

- No preview, thumbnail, or download of an unscanned file.
- No signed URL longer than five minutes for a deceased document.
- No accepted state while the scanner is unavailable.
- No private file on an external channel as an attachment.
- No file access without an audit record.
- No local always-on scanner on the combined 2/4 host (`AGENTS.md` capacity constraint).
