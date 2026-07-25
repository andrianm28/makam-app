# Tasks — Platform Feature Gate

- [ ] Model the 17 gates and 18 flags from the registry documents; do not restate them in code comments.
- [ ] Implement server-side evaluation with per-request caching and deny-by-default.
- [ ] Expose `PaymentMode`, `WhatsAppMode`, `PreNeedMode`, `GraveSearchMode` as mode values.
- [ ] Implement environment-scoped state so dev activation never implies staging or production.
- [ ] Implement privileged activation requiring recent re-authentication, evidence reference, and audit.
- [ ] Emit an outbox event on gate state change and invalidate caches.
- [ ] Implement the declared closed-state fallback for each gate.
- [ ] Add admin UI for gate state, owner, evidence, and history.
- [ ] Add tests: misconfigured gate resolves closed.
- [ ] Add tests: no MVP route or booking step disappears when a gate closes.
- [ ] Add tests: client-side tampering cannot open a gate.
- [ ] Add tests: activation without evidence is rejected.

## Design system

This spec owns the **fallback-banner contract** the whole product renders. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §6.9 and [`resources/css/tokens.css`](../../../resources/css/tokens.css):

- Closed-gate banners use `<x-mk.alert intent=info>` — `--mk-intent-info-*` — placed directly below the header, above `<main>`.
- **Not dismissible** when the mode changes how a user must pay or what they receive.
- `G-OPS-01` (Urgent) closed uses `--mk-intent-urgent-*` (alias of warning, no new hue) and states hours and coverage **without an acceptance claim**, with the hotline shown.
- Gate-closed routes use the §6.4 authorization pattern: an explanatory page, **never a generic 404**.
- Admin gate screens: `<x-mk.badge>` §3.6 — active `success` · closed `neutral` · misconfigured `danger`. Activation confirm uses `<x-mk.modal>` §3.4 with the consequence stated and the destructive option not default-focused.
- Required states per §6: loading · empty · validation error · authorization failure · provider unavailable · duplicate-safe (repeated activation is idempotent) · pending (activation in flight) · success (quiet) · support · responsive.

## NOT TESTED

Nothing here is implemented. Gate **states are unknown** — `G-PAY-01`, `G-WA-01`, `G-LEGAL-01`, `G-DATA-01` and the rest have no recorded current value in the repository, so the fallback paths cannot be exercised. `feature-flag-registry.md` exists but has not been reconciled against these criteria entry by entry.
