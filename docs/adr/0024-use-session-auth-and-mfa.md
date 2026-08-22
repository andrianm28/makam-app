# ADR-0024: Use Session Authentication and Mandatory Privileged MFA

## Status
Accepted — 23 July 2026

## Decision
Use Laravel same-origin session authentication for MVP. Require TOTP MFA for privileged roles and recent re-authentication for sensitive actions. Do not add OAuth/JWT/Passport without mobile/partner API requirements.

## Consequences
Minimum complexity and strong browser security; API clients require a future scoped authentication decision.

## Superseded (22 Aug 2026)

The "mandatory TOTP MFA for privileged roles" half of this decision is reversed. MFA was built in full (enrolment, TOTP/HOTP, recovery codes, login-time enforcement, self-service settings page — `app/Platform/IdentityAccess/Mfa/**`, removed in `docs/superpowers/plans/2026-08-22-mfa-removal-and-reauth.md`), but never enforced: `docs/adr/0035-beta-launch-accepted-risks.md` item 10 records the user's 19 Aug 2026 decision not to enrol any beta admin account. Building enrolment discovered a live bug independent of that decision — the money-route re-authentication challenge page (`MfaChallenge`) hard-required a confirmed MFA enrolment to function at all, so it crashed for every non-enrolled admin the instant `RequireRecentAuthentication`'s freshness window (15 minutes) lapsed during ordinary use.

Rather than build enrollment enforcement to fix the crash, the user decided (22 Aug 2026) to remove MFA entirely. Same-origin session authentication stands; recent re-authentication for sensitive actions now uses a password-only challenge (`App\Filament\Admin\Pages\PasswordReauthentication`) — the exact fallback this ADR's own `ReauthenticationService` doc block had already anticipated needing whenever an actor is not MFA-enrolled, now the only path since no actor ever will be.
