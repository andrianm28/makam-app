# Requirements — Platform Payment Adapter

**Authority:** K3/K4/K5 shared money path; `AGENTS.md` §Domain and financial invariants; ADR-0003, ADR-0004; `docs/contracts/payment-webhook.md`; gate `G-PAY-01`.

**Status:** Foundation P0. Consumed by 7 specs. Blocks booking Step 8 and renewal Step 5. Previously owned by no spec — `docs/planning/kiro-specs-analysis.md` §2.2.

## Acceptance criteria

1. Payment mode is server-resolved: `ONLINE` or `MANUAL_COORDINATION`, per `overview.md` §15. A front-end flag never determines mode.
2. A payment session is created **only** when all guards pass: product gate open, valid confirmation or active reservation, quote accepted and unexpired, authorized opening, and `amount == quote total`.
3. Online mode uses hosted checkout. The platform never handles raw card data.
4. Paid state is set **only** by a validated webhook or an approved manual verification. Never by a browser return URL.
5. Webhooks are durable-first: persist, acknowledge within 2 seconds, then process asynchronously.
6. Webhook validation covers signature, merchant scope, amount, currency, and replay window. Any failure is recorded and rejected, never silently ignored.
7. Webhook processing is idempotent by provider event identity; duplicate or out-of-order delivery produces exactly one set of effects.
8. Manual fallback captures method, instructions, payment reference, optional proof upload, and sets `MENUNGGU_VERIFIKASI_PEMBAYARAN`. Admin verification is a separate authorized action.
9. Manual verification requires recent re-authentication and emits an audit event.
10. Every paid effect writes a balanced journal entry through `platform-financial-ledger` in the same transaction as the state change.
11. Payment failure exposes a recovery path; the order and draft survive.
12. Refund, chargeback, and reversal are explicit operations with their own authorization and journal effects; they never mutate history.
13. Merchant and `badan_usaha` binding is explicit on every session and reconciled on every webhook.
14. Provider credentials come from secret management, are environment-scoped, and never appear in logs or error trackers.

## Negative criteria

- No paid state from a browser return, a client callback, or a notification.
- No payment session for an expired reservation, an expired quote, or a closed gate.
- No implicit partial payment or deposit assumption.
- No invoice issued before approved verification.
- No provider payload containing card data persisted or logged.
