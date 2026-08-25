# ADR-0029: Platform Foundation Specs, Consumed Not Redefined

## Status

Proposed — 25 July 2026. Not yet accepted by a human reviewer.

## Context

The spec corpus held 19 feature specs covering every stakeholder workflow, but eight cross-cutting concerns were consumed by many specs and owned by none: identity/access, payment, notifications, document vault, audit, feature gate, transactional outbox, financial ledger. Each consumer would have invented its own version.

Severity, from [`kiro-specs-analysis.md`](../planning/kiro-specs-analysis.md) §2.2 and §2.3:

- `platform-audit` behaviour is required by **17 of 19** feature specs ([`sprint-plan.md`](../planning/sprint-plan.md) §3.4) and was defined by none.
- The word `outbox` appeared **0 times** in the entire `.kiro/` tree, although [`AGENTS.md`](../../AGENTS.md) makes the transactional outbox mandatory for critical domain events (ADR-0019).
- Other `AGENTS.md` mandates with zero presence before this change: MFA/TOTP, re-authentication, the five-minute signed-URL limit.
- Consequence: booking Steps 8 and 9 — the flagship workflow's last two steps — could not be built from the specs as written.

## Decision

Introduce eight `platform-*` foundation specs as first-class Kiro specs, and establish the rule that **a feature spec consumes a foundation but never redefines one.** Where a foundation owns a table or a state contract, the consuming spec references it.

The corpus goes from 19 specs / 57 files to **27 specs / 81 files**. Each new spec has a complete requirements/design/tasks triad. They are registered in [`docs/specs/README.md`](../specs/README.md).

Build order is derived by counting consumers, not by preference: audit 17, identity 16, feature-gate 12, notifications 9, outbox 8, document-vault 7, payment 6, ledger 5. That yields tiers, with identity + feature-gate + audit as **Tier 0, blocking everything**.

## Consequences

### Positive

- every feature spec has a named owner for each concern it consumes;
- duplicate table ownership was resolved at the same time;
- sequencing is derivable from the specs rather than negotiated.

### Negative

- The sprint plan grew from four sprints to five, adding a Tier-0 implementation sprint of roughly **21.5 person-days** that was previously invisible. This is the cost of the discovery, not of the decision: the work was always required, the old plan simply had nowhere to put it.

## NOT TESTED / risk

- **None of the eight specs is implemented.** No claim here is a `PASS`; the executed evidence is the grep counts and the file counts only.
- **The K1–K8 external contracts have never been seen.** These specs derive their criteria from `docs/security/`, `docs/contracts/`, and `docs/domain/`, and must be reconciled against the real external contracts before implementation.
- Gate-blocked: `platform-payment-adapter` and `platform-financial-ledger` need the FIN-DEC approvals in [`release-gates.md`](../testing/release-gates.md) §H; `platform-document-vault` needs an object-storage provider decision (OQ-4) that has not been made.
- The audit consumer count is recorded as 17 of 19 in `sprint-plan.md` §3.4 but as 13 of 19 in `kiro-specs-analysis.md` §2.2 and in the `platform-audit` spec header. The discrepancy is unreconciled; the tiering above holds under either figure.
