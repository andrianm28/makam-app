# Requirements — Platform Audit

**Authority:** K8 append-only audit contract; `AGENTS.md` §Authorization and files, §Observability; ADR-0005.

**Status:** Foundation P0. **Required by 13 of 19 feature specs and defined by none** — the largest single gap in `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL NOT update, delete, soft-delete, or retroactively edit an audit record once it is written; audit records are **append-only**.
2. THE SYSTEM SHALL record, for every audit event, the actor reference, actor role, action, subject record, timestamp, source (panel/API/job), correlation id, and outcome.
3. WHEN a sensitive action occurs THE SYSTEM SHALL require a mandatory reason where the domain requires one — including `DITOLAK`, plot override, tariff-source change, gate change, manual payment verification, certificate revoke, and vendor payout.
4. WHEN a state change is committed THE SYSTEM SHALL write its audit record in the same database transaction, such that no committed state change exists without a corresponding audit record.
5. THE SYSTEM SHALL NOT store restricted data in an audit payload; THE SYSTEM SHALL reference the subject record rather than copy its sensitive contents.
6. WHEN an authorized reader queries audit records THE SYSTEM SHALL support filtering by actor, subject, action, and time range, with results scoped to the reader's authorization.
7. WHEN an audit record is read THE SYSTEM SHALL record that access as its own audit event.
8. THE SYSTEM SHALL retain audit records per approved policy; audit SHALL survive record deletion and application rollback.
9. THE SYSTEM SHALL capture financial and authorization audit detail sufficient to reconstruct who authorized what, when, and on which version.
10. THE SYSTEM SHALL preserve the correlation id across request, outbox, queue, provider, and notification flows.

## Negative criteria

- No mutable audit record.
- No state change committed without its audit record.
- No KTP, KK, death-certificate content, bank detail, credential, or full address in an audit payload.
- No audit query returning records outside the reader's scope.
- No audit deletion as part of a rollback.
