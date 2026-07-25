# Tasks — Platform Payment Adapter

- [ ] Implement server-resolved `PaymentMode` and expose it to consumers.
- [ ] Implement the six-condition payment guard as a single Action.
- [ ] Implement hosted-checkout session creation with merchant/amount binding.
- [ ] Implement durable webhook receiver: persist-then-ack within 2 seconds.
- [ ] Implement signature, merchant, amount, currency, and replay validation.
- [ ] Implement idempotent webhook application keyed on provider event identity.
- [ ] Implement manual fallback: reference, proof upload, pending state, admin verification.
- [ ] Require recent re-authentication on manual verification and payout actions.
- [ ] Write the balanced journal entry in the same transaction as the paid effect.
- [ ] Implement refund/chargeback/reversal as explicit non-destructive operations.
- [ ] Add guard tests: closed gate, expired quote, expired reservation, amount mismatch, unauthorized opening.
- [ ] Add webhook tests: bad signature, wrong merchant, wrong amount, duplicate, replay, out-of-order, dead dispatcher.
- [ ] Add tests proving no paid state can be reached from a browser return.

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
