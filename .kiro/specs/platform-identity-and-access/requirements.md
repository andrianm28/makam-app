# Requirements — Platform Identity and Access

**Authority:** K1/K2 external identity contract; `AGENTS.md` §Authentication and uploads; ADR-0024; `docs/security/authentication-and-mfa.md`; `docs/security/rbac-matrix.md`.

**Status:** Foundation P0. Consumed by every authenticated surface. Previously owned by no spec — see `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference to these criteria in other documents still points at the same requirement.

1. THE SYSTEM SHALL use same-origin session authentication for MVP. THE SYSTEM SHALL NOT issue a token to a browser for first-party use.
2. THE SYSTEM SHALL require TOTP MFA for every privileged role: admin, finance, issuer, auditor, operator, vendor.
3. WHEN a user attempts a financial, gate, bank-detail, certificate, plot-override, or bulk-export action THE SYSTEM SHALL require recent re-authentication, using a freshness window that is configured, not hardcoded.
4. THE SYSTEM SHALL require each panel (`/admin`, `/vendor`, operator) to declare explicit access checks. THE SYSTEM SHALL NOT grant record access on panel membership alone.
5. THE SYSTEM SHALL enforce record scope at query level for cemetery, vendor, order, case, grave, and business entity, per `rbac-matrix.md`.
6. THE SYSTEM SHALL make MFA enrolment, challenge, recovery, and reset auditable and rate-limited.
7. WHEN a session is revoked THE SYSTEM SHALL immediately revoke all active sessions for the actor.
8. THE SYSTEM SHALL resolve actor context (identity, roles, scopes) once per request as the single source consumers read.
9. WHEN an authentication or authorization attempt fails THE SYSTEM SHALL rate-limit and record the attempt without logging credentials.
10. THE SYSTEM SHALL issue anonymous booking-draft tokens that are opaque, single-purpose, expiring, and attachable to an account after verification.
11. WHEN a user follows a deep link that requires login THE SYSTEM SHALL return the user to the original location after authentication.
12. THE SYSTEM SHALL map roles to K1/K2 and SHALL store references only, not a duplicate identity master.

## Negative criteria

- No privileged role without enrolled MFA.
- No sensitive action accepted on a stale authentication.
- No cross-scope read reachable by changing an identifier in a URL.
- No credential, TOTP secret, or recovery code in logs, error trackers, or audit payloads.
- No authorization decision taken in a Blade view or Filament Resource; policies and scopes only.
