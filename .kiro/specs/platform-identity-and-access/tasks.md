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

## Implementation status

**Rewritten 13 Aug 2026.** The previous text — "Nothing here is implemented; the repository contains no application code" — was accurate when written and is now stale. The module shipped across the platform foundation batches, lane L5 (identity seam, 12 Aug 2026), and the reauthentication-satisfy wiring (PR #21):

- **`ActorContext` resolved once per request:** `App\Platform\IdentityAccess\ActorContext` + `ActorContextResolver`, populated by the bound `LocalUsersTableIdentityAccessAdapter`; `roles` and `scopes` are populated for real as of lane L5 (`actor_role_assignments`, `scope_assignments` rows; `ActorRoleReader`, `ScopeAssignmentReader`).
- **Session auth guard per panel:** `AdminPanelAccessPolicy`/`VendorPanelAccessPolicy` gate `/admin` and `/vendor` membership to the four back-office roles / vendor users; `PanelAccessPolicy` contract consumed by both panels.
- **TOTP MFA:** `MfaEnrolmentService`/`MfaChallengeService`/`MfaRecoveryService` over `mfa_enrolments`/`mfa_challenges`/`mfa_recovery_codes` with the pure TOTP implementation (`Totp`, `Hotp`, `Base32`, `OtpAuthUri`), `MfaRateLimiter`; mandatory MFA for privileged roles enforced through `EnforceMfaChallenge` middleware (enrolled actors must complete a challenge each session).
- **Re-authentication middleware:** `RequireRecentAuthentication` is wired on the sensitive-action routes (manual verification, reversals, bulk financial export) with reason-scoped `reauthentication_events`; PR #21 (`fix/reauthentication-satisfy-wiring`) made a completed MFA challenge mint the matching `satisfied` row for the pending reason (`MfaChallenge::submit()` → `ReauthenticationService::satisfy()`; `MfaChallengeSatisfiesRecentAuthenticationTest`).
- **Scope assignment + mandatory query scopes:** `ScopeAssignment` + `ScopeAssignmentGlobalScope`/`ScopeAssignmentResolver`, `HasScopeAssignments` concern — the cemetery/vendor/order scoping every domain query inherits.
- **Session records:** `ActorSession` rows are written on login and updated on logout (`RecordActorSessionOnLogin`/`RecordActorSessionOnLogout` listeners); `RecordActorSessionAuthentication` keeps `lastAuthenticatedAt` fresh on login and on MFA challenge. **AC7 (immediate revocation across all sessions) is NOT implemented** — only the `revoked_at` column exists for a later batch to write (`ActorSession.php` doc block), so the session-revocation task below stays open.

The K1/K2 contract remains external and its actual interface has **not** been seen — `IdentityAccessAdapter` keeps the module provider-neutral behind the contract. **Still open:** the `anonymous_draft_tokens` half (opaque anonymous draft token + post-login attachment, task list item 8) is not implemented — the wizard binds drafts to the session instead (`BookingDraftBinding`), and no token-based cross-device draft resume exists; and AC7 immediate session revocation (task list item 7) is not implemented (see the session-records bullet).
