# Design — Platform Document Vault

## Module

`DocumentVaultAdapter` (`overview.md` §5). Wraps the K6 contract. Consumers reference documents by id and request purpose-scoped access; they never touch storage directly.

## States

```text
UPLOADING -> QUARANTINED -> SCANNING -> ACCEPTED
                                     -> REJECTED
                         -> EXPIRED
```

`ACCEPTED` is the only state from which a signed URL may be issued.

## Data

```text
documents                -- id, owner record, classification, state, checksum
document_versions
document_scans           -- scanner, verdict, evidence, attempt count
document_access_events   -- append-only audit: actor, purpose, outcome
signed_url_grants        -- purpose, expiry (<=300s), consumed flag
```

## Storage layout

Two private prefixes on S3-compatible storage: `quarantine/` and `accepted/`. Promotion is a copy-then-verify-then-delete, never an in-place flag flip. No bucket or object is ever public.

## Scanner adapter

Pluggable. Development may use a deterministic mock; CI runs EICAR and adapter contract tests. Production requires a real scanner — provider undecided (`docs/planning/sprint-plan.md` OQ-7). Outage behaviour is fail-closed by construction: promotion requires a positive verdict, so absence of a verdict cannot promote.

## Access control

Purpose-scoped grant, checked against the caller's record relationship from `platform-identity-and-access`. Every grant and every use writes to `document_access_events`.

## Observability

Quarantine queue depth, scan latency, rejection reasons, scanner availability, signed-URL issuance rate, access-audit volume, promotion failures.
