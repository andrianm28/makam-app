# Tasks — Platform Feature Gate

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Model the 17 gates and 18 flags from the registry documents; do not restate them in code comments. _Requirements: 1, 3_
- [ ] Implement server-side evaluation with per-request caching and deny-by-default. _Requirements: 2, 8, 10_
- [ ] Expose `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode` as mode values. _Requirements: 7_
- [ ] Implement environment-scoped state so dev activation never implies staging or production. _Requirements: 11_
- [ ] Implement privileged activation requiring recent re-authentication, evidence reference, and audit. _Requirements: 4, 12_
- [ ] Emit an outbox event on gate state change and invalidate caches. _Requirements: 8, 9_
- [ ] Implement the declared closed-state fallback for each gate. _Requirements: 5, 6_
- [ ] Add admin UI for gate state, owner, evidence, and history. _Requirements: 3_
- [ ] Add tests: misconfigured gate resolves closed. _Requirements: 10_
- [ ] Add tests: no MVP route or booking step disappears when a gate closes. _Requirements: 6_
- [ ] Add tests: client-side tampering cannot open a gate. _Requirements: 2_
- [ ] Add tests: activation without evidence is rejected. _Requirements: 4_

## Design system

This spec owns the **fallback-banner contract** the whole product renders. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.9 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Closed-gate banners use `<x-mk.alert intent=info>` — `--mk-intent-info-*` — placed directly below the header, above `<main>`.
- **Not dismissible** when the mode changes how a user must pay or what they receive.
- `G-OPS-01` (Urgent) closed uses `--mk-intent-urgent-*` (alias of warning, no new hue) and states hours and coverage **without an acceptance claim**, with the hotline shown.
- Gate-closed routes use the §6.4 authorization pattern: an explanatory page, **never a generic 404**.
- Admin gate screens: `<x-mk.badge>` §3.6 — active `success` · closed `neutral` · misconfigured `danger`. Activation confirm uses `<x-mk.modal>` §3.4 with the consequence stated and the destructive option not default-focused.
- Required states per §6: loading · empty · validation error · authorization failure · provider unavailable · duplicate-safe (repeated activation is idempotent) · pending (activation in flight) · success (quiet) · support · responsive.

## Implementation status

**Rewritten 13 Aug 2026.** The previous text — "Nothing here is implemented. Gate **states are unknown**…" — was accurate when written and is now stale on both counts. The module shipped via the platform foundation batches and later lanes:

- **Gate model:** `feature_gates`/`feature_flags`/`gate_activations`/`gate_environment_states` with the 17 gates and 18 flags from the registry, seeded **closed** (`2026_07_26_120400_seed_feature_gate_registry.php`, `FeatureGateRegistrySeedTest`). `GateState` is a code-owned closed enum, not restated in comments.
- **Evaluation:** `App\Platform\FeatureGate\FeatureGateResolver` resolves per request via `EloquentGateRegistrySource` with a per-request snapshot (`GateRegistrySnapshot`), **deny-by-default**; `ModeResolver` exposes the four named modes (`PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode`) plus `UrgentMode` — read server-side by Steps 8/3 of the booking wizard, the renewal journey, and the payment guard (`ModeResolverTest`).
- **Environment-scoped state:** `GateEnvironmentState` scopes activation by environment so dev activation never implies staging/production (`GateStateTest`).
- **Privileged activation + audit:** `GateActivationRecorder` requires evidence reference and writes audit rows (`GateActivationRecorderTest`, `MissingActivationEvidenceException`); the re-authentication requirement for activation follows `platform-identity-and-access`'s `RequireRecentAuthentication` middleware (wired on the finance/payment routes).
- **Outbox event on change:** `GateActivationRecorder` emits `GateStateChanged` → outbox row in the same transaction as the state change (`GateActivationRecorder.php:143-165`; note `feature_gate.state_changed.v1` is **not yet a catalogued event** in `docs/contracts/event-catalog.md` — the recorder's own doc block records that gap).
- **Closed-state fallbacks:** the declared fallbacks are implemented by consumers — e.g. `PaymentMode::ManualCoordination` (Step 8 manual fallback, `GuardPaymentSession` deny-only), `WhatsAppMode::EmailInAppFallback` (email + in-app, WhatsApp recorded `UNAVAILABLE`), `PreNeedMode::InterestOnly` (`RegisterPreNeedInterest` creates no financial obligation), `GraveSearchMode::ManualAssistance` — with gate-closed routes rendering explanatory §6.4/§6.9 states, never a generic 404 (`GateClosedBladeComponentsRenderTest`, `ClientSideTamperingCannotOpenAGateTest`).

**Still open:** no admin UI for gate state/owner/evidence/history exists (the master-data admin panel does not include a gate-management screen), and `feature-flag-registry.md` has not been reconciled against these criteria entry by entry in a single document (the reconciliation lives across the consuming specs' task notes).
