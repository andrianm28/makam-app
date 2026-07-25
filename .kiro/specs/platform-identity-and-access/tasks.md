# Tasks — Platform Identity and Access

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define `ActorContext` and resolve it once per request. _Requirements: 8_
- [ ] Implement session auth guard for public and each panel. _Requirements: 1, 4_
- [ ] Implement TOTP enrolment, challenge, recovery, and reset. _Requirements: 2, 6_
- [ ] Enforce mandatory MFA for all privileged roles. _Requirements: 2_
- [ ] Implement re-authentication middleware for the six sensitive action classes. _Requirements: 3_
- [ ] Implement scope assignment model and mandatory query scopes. _Requirements: 5_
- [ ] Implement immediate session revocation across all actor sessions. _Requirements: 7_
- [ ] Implement opaque anonymous draft token and post-login attachment. _Requirements: 10_
- [ ] Add rate limiting for auth and authorization failures. _Requirements: 9_
- [ ] Add cross-panel and cross-record authorization negative tests. _Requirements: 4, 5_
- [ ] Add MFA enrolment/challenge/recovery/revocation tests. _Requirements: 2, 6_

## Design system

UI surfaces (login, MFA challenge, re-authentication prompt, access-denied page) follow [`docs/design/design-system.md`](../../../docs/design/design-system.md) and [`resources/css/tokens.css`](../../../resources/css/tokens.css). Never hardcode a hex, px, ms, or shadow.

- Forms use `<x-mk.field>` §3.2 with `--mk-border-interactive` and `--text-base` (16 px floor).
- Re-authentication prompt uses `<x-mk.modal>` §3.4; it must state **which** action is pending.
- Access denied uses the §6.4 authorization pattern: explanatory page, never a raw 403, and **must not reveal whether the out-of-scope record exists**.
- MFA challenge errors use §6.3; never clear an entered code field on an unrelated error.
- Required states per design-system.md §6: loading · empty (no MFA device enrolled → enrolment CTA) · validation error · authorization failure · provider unavailable (TOTP clock skew / recovery channel down) · duplicate-safe (repeated challenge submit) · pending (challenge sent) · success (quiet) · support (locked-out users need a human route) · responsive.

## NOT TESTED

Nothing here is implemented; the repository contains no application code. The K1/K2 contract is external and its actual interface has **not** been seen — these criteria are derived from `docs/security/authentication-and-mfa.md` and `rbac-matrix.md`, and must be reconciled with the real K1/K2 spec before implementation.
