# Tasks — Platform Payment Adapter

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Implement server-resolved `PaymentMode` and expose it to consumers. _Requirements: 1_
- [ ] Implement the six-condition payment guard as a single Action. _Requirements: 2_
- [ ] Implement hosted-checkout session creation with merchant/amount binding. _Requirements: 3, 13_
- [ ] Implement durable webhook receiver: persist-then-ack within 2 seconds. _Requirements: 5_
- [ ] Implement signature, merchant, amount, currency, and replay validation. _Requirements: 6_
- [ ] Implement idempotent webhook application keyed on provider event identity. _Requirements: 7_
- [ ] Implement manual fallback: reference, proof upload, pending state, admin verification. _Requirements: 8_
- [ ] Require recent re-authentication on manual verification and payout actions. _Requirements: 9_
- [ ] Write the balanced journal entry in the same transaction as the paid effect. _Requirements: 10_
- [ ] Implement refund/chargeback/reversal as explicit non-destructive operations. _Requirements: 12_
- [ ] Add guard tests: closed gate, expired quote, expired reservation, amount mismatch, unauthorized opening. _Requirements: 2_
- [ ] Add webhook tests: bad signature, wrong merchant, wrong amount, duplicate, replay, out-of-order, dead dispatcher. _Requirements: 6, 7_
- [ ] Add tests proving no paid state can be reached from a browser return. _Requirements: 4_

## Design system

Payment UI lives in the consuming specs (booking Step 8, renewal Step 5, marketplace checkout), but the **state contract** is owned here. Per [`docs/design/design-system.md`](../../../docs/design/design-system.md) §3.7 and §6.9:

- `MENUNGGU_PEMBAYARAN` → `pending`; `MENUNGGU_VERIFIKASI_PEMBAYARAN` → `pending`, **never `success`**; `DIBAYAR` → `success`.
- `MANUAL_COORDINATION` renders an `<x-mk.alert intent=info>` banner (§6.9) that is **not dismissible** — it changes how the user must pay. Step 8 is never removed.
- Provider unavailable follows §6.5: fallback path or truthful pending, never a dead end.
- Duplicate submission follows §6.6: the same confirmation, never a second order.
- Never surface a provider name, stack trace, or correlation ID to a public user; return a support reference instead.
- Resolve all states through the shared `StatusIntent` helper; never `match` on an enum in a view.

## NOT TESTED

Nothing here is implemented. The provider is **not chosen** and gate `G-PAY-01` status is unknown, so `docs/planning/sprint-plan.md` OQ-5 blocks this spec. `payment-webhook.md` describes the contract shape but no sandbox has been exercised. The FIN-DEC approvals in `release-gates.md` §H are prerequisites and are not granted.

**Correction, 10 Aug 2026 (Wave 1b ruling 1b-L3-01):** the paragraph above is no longer accurate and is superseded on two points; the rest of it still holds. Appended rather than rewritten, per this repository's correction convention.

1. **"Nothing here is implemented" is false as of commit `afe45fc`.** What landed on lane `lane/l3-payment-adapter`:
   - `GuardPaymentSession` — the six-condition payment guard as a single Action, **deny-only**. Condition 1 (server-resolved `PaymentMode` via `ModeResolver::paymentMode()`, backed by `G-PAY-01`) is genuinely evaluated. Conditions 2-6 (confirmation/reservation, accepted quote, authorized opening, amount vs quote total, merchant + `badan_usaha` binding) each deny as `UnavailableUpstream`, naming the missing upstream, because no such record exists in this repository yet.
   - `payment_intents` and `payment_sessions` tables and models. Every guard evaluation writes a `payment_intents` decision record; denials also write audit `PAYMENT_GUARD_DENIED` with outcome `denied`.
   - **There is no reachable PASS outcome**, and therefore no caller can create a `payment_sessions` row. `PaymentIntentDecision` deliberately has no `Allowed` case and the Postgres CHECK admits only `'denied'`. This is a fail-closed guard, not a working payment path.
   - **Not** implemented, deliberately: `CreatePaymentSession`, the `PaymentProvider` contract, and all provider/HTTP code.
2. **The provider IS now chosen** — ADR-0033 selects the SumoPod sandbox for dev/staging. The `G-PAY-01`-status and FIN-DEC points still stand: the gate stays **closed for production**, the FIN-DEC approvals in `release-gates.md` §H remain ungranted, and no sandbox has been exercised yet.

Still genuinely NOT TESTED: the Postgres-only CHECK constraints (the local suite runs on SQLite, so they are unverified until CI), and every provider/webhook/manual-fallback/reversal surface, none of which exists yet.
