# Authentication, Sessions, and MFA — v0.4

## 1. Decision

Use first-party same-origin Laravel session authentication for web users and Filament panels. Do not introduce OAuth server, Passport, JWT, or API tokens for MVP unless a mobile/partner API requirement is approved.

Sanctum may be added later for first-party mobile/SPA or scoped partner tokens through a separate contract and threat review.

## 2. Identity source

Authentication consumes shared K1 identity. Makam.co.id remains responsible for panel access, domain role mapping, record-level authorization, re-authentication, and audit.

## 3. Required account controls

- email verification where email is used;
- verified mobile/contact workflow where operationally required;
- secure password hashing using framework-supported defaults;
- rate-limited login, reset, verification, and MFA endpoints;
- generic authentication error messages;
- session ID rotation after login and privilege elevation;
- session revocation after password/MFA reset or account suspension;
- audit for privileged authentication events.

## 4. MFA policy

TOTP MFA plus recovery codes is the baseline for:

- platform administrator;
- finance;
- certificate issuer;
- users able to approve payment/refund/payout;
- users able to change vendor bank details;
- privileged operator/vendor roles;
- break-glass accounts.

Passkeys/WebAuthn are a post-MVP improvement, not a prerequisite.

## 5. Re-authentication

Require recent password/MFA confirmation before:

- payment/refund/payout approval;
- bank-account change;
- feature/payment gate change;
- specific-plot override;
- certificate issue/revoke/replace;
- bulk export of personal data;
- restricted retention/deletion action;
- creation/use of break-glass access.

Suggested recent-auth window is configuration-backed and security-approved.

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

Recovery must resist support impersonation. Privileged-account recovery requires stronger verification and audit. Recovery codes are one-time and stored hashed where possible.
