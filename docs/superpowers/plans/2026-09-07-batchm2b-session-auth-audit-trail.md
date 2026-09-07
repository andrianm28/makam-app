# Batch M2b — session/auth audit trail (SEC-06, SEC-07, SEC-08, SEC-12)

Phase 3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Four
related Medium findings, all in the login/logout/re-authentication/audit
seam. Builds directly on Batch 2A's `RecordActorSessionAuthentication` /
`LocalUsersTableIdentityAccessAdapter` fix (SEC-05) — read those files before
touching anything here; this batch must not regress that fix.

## SEC-06 — logout never revokes its `actor_sessions` row

**Root cause, verified by reading the login flow in order:**

1. `LoginPage::login()` calls `auth()->attempt(...)`, which fires
   `Illuminate\Auth\Events\Login` **before** session regeneration.
   `RecordActorSessionOnLogin` writes an `actor_sessions` row keyed on
   `$request->session()->getId()` at that moment — the **pre-regeneration**
   session id.
2. `LoginPage::login()` then calls `session()->regenerate()`, which assigns a
   **new** session id (Laravel keeps the session data, but the id changes).
3. `LogoutController::__invoke()` calls `auth()->logout()`, which fires
   `Illuminate\Auth\Events\Logout`. `RecordActorSessionOnLogout` looks up the
   row by `$request->session()->getId()` — now the **post-regeneration** id,
   which never matches the row written in step 1.

Result: the logout listener's `where('session_id', ...)` never matches
anything, `revoked_at` is never set, and
`LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()` keeps
treating the row as live (`whereNull('revoked_at')`) until it ages out of the
freshness window on its own.

**Fix chosen:** revoke by `user_id` + `guard` on logout, not by session id.
This matches the actual semantics `LogoutController` implements — "logout
ends this user's session" — is immune to session-id churn from any future
regeneration point, and does not require threading a new session key through
the login path. The alternative (store the pre-regenerate id under a
`session(['actor_session_key' => ...])` key at login, matched at logout) was
considered and rejected: it only works if regenerate happens after the
Login event fires and preserves session data across regeneration in every
code path that logs a user in (Filament's own `/admin` login is a second,
separately-owned path we do not control), so it is more fragile for no
behavioural gain over "revoke everything for this user/guard."

Since `RecordActorSessionOnLogout` no longer scopes to one session row, its
doc block's "self-logout bookkeeping for ONE row only" framing is stale and
is corrected. This listener now performs the full-logout revocation AC7
originally called for ("revoke all active sessions for the actor") for the
`web` guard the actor is actually logging out of — narrower than "all
guards", which is deliberately out of scope (no cross-guard logout semantics
exist in this codebase).

**Test:** replace `RecordActorSessionOnLogoutTest`'s unit-shaped calls (which
construct the event and call `handle()` directly, so they cannot see the
stale-session-id bug at all) with an HTTP feature test that: registers a
user, posts `/masuk` to log in for real (so the real event ordering runs),
posts `/keluar`, and asserts `revoked_at` is set on the `actor_sessions` row
this login actually created — found by `user_id`, not by guessing the
session id.

## SEC-07 — bulk financial export unreachable over HTTP

`BulkFinancialExport::assertReauthenticatedRecently()` throws
`BulkFinancialExportReauthenticationRequiredException` when there is no
*reason-scoped* satisfied `reauthentication_events` row (independent of the
route's own `RequireRecentAuthentication` session-freshness gate — see that
middleware's own "KNOWN LIMIT" doc block). `FinanceExportController` catches
that exception and redirects to `PasswordReauthentication::ROUTE_NAME`
without writing `RequireRecentAuthentication::REASON_SESSION_KEY`.
`PasswordReauthentication::reasonForThisChallenge()` reads that key via
`session()->pull(...)` and falls back to the generic
`password_reauthentication` reason when it is absent — so the challenge is
satisfied under the wrong reason, `assertReauthenticatedRecently()`'s
`where('reason', 'bulk_financial_export')` check never matches, and the
actor loops back to the challenge page forever. The export is unreachable
over HTTP for any actor who is session-fresh but has not already separately
satisfied this exact reason.

**Fix:** in the `catch (BulkFinancialExportReauthenticationRequiredException)`
block, write
`$request->session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, BulkFinancialExport::REAUTHENTICATION_REASON)`
before redirecting, mirroring exactly what
`RequireRecentAuthentication::handle()` itself does on its own redirect.

**`ManualPayout` check:** grepped for `ManualPayout::class` usage outside its
own file and tests — there is no HTTP controller or route that invokes it
anywhere in this repo (`PayoutStatus` is a read-only vendor Filament page
with no write action). `ManualPayout` is not reachable over HTTP at all
today, so this gap does not exist for it yet. Documented here rather than
silently skipped; no code change needed until an HTTP/Filament caller for
`ManualPayout` exists.

**Test:** new feature test — log in, force-stale the reason-scoped gate
only (fresh session, no satisfied `bulk_financial_export` event), hit the
export route, follow the redirect to the challenge page, submit the correct
password, and assert a CSV actually streams back on the next request to the
export route (the redirect target `PasswordReauthentication::submit()`
already honours via `redirectIntended`).

## SEC-08 — no audit trail for authentication events

Add four listeners on Laravel's standard auth events, writing through
`Audit::record()` (the only allowed write path — see that class's doc
block). None of these four actions are on `SensitiveActions::ACTIONS`, so no
mandatory `$reason` applies; none is passed one.

| Event | Action | Outcome | actorRef | subject |
| --- | --- | --- | --- | --- |
| `Login` | `AUTH_LOGIN` | Allowed | `$event->user->getAuthIdentifier()` | `AuditSubject('user', $actorRef)` |
| `Failed` | `AUTH_LOGIN_FAILED` | Failed | `$event->user?->getAuthIdentifier()` (null if the account does not exist — no enumeration) | `AuditSubject('user', $actorRef ?? 'unknown')` |
| `Lockout` | `AUTH_LOCKOUT` | Denied | `null` (the rate limiter has no resolved identity) | `AuditSubject('login_lockout', $request->ip() ?? '0.0.0.0')` |
| `Logout` | `AUTH_LOGOUT` | Allowed | `$event->user?->getAuthIdentifier()` | `AuditSubject('user', $actorRef)` |

Never included: the submitted email/password, `$event->credentials` (Failed
event), or any other field from the login form — matching
`PasswordReauthentication::submit()`'s failed-password branch, which audits
the attempt without ever touching `$this->password`. `Failed::$credentials`
is a raw associative array a caller might have populated with `password` —
it is never read by these listeners.

`source`: `AuditSource::Panel` when the current request path is under
`/admin` or `/vendor` (Filament panels authenticate through the same `web`
guard, so guard name cannot distinguish them — path is the only signal
available), `AuditSource::Api` otherwise (the existing convention for
public-site HTTP actions that are not Panel/Job/Console — see
`SaveBookingDraftStep`/`StartBookingDraft`). `AuditSource` has no `Web` case
today and none is added — adding an enum case is a deliberate, reviewed
decision this batch does not need to make since `Api` already covers "public
HTTP, not a panel."

Registered in `IdentityAccessServiceProvider::boot()` alongside the existing
`Login`/`Logout` listener registrations. `Failed` and `Lockout` are new
`Event::listen()` calls in the same method.

**Test:** feature tests posting to `/masuk` for a correct login, a wrong
password, a lockout (five failed attempts), and `/keluar`, asserting one
`audit_events` row per case with the right action/outcome and asserting the
password never appears in any row's persisted columns.

## SEC-12 — stale MFA docs

Pure documentation, no code risk. Each file gets a dated superseding note in
the same voice `docs/security/authentication-and-mfa.md` already uses
("MFA ... was built in full and then removed entirely on 22 Aug 2026 — see
`docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note ... Every
section below describes the current, real mechanism"):

- `docs/security/security-baseline.md` §Identity (~line 6): replace "TOTP
  MFA and recovery codes for privileged roles" bullet with the current
  password-only re-authentication control plus the superseding note.
- `docs/architecture/overview.md` (~line 282): replace "mandatory privileged
  MFA" with "mandatory privileged re-authentication" plus the note.
- `README.md` (~line 155): replace "Session auth + privileged TOTP MFA" with
  "Session auth + privileged password re-authentication" plus the note.
- `docs/product/screen-inventory.md` ADM-120 (~line 186): replace "a stale
  actor is redirected to the MFA challenge" with "...to the password
  re-authentication challenge" plus the note.
- `docs/operations/observability-and-slo.md` (~line 58): replace "privileged
  action and MFA failure" with "privileged action and re-authentication
  failure" plus the note.

## Files touched

- `app/Platform/IdentityAccess/Listeners/RecordActorSessionOnLogout.php` (SEC-06)
- `app/Http/Controllers/Admin/FinanceExportController.php` (SEC-07)
- `app/Platform/IdentityAccess/Listeners/RecordAuthAuditOnLogin.php` (new, SEC-08)
- `app/Platform/IdentityAccess/Listeners/RecordAuthAuditOnLoginFailed.php` (new, SEC-08)
- `app/Platform/IdentityAccess/Listeners/RecordAuthAuditOnLockout.php` (new, SEC-08)
- `app/Platform/IdentityAccess/Listeners/RecordAuthAuditOnLogout.php` (new, SEC-08)
- `app/Platform/IdentityAccess/Providers/IdentityAccessServiceProvider.php` (SEC-08)
- `docs/security/security-baseline.md`, `docs/architecture/overview.md`,
  `README.md`, `docs/product/screen-inventory.md`,
  `docs/operations/observability-and-slo.md` (SEC-12)
- Tests: `tests/Feature/IdentityAccess/RecordActorSessionOnLogoutTest.php`
  (rewritten to HTTP), `tests/Feature/FinancialLedger/BulkFinancialExportTest.php`
  (new E2E case), new `tests/Feature/IdentityAccess/AuthEventAuditTest.php`.

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bumped memory limit)
- `php artisan test` against real PostgreSQL/Redis in Docker (`m2b-*`
  containers)
- `bash ci/verify-docs.sh`
- Security-affecting: flagged for mandatory human review per AGENTS.md
  §Infrastructure-agent execution before merge.
