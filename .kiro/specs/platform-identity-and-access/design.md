# Design — Platform Identity and Access

## Module

`IdentityAccessAdapter` (`overview.md` §5). Wraps the K1/K2 contract and exposes one `ActorContext` per request: identity reference, roles, scopes, MFA state, and last-authentication timestamp.

## Data

```text
actor_sessions
mfa_enrolments
mfa_challenges
reauthentication_events
scope_assignments        -- cemetery / vendor / entity grants
access_denials           -- rate limiting and audit input
anonymous_draft_tokens
```

Identity itself is **not** mastered here; only references and platform-local authorization state.

## Enforcement points

1. Panel access check (per Filament panel and public guard).
2. Policy check per model action.
3. Mandatory query scope via global scopes or explicit builders.
4. Re-authentication middleware on the six sensitive action classes.

All four apply; any one alone is insufficient.

## Re-authentication

Sensitive actions declare a required freshness. Middleware compares `ActorContext.lastAuthenticatedAt` and challenges when stale. The challenge preserves the pending action so the user is not sent back to the start.

## Security

Rate limit by actor and IP. Never log credentials, TOTP secrets, or recovery codes. Session revocation invalidates server-side session records, not just cookies.
