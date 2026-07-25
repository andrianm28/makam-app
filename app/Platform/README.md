# Platform foundations

One directory per cross-cutting foundation, each specified by a `platform-*` Kiro
spec under [`.kiro/specs/`](../../.kiro/specs).

These exist because the 19 feature specs consumed eight cross-cutting concerns
that no spec owned — see [`docs/planning/kiro-specs-analysis.md`](../../docs/planning/kiro-specs-analysis.md) §2.2.

**Dependency rule:** a feature module consumes a platform foundation and must
never redefine one. Implementation order is derived from consumer count in
[`docs/planning/sprint-plan.md`](../../docs/planning/sprint-plan.md) §3.4:

| Tier | Foundations | Sprint |
|---|---|---|
| 0 | `IdentityAccess`, `FeatureGate`, `Audit`, `Outbox` (minimum) | 3 |
| 2 | `Notification`, `DocumentVault` | 6 |
| 3 | `Payment`, `FinancialLedger` | 8–9 |

Nothing ships before Tier 0.
