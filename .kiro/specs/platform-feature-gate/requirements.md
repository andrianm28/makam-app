# Requirements — Platform Feature Gate

**Authority:** ADR-0006; `docs/governance/assumptions-and-gates.md` (17 gates, 18 flags); `docs/operations/feature-flag-registry.md`; `AGENTS.md` §Mandatory MVP UX.

**Status:** Foundation P0. Consumed by 7 specs; governs every gated fallback in the MVP. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL treat `assumptions-and-gates.md` and `feature-flag-registry.md` as the single source of truth for the gate and flag registry; THE SYSTEM SHALL implement them without restating them.
2. THE SYSTEM SHALL evaluate every gate check **server-side**; THE SYSTEM SHALL NOT treat a front-end flag as authoritative (`overview.md` §15).
3. THE SYSTEM SHALL record, for every gate, an id, capability, type, owner, activation evidence, effective date, current state, and rollback path.
4. WHEN a user activates or deactivates a gate THE SYSTEM SHALL require recent re-authentication and an audited reason.
5. WHILE a gate is closed THE SYSTEM SHALL resolve it to its **documented fallback**; THE SYSTEM SHALL NOT resolve it to a removed route, a dead link, or a generic 404.
6. THE SYSTEM SHALL NOT remove a stakeholder MVP item because its gate is closed (`AGENTS.md` invariant); THE SYSTEM SHALL implement the fallback instead.
7. THE SYSTEM SHALL expose gate state to the UI as an explicit mode value, not a boolean, where the fallback has its own behaviour — `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode`.
8. THE SYSTEM SHALL cache gate evaluation per request for low cost; THE SYSTEM SHALL NOT let a cached value outlive a state change.
9. WHEN a gate state changes THE SYSTEM SHALL emit an outbox event so dependent projections and notifications react.
10. WHEN a gate is unknown or misconfigured THE SYSTEM SHALL resolve it closed; THE SYSTEM SHALL NOT resolve it open — evaluation is deny-by-default.
11. THE SYSTEM SHALL scope gate state by environment; THE SYSTEM SHALL NOT let a development activation imply staging or production activation.
12. WHEN a gate decision affects a money path or a legal capability THE SYSTEM SHALL audit it with the evidence reference.

## Negative criteria

- No client-side gate as the enforcement point.
- No gate defaulting to open on misconfiguration.
- No MVP route or booking step removed because a gate is closed.
- No gate activation without recorded evidence, owner, and date.
- No cached gate state served after a state change.
