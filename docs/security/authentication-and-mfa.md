# Authentication, Sessions, and Re-authentication — v0.5

MFA (TOTP enrolment, recovery codes, login-time challenge) was built in full and then removed
entirely on 22 Aug 2026 — see `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note for
the full history of why. Every section below describes the current, real mechanism: password-only
recent re-authentication, not MFA.

## 1. Decision

Use first-party same-origin Laravel session authentication for web users and Filament panels. Do not introduce OAuth server, Passport, JWT, or API tokens for MVP unless a mobile/partner API requirement is approved.

Sanctum may be added later for first-party mobile/SPA or scoped partner tokens through a separate contract and threat review.

## 2. Identity source

Authentication consumes shared K1 identity. Makam.co.id remains responsible for panel access, domain role mapping, record-level authorization, re-authentication, and audit.

## 3. Required account controls

- email verification where email is used;
- verified mobile/contact workflow where operationally required;
- secure password hashing using framework-supported defaults;
- rate-limited login, reset, verification, and re-authentication endpoints;
- generic authentication error messages;
- session ID rotation after login and privilege elevation;
- session revocation after password reset or account suspension;
- audit for privileged authentication events.

## 4. Step-up re-authentication mechanism

Password-only re-authentication (`App\Filament\Admin\Pages\PasswordReauthentication`) is the sole
step-up mechanism for every role, including platform administrator, finance, certificate issuer,
users able to approve payment/refund/payout, users able to change vendor bank details, and
privileged operator/vendor roles. There is no per-role baseline distinct from this — the same
password challenge, gated by the recency window in §5, applies uniformly.

MFA (TOTP plus recovery codes) was built as this baseline instead, then removed entirely because it
was never enforced (no beta admin account was ever MFA-enrolled — see
`docs/adr/0035-beta-launch-accepted-risks.md` item 10) and its own login-time challenge page hard-
required a confirmed enrolment to function at all, crashing for every admin whenever the freshness
window in §5 lapsed. See `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note.

Passkeys/WebAuthn remain a post-MVP improvement, not a prerequisite.

## 5. Re-authentication

Require recent password confirmation (`App\Http\Middleware\RequireRecentAuthentication`) before:

- payment/refund/payout approval;
- bank-account change;
- feature/payment gate change;
- specific-plot override;
- certificate issue/revoke/replace;
- bulk export of personal data;
- restricted retention/deletion action;
- creation/use of break-glass access.

The recent-auth window is configuration-backed and security-approved (`config('reauthentication.freshness_seconds')`, 900 seconds by default). Submissions against the password challenge (`App\Filament\Admin\Pages\PasswordReauthentication::submit()`) are rate-limited by `App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter` under their own `'password-reauthentication'` context (5 attempts/60 seconds, distinct from the middleware's own `'reauthentication-challenge'` context so the two never share a budget), and each wrong-password submission up to the rate limit writes an `audit_events` row (`App\Platform\IdentityAccess\Reauthentication\ReauthenticationAuditActions::FAILED`) with no submitted credential value in its metadata — a submission rejected by the rate limiter itself never reaches the password check, so it writes no row.

## 6. Sessions

- secure, HTTP-only, SameSite cookies;
- HTTPS only in non-local environments;
- idle and absolute timeout differentiated for public and privileged panels;
- privileged panel sessions shorter than customer sessions;
- logout from all sessions capability;
- no credentials/session tokens in logs or URLs.

## 7. Filament panel access

Each panel uses explicit `canAccessPanel`/equivalent authorization plus policies and query scopes:

```text
/admin    platform admin/finance/support by permission
/operator assigned cemetery operators only
/vendor   assigned vendor users only
```

Menu visibility is not authorization.

## 8. Service accounts and providers

- machine credentials are separate from human accounts;
- least-privilege scopes;
- rotation and expiry;
- provider webhook authentication uses signature/secret or approved asymmetric method;
- no shared personal admin account.

## 9. Account recovery

Recovery must resist support impersonation. Privileged-account recovery requires stronger verification and audit.
