# MFA + Reauthentication Integration — Design

**Status:** Approved by user, 09 Aug 2026 (interactive brainstorming session).
**Governing methodology:** `AGENTS.md` §Development methodology. This design went through `superpowers:brainstorming` with the real user before any implementation, per that section's rule that security/authorization-adjacent work gets its own `brainstorming` pass and explicit scope sign-off — not the blanket "wire the integration" choice made earlier in the retrofit-program-level planning session.
**Retrofit program entry:** `docs/planning/retrofit-backlog.md` §1 item 2.

## Context

`app/Platform/IdentityAccess/Mfa/**` (TOTP enrolment, challenge, recovery — RFC 4226/6238-vector-tested, fully audited and rate-limited) and `app/Platform/IdentityAccess/Reauthentication/**` (`RequireRecentAuthentication` middleware, fail-closed, session-freshness-based) are both fully built and have **zero real callers** — no controller, route, Livewire component, or Filament resource invokes either. Confirmed by grep across `app/Http`, `app/Livewire`, `app/Filament`, `routes/`.

The Kiro spec (`.kiro/specs/platform-identity-and-access/design.md`) originally envisioned two integration points that turn out to be blocked on work this retrofit does not own:

- **"Mandatory MFA for privileged roles"** — there is no role model. `ActorContext::$roles` is hardcoded to always resolve to `[]`; no roles table exists; `AdminPanelAccessPolicy::allows()` reduces to a plain `isAuthenticated()` check. Every authenticated `/admin` user is currently equivalent. K1/K2 role mapping is recorded as an unresolved open decision in the Kiro spec itself.
- **"Re-authentication for financial, gate, bank-detail, certificate, plot-override, bulk-export actions"** — none of these six action classes (`docs/security/authentication-and-mfa.md` §5) has a real route or controller anywhere in the repo yet. They belong to specs (`admin-operations`, `certificates-and-agreements`, `booking-and-order-orchestration`, others) that haven't shipped the relevant flows.

This design does not attempt either of those — they stay correctly out of scope, ledgered against the specs that actually own them. What this design does instead: give both modules a **real, narrower integration** that doesn't require inventing a role model or sensitive-action controllers that belong elsewhere.

## Goals

1. Every `/admin` user can voluntarily enroll in TOTP MFA for their own account — closing the "zero HTTP callers" gap for the `Mfa` module with a real, always-available feature (no FeatureGate — this is the user's own choice, not an org-wide policy toggle).
2. Once a user has enrolled, they are challenged for a TOTP/recovery code at the start of each session before reaching the panel — otherwise enrolling would provide no actual protection.
3. Disabling MFA — the one action in this design that reduces account security — requires a fresh re-authentication first, giving `RequireRecentAuthentication` its first real attachment. The challenge itself uses `MfaChallengeService` (not password re-entry), since proving continued control of the second factor is the more meaningful check at exactly the moment it's being removed. This is the specific hand-off `RequireRecentAuthentication`'s own doc block already anticipated ("a future controller... `MfaChallengeService`").
4. Regenerating recovery codes stays ungated (no re-auth) — it doesn't reduce security the way disabling does.

## Non-goals (explicitly deferred, not built here)

- Mandatory MFA for privileged roles — blocked on a role model this retrofit does not build. Stays ledgered in `docs/planning/retrofit-backlog.md`.
- Re-authentication on any of the six sensitive-action classes — blocked on those actions' own controllers not existing yet. Each future spec that builds one of those controllers attaches `RequireRecentAuthentication` itself, per that middleware's own doc block.
- An admin-resets-another-user's-MFA recovery flow (for a user who loses their authenticator and exhausts recovery codes) — no such flow exists or is being built. Ledgered as a known gap, not silently ignored.
- `/operator` and `/vendor` panel wiring — neither panel exists yet in this repo (`find app/Providers/Filament -type f` returns only `AdminPanelProvider.php`). This design touches `/admin` only.

## Architecture

Built natively inside Filament's existing `/admin` panel: two new Filament pages plus one new middleware appended to `AdminPanelProvider`'s existing chain. No new panel, no parallel non-Filament auth path — the research confirmed no non-Filament admin surface exists, so a separate Livewire route would just be a second auth path for no benefit. The middleware-based attachment (rather than overriding Filament's login page/form directly) mirrors the pattern `RequireRecentAuthentication` already established: a named middleware that redirects to a named route, kept independent of the login form's own lifecycle so each piece stays unit-testable.

## Components

- **`MfaSettings`** (new Filament page, `app/Filament/Admin/Pages/MfaSettings.php`) — the self-service management surface:
  - Not enrolled: shows enroll flow (QR code / `OtpAuthUri`, confirm first TOTP code via `MfaEnrolmentService`, then display recovery codes exactly once via `MfaRecoveryService`).
  - Enrolled: shows status, a "Regenerate recovery codes" action (ungated — calls `MfaRecoveryService` directly), and a "Disable MFA" action (gated — see below).
- **`MfaChallenge`** (new Filament page, `app/Filament/Admin/Pages/MfaChallenge.php`) — the login-time and disable-time challenge: a form accepting a TOTP or recovery code, verified via `MfaChallengeService`. Reached only by a user who has a confirmed `MfaEnrolment`.
- **`EnforceMfaChallenge`** (new middleware, `app/Http/Middleware/EnforceMfaChallenge.php`) — appended to `AdminPanelProvider`'s existing middleware array, after `AuthenticateSession`/`Authenticate`. For an authenticated user with a confirmed `MfaEnrolment` who has not completed a challenge this session (session-scoped flag, e.g. `mfa_challenge_satisfied_at`), redirects to `MfaChallenge`. A user with no confirmed enrolment passes through untouched — nothing changes for anyone who hasn't opted in.
- **`RequireRecentAuthentication`** (existing, currently unattached) — attached to `MfaSettings`' "Disable MFA" action only. `$challengeRouteName` points at a route backed by `MfaChallenge`/`MfaChallengeService` — the same challenge mechanism as the login-time flow, reused rather than duplicated.

## Data flow

1. Login (Filament's existing flow, unchanged) → `AdminPanelProvider`'s chain now includes `EnforceMfaChallenge`.
2. No confirmed enrolment → proceed exactly as today.
3. Confirmed enrolment, no session challenge yet → redirect to `MfaChallenge` → on success, set the session flag → proceed to the panel.
4. `MfaSettings`, not enrolled → enroll flow → recovery codes shown once.
5. `MfaSettings`, enrolled, "Regenerate recovery codes" → ungated, immediate.
6. `MfaSettings`, enrolled, "Disable MFA" → `RequireRecentAuthentication` intercepts → redirect to the TOTP/recovery challenge route → on success, the disable action actually runs (revokes the `MfaEnrolment`).

## Error handling

No new security logic anywhere in this design — rate limiting, replay/drift protection, and fail-closed behavior on a missing/stale session all already exist and are already tested in the underlying services (`MfaRateLimiter`, `Totp::verify`'s replay guard, `RequireRecentAuthentication`'s fail-closed-on-null-timestamp behavior). This design's only job is to give that existing, correct logic a real caller.

One new gap this design knowingly introduces and does not solve: a user who loses their authenticator device and has exhausted their recovery codes has no self-service or admin-assisted recovery path. Recorded under Non-goals above, not silently absorbed.

## Testing

This module has zero HTTP-boundary tests today (`AdminPanelHttpAccessTest` is the only real HTTP test in the whole `IdentityAccess` suite, and it doesn't touch MFA). New coverage required:

- `MfaSettings` feature tests: enroll (wrong code rejected, right code confirms, recovery codes shown once and not retrievable again from that response), regenerate (ungated, succeeds), disable (blocked until re-auth challenge passes, succeeds after).
- `EnforceMfaChallenge` feature tests: enrolled-without-session-challenge is redirected; not-enrolled is untouched; successful challenge sets the session flag and un-redirects; the flag is session-scoped (a new session re-challenges).
- A feature test for the `RequireRecentAuthentication` → `MfaChallengeService` hand-off specifically, since this design is the first real exercise of that documented-but-unbuilt integration.
- Existing service-level and RFC-vector suites (`TotpRfc6238VectorsTest`, `HotpRfc4226VectorsTest`, `TotpVerifyReplayAndDriftTest`, `MfaAuditSafetyTest`, etc.) are unchanged and stay as regression coverage — this design adds callers, not new crypto or audit logic.

## Global constraints (apply to every task in the implementation plan)

- `AGENTS.md` §Authentication and uploads: "mandatory TOTP MFA for privileged roles" is explicitly NOT what this design builds (see Non-goals) — this design ships voluntary, always-available MFA, which is consistent with but narrower than that eventual goal.
- `AGENTS.md` §Infrastructure-agent execution: "AI agents may prepare migrations and deployment changes but human review is mandatory before security, authorization... changes" — this design was itself produced through a `brainstorming` pass with explicit user sign-off at each section, satisfying that rule for the design stage; the implementation plan and its execution remain subject to the same two-tier SDD review every other retrofit unit gets.
- `AGENTS.md` §Testing: every new behavior gets a real regression test — no exceptions carried from the pilot retrofit's "can't run PHP locally" constraint; that constraint still applies here (verify via CI push, not local `composer install`, per `CLAUDE.md` §Scope note).
- `docs/design/design-system.md`: the two new Filament pages must use `resources/css/tokens.css`-driven values only — `ci/verify-docs.sh` gates 2/3/11/12 apply to any new Blade/Filament view the same as anywhere else in the codebase.
- No new role model, no new sensitive-action controller, no FeatureGate row — all explicitly out of scope per Non-goals above; the implementation plan must not introduce any of them.

## Self-review

**Placeholder scan:** none — every section states concrete behavior, not TBD.
**Internal consistency:** the "always available, no FeatureGate" decision (Goals #1) is consistent throughout — no section reintroduces gating logic. The "disable-only" re-auth scope (Goal #3) is consistent — regenerate is explicitly ungated in both Components and Data flow.
**Scope check:** single, focused unit — one retrofit branch, one PR, matching the pilot's pattern. Does not need further decomposition.
**Ambiguity check:** "session-scoped" for the challenge flag is made concrete (a session key, re-challenged on a new session) rather than left vague.
