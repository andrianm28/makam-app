# Batch 2A — Step-up authentication coverage (SEC-03, SEC-04, SEC-05)

Derived from `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`, Phase 2, Batch 2A. Worktree `.worktrees/batch2a-stepup-auth`, branch `fix/batch2a-stepup-authentication`.

## Scope

Three related High-severity findings from the 6 Sep 2026 audit, sharing one root cause (step-up/session-freshness enforcement gaps) and fixed together:

- **SEC-03** — certificate admin actions (`CreateCertificateAction`, `RevokeCertificateAction`, `ReplaceCertificateAction`) gated only on `isIssuer()`, with no `ReauthenticationGuard::assertFresh()` step-up check.
- **SEC-04** — the plain `web` middleware group (public `/akun` account area, `/masuk`, `/daftar`, password reset) never registered `Illuminate\Session\Middleware\AuthenticateSession`, so a stolen session cookie kept authenticating after a password reset.
- **SEC-05** — `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()` took the max `last_authenticated_at` across every non-revoked session for a user, not the requesting session specifically — a step-up proof on one session silently re-armed freshness for a different, older, unattended session belonging to the same actor.

## Changes

### SEC-03
Added the established `ReauthenticationGuard::assertFresh()` / `ReauthenticationRequiredException` / redirect-to-`PasswordReauthentication` pattern (matching `MarkMarketplaceOrderPaidAction` and 7 other existing call sites) to all three certificate actions, immediately after their existing `isIssuer()` check.

### SEC-04
Registered `Illuminate\Session\Middleware\AuthenticateSession::class` on the `web` middleware group in `bootstrap/app.php`. No change needed in `ResetPasswordPage` — it already rotates the password hash and remember token on a successful reset; this middleware is the missing enforcement point that acts on that rotation by comparing the session's stored password hash against the user's current one on every request.

### SEC-05
- `ActorContext` gained a fifth, trailing-optional constructor parameter, `?string $sessionId`.
- `LocalUsersTableIdentityAccessAdapter` now resolves the current request's session id (`request()->hasSession() ? request()->session()->getId() : null`) and scopes `resolveLastAuthenticatedAt()`'s query by `session_id` when one is known, falling back to the old cross-session lookup only when there is genuinely no HTTP session (console/job contexts).
- **Write-path fix, discovered while verifying SEC-05 end-to-end**: `RecordActorSessionAuthentication` (the single write path both the login listener and `PasswordReauthentication::submit()` use) previously kept `session_id` from `$request->hasSession() ? $request->session()->getId() : Str::uuid()`. Under `Livewire::test()`, Livewire's internal `RequestBroker` dispatches through `withoutMiddleware()`, so the fresh `Request` object for that one internal call never has `StartSession` run against it and `hasSession()` reads false — even though the real session driver (a container singleton) is still bound and still carries the correct id. This is invisible in production (a real Livewire AJAX request always runs through the full `web` middleware group), but it meant a step-up write completed via a Filament/Livewire page could land on a random UUID instead of the actor's real session id, defeating the SEC-05 fix for exactly the pages it was meant to protect. Fixed by reading the id from `session()->getId()` unconditionally — a session driver always carries a real id from construction (`SessionManager::buildSession()` passes `$id = null`, which `Store::setId()` turns into a freshly generated one immediately; `start()` only loads data, it doesn't assign the id), so this is safe and behaviour-preserving for genuine console contexts too.
- Corrected a stale doc comment on `2026_07_26_100000_create_actor_sessions_table.php` that called the `session_id` column "nullable" when it has always been `NOT NULL`.

## Test infrastructure fix (not a finding, but required to verify SEC-05 without weakening any test)

Every pre-existing raw-HTTP feature test that exercised `RequireRecentAuthentication`/`ReauthenticationGuard` seeded an `ActorSession` row with a hand-picked `session_id` string (e.g. `'test-session-'.$user->id`) and called plain `actingAs($user)->get(...)`. Two problems, both latent until SEC-05 made session scoping real:

1. Laravel's HTTP test client generates a fresh, unpredictable session id for every `$this->get()`/`$this->post()` call — `actingAs()` alone never makes it reuse one, so these fixtures never actually matched the request's real session id.
2. Several of the hand-picked id strings (e.g. `'this-session'`) fail `Illuminate\Session\Store::isValidId()` (40-char alphanumeric), so `setId()` silently replaced them with a random id even where a real `Illuminate\Session\Store` object was constructed directly in a unit test.

Added `tests/Support/EstablishesFreshActorSession.php`, a trait providing `actingAsWithSessionAuthenticatedAt(User $user, ?CarbonImmutable $lastAuthenticatedAt = null)`: logs the user in, starts a real session, seeds the `actor_sessions` row against that real id, and returns the test case with a matching encrypting session cookie attached (`withCookie(config('session.cookie'), $sessionId)`, which Laravel's test client applies to every subsequent request in the chain). Applied to the raw-HTTP test files affected:

- `tests/Feature/IdentityAccess/Reauthentication/RequireRecentAuthenticationMiddlewareTest.php`
- `tests/Feature/Payment/VerifyManualPaymentRouteTest.php`
- `tests/Feature/Payment/RecordPaymentReversalRouteTest.php`
- `tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationSatisfiesRecentAuthenticationTest.php` (also needed a fixed `crossRequestBoundary()` that re-binds the session onto `request()` after `forgetScopedInstances()`, and is the test that surfaced the `RecordActorSessionAuthentication` write-path bug above)

`tests/Feature/Filament/CertificateAdminTest.php` needed no session-fixture change — it drives everything through `Livewire::test()`, which reuses the test's own bound session directly rather than issuing a fresh HTTP request per call — only a `create()` → `updateOrCreate()` fix for a duplicate-key collision in its own new stale-session tests. `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php` needed its hand-picked session id fixtures (`'this-session'`, `'a-different-session'`, `'this-session-has-no-row'`) replaced with real 40-char alphanumeric ids for the same `isValidId()` reason above.

## Verification

- `vendor/bin/phpunit` (full suite, Postgres 18 + Redis 8.2 real containers, CI-parity PHP 8.5.8 image): 3806 tests, 15264 assertions, 0 failures/errors attributable to this branch. The only 2 errors + 3 failures remaining are pre-existing environment gaps in the local disposable image (missing GD extension for `DocumentValidatorTest`; missing `git` binary for `VerifyNoDestructiveMigrationsCommandTest`) — both confirmed present on a clean checkout of this same commit before any Batch 2A change, and both covered by the real `git`/GD-equipped image in GitHub Actions CI.
- Mutation-tested: observed `test_a_stale_actor_passes_the_same_gate_after_a_correct_password` and the `RequireRecentAuthenticationMiddlewareTest`/`LocalUsersTableIdentityAccessAdapterTest` regressions genuinely fail under the pre-fix code (both the original session-scoping bug and, separately, the `RecordActorSessionAuthentication` write-path bug), then pass after each fix.
- `vendor/bin/pint --test`: clean (3 style issues auto-fixed with `vendor/bin/pint`, all import ordering/unused-import housekeeping, re-verified green).
- `vendor/bin/phpstan analyse --no-progress`: no errors.
- `bash ci/verify-docs.sh`: all 13 gates pass.

🤖 Generated with [Claude Code](https://claude.com/claude-code)

https://claude.ai/code/session_01V5HEWU9oWnDfM1kQTB9762
