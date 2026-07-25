# Tasks — Platform Audit

- [ ] Define the `audit_events` schema with append-only database grants.
- [ ] Implement the single `Audit::record()` write API.
- [ ] Implement a mutation+audit wrapper so the pair cannot be separated.
- [ ] Declare the sensitive-action list requiring a mandatory reason.
- [ ] Implement a metadata allowlist that rejects restricted classifications at write time.
- [ ] Implement correlation-id propagation across request, outbox, queue, provider, and notification.
- [ ] Implement scoped audit query and audit-read logging.
- [ ] Add tests: no state change commits without its audit record.
- [ ] Add tests: update and delete on `audit_events` are rejected for the app role.
- [ ] Add tests: restricted fields rejected from metadata.
- [ ] Add tests: audit survives application rollback.
- [ ] Reconcile with the 13 consuming specs so each names the audit actions it emits.

## Design system

Audit surfaces in ADM-100 (audit and sensitive-action review). Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Read-only `<x-mk.table>` §3.5; reflows to stacked cards below `--breakpoint-md`.
- Outcome uses `<x-mk.badge>` §3.6: allowed `success` · denied `danger` · failed `danger`.
- Identifiers and correlation ids use `--font-mono`; timestamps `--text-sm --mk-text-muted`.
- **No action controls on an audit row.** Audit is immutable, so the UI must offer nothing that implies otherwise.
- Required states per §6: loading · empty ("Belum ada aktivitas tercatat") · error · authorization (scope-filtered, no existence leak) · provider unavailable · duplicate-safe (n/a, read-only) · pending (long query) · success (n/a) · support · responsive.

## NOT TESTED

Nothing here is implemented. The K8 contract is external and its actual interface has not been seen. The 13 consuming specs reference audit behaviour in prose but **none enumerates the specific audit actions it emits** — that reconciliation is required and has not been done.
