# Design — Platform Payment Adapter

## Module

`PaymentAdapter` (`overview.md` §5). Single boundary between domain workflows and the K3–K5 money path. Consumers request a session and observe state; they never talk to a provider directly.

## Payment guard

```text
product gate open
confirmation valid OR reservation active
quote accepted and not expired
admin/case permission
amount == quote total
merchant/badan_usaha bound
```

All six must hold. The guard is the only path to a payment session; a denial returns an explanatory result, never a silent no-op.

## Data

```text
payment_intents           -- guard evaluation + decision record
payment_sessions          -- provider session, merchant binding, amount snapshot
provider_events           -- raw durable webhook store, unique by provider event id
payment_verifications     -- manual fallback: reference, proof, verifier, decision
payment_reversals         -- refund / chargeback / reversal
```

`provider_events` is append-only and is the replay source of truth.

## Webhook sequence

```text
receive -> validate signature -> persist raw (unique key) -> ack <=2s
        -> enqueue -> idempotent apply -> outbox event -> journal entry
```

Duplicate delivery short-circuits at the unique key. Out-of-order delivery is resolved by provider event ordering, not arrival order.

## Manual fallback

Same state machine, different evidence. `MENUNGGU_VERIFIKASI_PEMBAYARAN` is a pending state and must never be rendered as success. Verification is an authorized action requiring recent re-authentication.

## Failure strategy

Provider unavailable at session creation returns a truthful pending or offers manual coordination when the mode allows. Never a dead end on the payment step.

## Observability

Blocked early-payment attempts, guard denial reasons, webhook validation failures, replay hits, ack latency, event-to-projection lag, manual verification queue age, fallback rate.
