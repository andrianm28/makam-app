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
PROCESSED
DUPLICATE
REJECTED_SIGNATURE
REJECTED_MERCHANT
REJECTED_AMOUNT
RETRYABLE_FAILURE
MANUAL_REVIEW
```
