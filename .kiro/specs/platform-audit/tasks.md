# Tasks — Platform Audit

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define the `audit_events` schema with append-only database grants. _Requirements: 1, 2_
- [ ] Implement the single `Audit::record()` write API. _Requirements: 2_
- [ ] Implement a mutation+audit wrapper so the pair cannot be separated. _Requirements: 4_
- [ ] Declare the sensitive-action list requiring a mandatory reason. _Requirements: 3_
- [ ] Implement a metadata allowlist that rejects restricted classifications at write time. _Requirements: 5_
- [ ] Implement correlation-id propagation across request, outbox, queue, provider, and notification. _Requirements: 10_
- [ ] Implement scoped audit query and audit-read logging. _Requirements: 6, 7_
- [ ] Add tests: no state change commits without its audit record. _Requirements: 4_
- [ ] Add tests: update and delete on `audit_events` are rejected for the app role. _Requirements: 1_
- [ ] Add tests: restricted fields rejected from metadata. _Requirements: 5_
- [ ] Add tests: audit survives application rollback. _Requirements: 8_
- [ ] Reconcile with the 13 consuming specs so each names the audit actions it emits. _Requirements: 9_

## Design system

Audit surfaces in ADM-100 (audit and sensitive-action review). Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Read-only `<x-mk.table>` §3.5; reflows to stacked cards below `--breakpoint-md`.
- Outcome uses `<x-mk.badge>` §3.6: allowed `success` · denied `danger` · failed `danger`.
- Identifiers and correlation ids use `--font-mono`; timestamps `--text-sm --mk-text-muted`.
- **No action controls on an audit row.** Audit is immutable, so the UI must offer nothing that implies otherwise.
- Required states per §6: loading · empty ("Belum ada aktivitas tercatat") · error · authorization (scope-filtered, no existence leak) · provider unavailable · duplicate-safe (n/a, read-only) · pending (long query) · success (n/a) · support · responsive.

## Implementation status

**Rewritten 13 Aug 2026.** The previous text — "Nothing here is implemented. The K8 contract is external…" — was accurate when written and is now stale. The module shipped via the platform foundation batches and the L4/L6 lanes:

- **Schema + append-only grants:** `audit_events` with the migration role owning schema and the app role holding no `UPDATE`/`DELETE` grant; the reference-only `app/Platform/Audit/sql/revoke-audit-mutations.sql` documents the canonical revoke shape and `ci/verify-docs.sh` GATE 13 keeps it faithful. The role-level revoke itself remains **NOT EXECUTED** until finding N-1 (one PostgreSQL role per environment) is resolved — two tests pin the surviving bypasses (`tests/Feature/Audit/AuditEventAppendOnlyTest.php`).
- **Write API + wrapper:** `App\Platform\Audit\Audit::record()` is the one write API, used through `Audit::wrap()` (mutation+audit in one transaction) across all domain Actions.
- **Sensitive actions + reason:** `SensitiveActions::ACTIONS` is the declared list requiring a mandatory reason, enforced at write time (`AuditReasonRequiredException`, `Rules\NonBlankReason`; `AuditReasonRequiredTest`).
- **Metadata allowlist:** `MetadataAllowlist` rejects restricted classifications at write time (`AuditMetadataKeyNotAllowedException`; `AuditMetadataAllowlistTest`).
- **Audit-read logging:** scoped audit query writes `audit_read_events` (auditing the auditors).
- **Correlation:** `correlation_id` propagates request → audit → outbox → queue job (`Correlation` platform module, `AssignCorrelationId` middleware).

The K8 contract remains external and its actual interface has not been seen; the module stays provider-neutral behind the `Audit` facade. **Still open:** the reconciliation item below — the 13 consuming specs reference audit behaviour in prose but none enumerates the specific audit actions it emits as a list; each spec's task list names its own actions individually (e.g. `PAYMENT_MANUAL_SUBMITTED`, `JOURNAL_REVERSAL`, `RECONCILIATION_EXCEPTION_RESOLVED` added by the L3/L4 lanes) but the cross-spec enumeration is not a single document.

### Still open

- [ ] Reconcile with the 13 consuming specs so each names the audit actions it emits. _Requirements: 9_ — the individual actions are named per spec and per Action file (see above), but no single reconciled enumeration document exists yet; the audit-actions list itself is code-owned in `SensitiveActions::ACTIONS`.
