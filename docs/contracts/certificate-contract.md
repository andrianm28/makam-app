# Agreement and Certificate Contract

Rewritten 07 Sep 2026 (CONTRACT-04 audit finding) against the actual shipped
`App\Domain\AgreementCertificate` implementation — `database/migrations/
2026_08_17_100010_create_certificates_table.php`, `CertificateStatus`, and
the `Actions/` directory — rather than the aspirational fields/commands the
previous version of this doc described.

## Storage shape

One `certificates` row per certificate **version**. Replacement never
mutates or deletes an existing row — it marks the incumbent `replaced` and
inserts the next `version_number` (AC5). There is no `supersedes_id` column
and no `audit_reference` column; version ordering is the append-only
`(subject_type, subject_id, type, version_number)` sequence
(`certificates_subject_type_version_unique`), and audit correlation is by
**correlation ID**, not a stored foreign key — every mutating Action wraps
its write in `Audit::wrap()`, which records the actor, action, outcome, and
`CorrelationContext::current()` on the audit row; the certificate row itself
carries no pointer back to that audit entry.

Required/present fields on a row: `reference` (`'CERT-'.random(8)`, unique
per `(issued_by_ref, type, reference)` — AC7), `type`
(`CertificateType`), `version_number`, `status` (`CertificateStatus`:
`draft` | `issued` | `revoked` | `replaced` — see "No delivery state"
below for why `draft` is unused in practice), `subject_type`/`subject_id`
(polymorphic subject, same convention as `agreements`), `issued_by_ref`/
`issued_by_role` (who issued it — exposed on the AC6 status view),
`effective_at`, and a nullable `document_id` referencing a vault
`documents` row (deliberately **not** a foreign key — the vault may retain
the document row past deletion for history; see the migration's own doc
block). There is no `supersedes_id`, `audit_reference`, or a distinct
"signer evidence" field set — `issued_by_ref`/`issued_by_role` is the only
issuer/signer record kept.

## Commands (the real Actions, not aspirational ones)

- **`IssueCertificate`** — creates a row directly at `status=issued`
  (there is no separate "create draft" command; `CertificateStatus::Draft`
  is a defined enum case but nothing in `app/` ever writes it). Runs the
  `CertificateIssuerAuthorizer` role gate first, then
  `CertificateEligibilityPolicy`, then (inside the write transaction)
  requires any referenced vault document to be `DocumentState::Accepted` —
  a Quarantined/Scanning/Rejected/missing document is refused
  (`InvalidArgumentException`), never issued against. Emits
  `certificate.issued.v1` and a `CERTIFICATE_ISSUED` audit row in the same
  transaction as the insert.
- **`ReplaceCertificate`** — the AC5 replace path: marks the incumbent
  `replaced`, inserts the next version at `issued`, same subject required.
  Same role gate and document-state check as `IssueCertificate`. Emits
  `certificate.replaced.v1` + `CERTIFICATE_REPLACED` audit.
- **`RevokeCertificate`** — `issued` → `revoked` only (an already
  `revoked`/`replaced`/`draft` row is refused). Requires a non-blank
  `reason` (enforced locally in the Action, not via the platform's global
  `SensitiveActions::ACTIONS` list). **No outbox event is emitted for
  revocation** — the catalog defines no `certificate.revoked.*` event, and
  none is invented; the `CERTIFICATE_REVOKED` audit row is the only record.
- There is **no "mark delivered" command and no delivery state** in
  `CertificateStatus`. Whatever acceptance criterion currently expects
  certificate-delivery tracking is not implemented — record that gap
  against the AC in the owning spec's traceability row rather than in this
  contract (this doc describes the shipped surface, not a tracking ledger).

Issue/replace/revoke all require the issuer role gate
(`CertificateIssuerAuthorizer`) and are idempotent only in the sense that
the `(issued_by_ref, type, reference)` unique index backstops a reference
collision — a genuine retry with the same inputs generates a fresh random
reference and is **not** deduplicated by the Action itself; callers rely on
their own idempotency key upstream (e.g. the admin form's own submission
guard), not a certificate-level idempotency key.

## Errors

- `CertificateEligibilityNotMetException` — `IssueCertificate` only, when
  `CertificateEligibilityPolicy` rejects the subject/type pair.
- `CertificateIssuerNotAuthorisedException` — role gate failure on any of
  the three commands.
- `CertificateReferenceCollisionException` — the AC7 unique-index backstop,
  raised from a narrow `QueryException` classifier (never chains the raw
  exception, which would leak interpolated INSERT bindings into logs — see
  `AGENTS.md` §Observability).
- Plain `InvalidArgumentException` for: an unusable (non-`Accepted`)
  referenced document, a blank revoke reason, or revoking a certificate
  that is not currently `issued`.

Number uniqueness is scoped to `(issuer, type)`, not globally — matching
the `certificates_issuer_type_reference_unique` index, not a
single-namespace document-number scheme.
