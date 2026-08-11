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

### The shipped ledger seam does not match the line above — 11 Aug 2026

`Ledger.postBalancedBatch(entity, source, entries, idempotencyKey)` is **not** what
`lane/l4-financial-ledger` implemented, and it is not what `lane/l3-payment-adapter` codes
against. The real seam is `App\Platform\FinancialLedger\Contracts\Journal`, with two methods
rather than one — a `post(...)` that takes a business key, entity ref, source type, source id,
entries, correlation id, and occurred-at, and a separate `postReversal(...)` for corrections.
The interface file is the canonical signature and carries the four contract terms an
implementation must honour; they are not restated here.

Three differences matter to a consumer, not just to a reader:

1. **Corrections are a distinct method**, not a `postBalancedBatch` call with negated entries.
2. **`post()` opens no transaction**, so the caller must supply one; the interface says in
   writing that it cannot enforce this.
3. **The database is the balance authority**, so a consumer must expect a balance failure and a
   duplicate-key failure to surface as database errors, not as an argument-validation error
   raised by the seam. A consumer test double that throws its own exception type teaches a false
   model — this was found and escalated between the two lanes rather than fixed on either side.

Neither line is retired here. Whoever owns this contract has to decide whether the required
interface above is amended to the shipped shape or the shipped shape is wrong; recording the
divergence is not that decision.

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

### Which of those actually exist — 11 Aug 2026

Verified against the migrations on branch `lane/l4-financial-ledger` at `713442f`. The list
above is the requirement and is unchanged; this is its status, and "absent" here means absent
from the whole repository, not merely from the ledger module.

| Required constraint | Status |
|---|---|
| provider + provider event ID | **Absent.** No provider-event table exists. Owner: `platform-payment-adapter`. |
| merchant + provider transaction reference | **Absent.** No provider-transaction table exists. Owner: `platform-payment-adapter`. |
| ledger source idempotency key | **Present.** `journal_batches.business_key` is UNIQUE, with a companion CHECK requiring a source prefix, in `database/migrations/2026_08_09_110000_create_journal_batches_table.php`. |
| invoice issuer + invoice number | **Absent.** No invoice table exists, so §9's numbering-and-audit requirement has nothing to constrain. |
| refund provider reference | **Absent.** No refund object exists; the ledger can post a refund-shaped reversing batch and nothing more. |
| payable allocation key | **Present in effect, under a different name.** `vendor_payables` is UNIQUE on `(vendor_id, source_type, source_id)` in `database/migrations/2026_08_09_120000_create_vendor_payables_table.php`, which is the allocation key for the one allocation rule that exists. It will need revisiting if `FIN-DEC-05` admits several payables per vendor per source. |
| payout submission key | **Partial, deliberately.** `payouts.payable_id` and `payouts.journal_business_key` are both UNIQUE in `database/migrations/2026_08_09_120100_create_payouts_table.php`. There is no *provider submission* key because there is no provider submission: `G-PAYOUT-01` is closed and the module has no automated transfer path. |
| renewal grave + period | **Absent.** `grave_records` exists; no renewal-period table does. Owner: `renewal-and-grave-registry`. |
| subscription + billing cycle | **Absent.** Owner: `recurring-care-subscriptions`. |

Three constraints the shipped module enforces are **not** on the list above, and two of them are
permanent financial policy rather than hygiene, so they are named here rather than left to be
discovered: `journal_batches.reverses_batch_id` is UNIQUE, which makes "a batch may be reversed
at most once, ever" a database-level rule; `reconciliations` is UNIQUE on
`(entity_ref, period)`; and `reconciliation_exceptions` is UNIQUE on its natural key plus a
version. Adding them to the required list is a decision for this register's owner.

## Financial data retention

Retention is controlled by legal/accounting policy. Financial records, journal references, approvals, and reconciliation evidence must not be deleted by ordinary user-data deletion workflows; personal fields should be minimized or tokenized where possible.
