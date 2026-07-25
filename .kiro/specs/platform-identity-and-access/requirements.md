# Requirements — Platform Identity and Access

**Authority:** K1/K2 external identity contract; `AGENTS.md` §Authentication and uploads; ADR-0024; `docs/security/authentication-and-mfa.md`; `docs/security/rbac-matrix.md`.

**Status:** Foundation P0. Consumed by every authenticated surface. Previously owned by no spec — see `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. MVP uses same-origin session authentication. No token issued to a browser for first-party use.
2. TOTP MFA is **mandatory** for every privileged role: admin, finance, issuer, auditor, operator, vendor.
3. Recent re-authentication is required before financial, gate, bank-detail, certificate, plot-override, and bulk-export actions. The freshness window is configured, not hardcoded.
4. Each panel (`/admin`, `/vendor`, operator) declares explicit access checks; panel membership alone never grants record access.
5. Record scope is enforced at query level for cemetery, vendor, order, case, grave, and business entity, per `rbac-matrix.md`.
6. MFA enrolment, challenge, recovery, and reset are auditable and rate-limited.
7. Session revocation is immediate and covers all active sessions for the actor.
8. Actor context (identity, roles, scopes) is resolved once per request and is the single source consumers read.
9. Failed authentication and authorization attempts are rate-limited and recorded without logging credentials.
10. Anonymous booking-draft tokens are opaque, single-purpose, expiring, and attachable to an account after verification.
11. Deep links requiring login return the user to the original location after authentication.
12. Roles map to K1/K2; the platform stores references, not a duplicate identity master.

## Negative criteria

- No privileged role without enrolled MFA.
- No sensitive action accepted on a stale authentication.
- No cross-scope read reachable by changing an identifier in a URL.
- No credential, TOTP secret, or recovery code in logs, error trackers, or audit payloads.
- No authorization decision taken in a Blade view or Filament Resource; policies and scopes only.
