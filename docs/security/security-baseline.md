# Security and Privacy Baseline — v0.4

## Identity and privileged access

- Same-origin Laravel session authentication through shared K1 identity.
- TOTP MFA and recovery codes for privileged roles.
- Explicit Filament panel access plus policies/query scopes.
- Session revocation and recent re-authentication for financial, gate, certificate, bank-detail, plot-override, and bulk-export actions.
- See `authentication-and-mfa.md`.

## Data classifications

| Data | Minimum class/control |
|---|---|
| KTP, KK, death certificate, agreements | Restricted; quarantine, private, short URL, audit, retention |
| Payment/payout evidence | Restricted financial |
| Heir/contact | Confidential personal |
| Grave/plot internal data | Policy-dependent; separate internal/public projection |
| Memorial content | Consent-dependent; moderation and revocation |
| QR token | Public opaque token; rate-limited and revocable |
| Certificate public copy | Only if issuer/policy permits; no identity attachments |
| Audit/journal references | Append-only protected evidence |

## File controls

All uploads follow `file-upload-pipeline.md`. Restricted files fail closed when malware scanning is unavailable. Signed URL for deceased documents is at most five minutes. Email/WhatsApp never carries restricted attachments.

## Authorization

- role permission plus record-level scope;
- UI filtering is not authorization;
- query/export/file access tests cover cross-customer, cross-cemetery, cross-vendor, and cross-entity attempts;
- privileged actions use dedicated Actions, permission, reason, re-authentication, and audit.

## Payment and marketplace

Hosted checkout, durable signed/replay-protected webhook, amount/currency/merchant/entity match, idempotency, balanced journal reference, reconciliation, maker/checker payout/refund, and bank-account change verification. Land marketplace stays off without dedicated legal/security design.

## Database/secrets

Managed encrypted PostgreSQL, TLS, least-privilege application/migration roles, protected secret manager, credential rotation, no production database on developer devices.

## Logging/privacy

No secrets, raw restricted documents, signed URLs, full identifiers, unrestricted provider payload, or sensitive memorial drafts in logs/events. Error tracking requires PII scrubbing.

## Operational controls

- payment kill switch and feature gates;
- failed webhook/outbox/notification queues;
- backup/PITR and restore tests;
- Horizon/Pulse/error/uptime monitoring;
- dependency/security audits in CI;
- incident response and evidence preservation.

## Non-production environment isolation

The combined dev/staging host contains no production credentials or unsanitized production data. Development and staging use separate keys, database users, Redis namespaces, storage prefixes, provider sandboxes, cookies, and error-tracking environments. PostgreSQL/Redis ports are private. Both web environments are access-restricted and `noindex`.
