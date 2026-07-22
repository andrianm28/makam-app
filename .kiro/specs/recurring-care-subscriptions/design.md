# Design — Recurring Care Subscriptions

## Data

```text
subscriptions
subscription_cycles
subscription_invoices
subscription_payment_references
care_work_orders
care_evidence
```

## Idempotency

Unique constraint:

```text
subscription_id + cycle_start + cycle_end
```

Scheduler uses the same deterministic key when retried.

## Statuses

```text
Subscription: DRAFT -> ACTIVE -> PAUSED -> ENDED -> CANCELLED
Cycle: SCHEDULED -> INVOICED -> PAID -> WORK_SCHEDULED -> COMPLETED
                         -> EXPIRED
```

`ACTIVE` and `PAID` cannot be inferred from notification or browser return.

## Tokenization

Only provider token references may be stored. Raw PAN/CVV or sensitive card data is prohibited.
