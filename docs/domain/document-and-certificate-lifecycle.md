# Document, Agreement, and Certificate Lifecycle

## Domain records

- Booking form
- Agreement/contract
- Invoice
- Receipt
- Payment schedule
- Utilization certificate
- Relocation request and power of attorney
- Completion report

## Common fields

```text
document_number
document_type
subject_type / subject_id
version
status
issued_by
accepted_or_signed_by
issued_at / effective_at
file_reference
supersedes_id
source_system
audit_reference
```

## Certificate status

```text
PENDING_ELIGIBILITY -> READY_TO_ISSUE -> ISSUED -> DELIVERED
                                           -> REVOKED
                                           -> REPLACED
```

## Rules

- File is a representation; the database record is the domain identity.
- Reissue/replacement creates a new version and preserves prior history.
- Payment completion and certificate issuance are separate milestones.
- Signature/acceptance method and evidence must be explicit.
- Restricted identity documents are not bundled into public certificates.
