# Requirements — Platform Audit

**Authority:** K8 append-only audit contract; `AGENTS.md` §Authorization and files, §Observability; ADR-0005.

**Status:** Foundation P0. **Required by 13 of 19 feature specs and defined by none** — the largest single gap in `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. Audit records are **append-only**. No update, no delete, no soft-delete, no retro-edit.
2. Every record carries: actor reference, actor role, action, subject record, timestamp, source (panel/API/job), correlation id, and outcome.
3. Sensitive actions carry a **mandatory reason** where the domain requires one — including `DITOLAK`, plot override, tariff-source change, gate change, manual payment verification, certificate revoke, and vendor payout.
4. Audit writing is part of the same database transaction as the state change it describes. A committed state change with no audit record is a defect.
5. Restricted data is **never** stored in an audit payload. Audit references records; it does not copy their sensitive contents.
6. Audit is queryable by actor, subject, action, and time range, with results scoped to the reader's authorization.
7. Audit read access is itself audited.
8. Retention follows approved policy; audit survives record deletion and application rollback.
9. Financial and authorization audit is complete enough to reconstruct who authorized what, when, on which version.
10. Correlation id is preserved across request, outbox, queue, provider, and notification flows.

## Negative criteria

- No mutable audit record.
- No state change committed without its audit record.
- No KTP, KK, death-certificate content, bank detail, credential, or full address in an audit payload.
- No audit query returning records outside the reader's scope.
- No audit deletion as part of a rollback.
