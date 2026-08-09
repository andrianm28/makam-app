# Payment Webhook Contract

## Scope

This contract is an application-side requirement. Provider-specific signature, headers, retry schedule, and payload schema remain defined by K4.

## Processing pipeline

```text
HTTP received
-> durable raw event stored
-> signature and timestamp validated
-> merchant/badan_usaha validated
-> idempotency key checked
-> invoice and amount matched
-> journal requested/confirmed
-> domain state transitioned
-> invoice/notification emitted
-> reconciliation queued
```

## Required envelope

- provider event ID;
- provider transaction ID;
- event type;
- event timestamp;
- merchant or `badan_usaha` identifier;
- invoice/order reference;
- amount and currency;
- signature metadata;
- raw payload reference;
- processing status and trace ID.

## Idempotency

Primary key should use provider + event ID. A secondary guard should prevent the same provider transaction from settling multiple invoices.

Duplicate valid webhook returns a success acknowledgment and the original processing reference. It must not repeat journal, state, invoice, or notification effects.

## Security

- Verify provider signature using current and rotating secret support.
- Enforce acceptable timestamp skew if provider supports it.
- Do not trust query parameters or browser return URL as payment evidence.
- Store raw payload privately with retention policy TBD.
- Mask secrets and personal data in application logs.

## Failure states

```text
RECEIVED
VALIDATED
PROCESSING
PROCESSED
DUPLICATE
REJECTED_PAYLOAD
REJECTED_SIGNATURE
REJECTED_REPLAY
REJECTED_MERCHANT
REJECTED_SESSION
REJECTED_CURRENCY
REJECTED_AMOUNT
RETRYABLE_FAILURE
MANUAL_REVIEW
```

### Amendment — four states added 10 Aug 2026 (`platform-payment-adapter` Task 3)

`REJECTED_PAYLOAD`, `REJECTED_REPLAY`, `REJECTED_CURRENCY`, and `REJECTED_SESSION`
were added when the receiver was implemented. The original nine states are
unchanged and keep their meanings; nothing that referenced them is affected.

The reason is `platform-payment-adapter` AC6, which requires every webhook to be
validated against five distinct things — "signature, merchant scope, amount,
currency, and replay window" — and requires a failure to be *recorded*, not only
rejected. Three of those five had no state to be recorded under, and a rejection
filed under a status naming the wrong cause is not a usable record:

- `REJECTED_PAYLOAD` — the body could not be read as the envelope above, so
  nothing else could be checked. Also covers a body over the configured size cap,
  which is refused before anything is stored.
- `REJECTED_REPLAY` — the delivery was authentic but its signed timestamp fell
  outside the acceptable skew (this section's Security bullet). Distinguished from
  `REJECTED_SIGNATURE` only because the signature is verified *first*, so this
  state always means a genuine delivery arriving late, never a forgery.
- `REJECTED_CURRENCY` — the declared or session currency is not the configured
  currency.
- `REJECTED_SESSION` — no payment session is bound to the provider transaction,
  so the merchant/`badan_usaha` binding and the amount have nothing authoritative
  to be reconciled against. Under Wave 1b ruling 1b-L3-01 the payment guard is
  deny-only and no session can exist yet, so this is currently the terminal state
  of every otherwise-valid webhook — fail-closed by design.

The application-side enum is `App\Platform\Payment\ProviderEventStatus`. This
document remains the canonical list; the enum follows it.
