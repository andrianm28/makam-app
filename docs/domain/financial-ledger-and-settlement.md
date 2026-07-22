# Financial Ledger, Settlement, and Decision Register — v0.4

## Decision register

| ID | Decision | Owner | Gate | Status |
|---|---|---|---|---|
| FIN-DEC-01 | Merchant of record per product type | Business/legal/finance | Online payment | TBD |
| FIN-DEC-02 | Invoice issuer, numbering, tax | Finance/legal | Invoice production | TBD |
| FIN-DEC-03 | Fee/commission and revenue recognition | Finance | Marketplace settlement | TBD |
| FIN-DEC-04 | Cancellation/refund/dispute/chargeback policy | Business/legal/finance | Refund | TBD |
| FIN-DEC-05 | Vendor payable eligibility, hold, payout approval | Operations/finance | Vendor payout | TBD |
| FIN-DEC-06 | Reconciliation SLA and exception authority | Finance/operations | Online payment | TBD |
| FIN-DEC-07 | DP/partial-payment policy for At-Need | Business/finance | DP | TBD |
| FIN-DEC-08 | Pre-Need reserve/liability model | Legal/finance | Paid Pre-Need | GATED |

## Required shared-service interfaces

```text
PaymentService.createCheckout(merchant, amount, currency, reference, metadata)
WebhookHandler.validateAndStore(headers, payload)
Ledger.postBalancedBatch(entity, source, entries, idempotencyKey)
Reconciliation.run(entity, period)
RefundService.submit(transaction, allocation, reason)
PayoutService.submit(payable, destination, approval)
```

Implementations may differ, but domain code must receive stable normalized results and references.

## Required unique constraints

- provider + provider event ID;
- merchant + provider transaction reference;
- ledger source idempotency key;
- invoice issuer + invoice number;
- refund provider reference;
- payable allocation key;
- payout submission key;
- renewal grave + period;
- subscription + billing cycle.

## Financial data retention

Retention is controlled by legal/accounting policy. Financial records, journal references, approvals, and reconciliation evidence must not be deleted by ordinary user-data deletion workflows; personal fields should be minimized or tokenized where possible.
