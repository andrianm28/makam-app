# Requirements — Platform Payment Adapter

**Authority:** K3/K4/K5 shared money path; `AGENTS.md` §Domain and financial invariants; ADR-0003, ADR-0004; `docs/contracts/payment-webhook.md`; gate `G-PAY-01`.

**Status:** Foundation P0. Consumed by 7 specs. Blocks booking Step 8 and renewal Step 5. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference to these criteria in other documents still points at the same requirement.

1. THE SYSTEM SHALL resolve payment mode (`ONLINE` or `MANUAL_COORDINATION`) on the server, per `overview.md` §15. THE SYSTEM SHALL NOT let a front-end flag determine mode.
2. WHEN a payment session is requested THE SYSTEM SHALL create it only when all guards pass: product gate open, valid confirmation or active reservation, quote accepted and unexpired, authorized opening, and `amount == quote total`.
3. WHILE payment mode is `ONLINE` THE SYSTEM SHALL use hosted checkout. THE SYSTEM SHALL NOT handle raw card data.
4. THE SYSTEM SHALL set paid state only via a validated webhook or an approved manual verification. THE SYSTEM SHALL NOT set paid state from a browser return URL.
5. WHEN a webhook is received THE SYSTEM SHALL persist it, acknowledge it within 2 seconds, and then process it asynchronously.
6. THE SYSTEM SHALL validate every webhook's signature, merchant scope, amount, currency, and replay window. WHEN validation fails THE SYSTEM SHALL record and reject the webhook, and SHALL NOT silently ignore it.
7. THE SYSTEM SHALL process webhooks idempotently by provider event identity. Duplicate or out-of-order delivery SHALL produce exactly one set of effects.
8. WHEN a customer uses manual fallback THE SYSTEM SHALL capture method, instructions, payment reference, and optional proof upload, and SHALL set status `MENUNGGU_VERIFIKASI_PEMBAYARAN`. Admin verification SHALL be a separate authorized action.
9. WHEN an admin performs manual verification THE SYSTEM SHALL require recent re-authentication and SHALL emit an audit event.
10. WHEN a paid effect occurs THE SYSTEM SHALL write a balanced journal entry through `platform-financial-ledger` in the same transaction as the state change.
11. WHEN a payment fails THE SYSTEM SHALL expose a recovery path; the order and draft SHALL survive.
12. THE SYSTEM SHALL implement refund, chargeback, and reversal as explicit operations with their own authorization and journal effects. THE SYSTEM SHALL NOT let these operations mutate history.
13. THE SYSTEM SHALL bind merchant and `badan_usaha` explicitly on every session and SHALL reconcile the binding on every webhook.
14. THE SYSTEM SHALL source provider credentials from secret management, scoped per environment. THE SYSTEM SHALL NOT let credentials appear in logs or error trackers.

## Negative criteria

- No paid state from a browser return, a client callback, or a notification.
- No payment session for an expired reservation, an expired quote, or a closed gate.
- No implicit partial payment or deposit assumption.
- No invoice issued before approved verification.
- No provider payload containing card data persisted or logged.
