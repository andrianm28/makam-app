# Design — Booking and Order Orchestration

## Shared components

Draft service, product-type router, quote versioning, order state machine, document adapter, payment adapter.

## Payment guard

```text
product gate open
confirmation valid OR reservation active
quote accepted and not expired
admin/case permission
amount == quote total
```

## Data

`booking_drafts`, `orders`, `order_parties`, `deceased_profiles`, `order_documents`, `quotes`, `quote_lines`, `order_status_events`, workflow references.

## Observability

Draft errors, time-to-submit, quote revisions, blocked early-payment attempts, webhook outcomes, and fallback rates.
