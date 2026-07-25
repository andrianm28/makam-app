# Requirements — Platform Feature Gate

**Authority:** ADR-0006; `docs/governance/assumptions-and-gates.md` (17 gates, 18 flags); `docs/operations/feature-flag-registry.md`; `AGENTS.md` §Mandatory MVP UX.

**Status:** Foundation P0. Consumed by 7 specs; governs every gated fallback in the MVP. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. `assumptions-and-gates.md` and `feature-flag-registry.md` are the single source of truth for the gate and flag registry. This spec implements them and does not restate them.
2. Every gate check is **server-side**. A front-end flag is never authoritative (`overview.md` §15).
3. A gate carries: id, capability, type, owner, activation evidence, effective date, current state, and rollback path.
4. Activating or deactivating a gate is a privileged action requiring recent re-authentication and an audited reason.
5. A closed gate resolves to its **documented fallback**, never to a removed route, a dead link, or a generic 404.
6. `AGENTS.md` invariant: a stakeholder MVP item is **never removed** because a gate is closed. The fallback is implemented instead.
7. Gate state is readable by the UI as an explicit mode value, not a boolean, where the fallback has its own behaviour — `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode`.
8. Gate evaluation is cheap and cached per request; a cache must never outlive a state change.
9. Gate state changes emit an outbox event so dependent projections and notifications react.
10. Evaluation is deny-by-default: an unknown or misconfigured gate resolves closed, never open.
11. Gate state is environment-scoped; development activation never implies staging or production activation.
12. Every gate decision affecting a money path or a legal capability is audited with the evidence reference.

## Negative criteria

- No client-side gate as the enforcement point.
- No gate defaulting to open on misconfiguration.
- No MVP route or booking step removed because a gate is closed.
- No gate activation without recorded evidence, owner, and date.
- No cached gate state served after a state change.
