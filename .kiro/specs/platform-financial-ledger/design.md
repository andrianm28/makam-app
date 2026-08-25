# Design — Platform Financial Ledger and Settlement

## Module

Owns the money record. `PaymentAdapter` reports what a provider did; this module records what it means financially. Order workflows read projections, never raw ledger rows.

## Data

```text
journal_batches      -- id, occurred_at, entity_ref (badan_usaha), business_key (unique),
                        source_type, source_id, correlation_id, status
journal_entries      -- batch_id, account, direction (DR/CR), amount_minor, currency
vendor_payables      -- vendor, source order, eligible_at, amount_minor, state
payouts              -- payable refs, method, proof, approver, provider_ref, state
reconciliations      -- period, provider statement ref, status
reconciliation_exceptions -- type, amounts, decision, decided_by, decided_at
```

`amount_minor` is an integer in the currency's minor unit. No float, no decimal string arithmetic in application code.

## Balance enforcement

A batch is inserted with its entries in one statement group, and a database-level check (constraint trigger or deferred constraint) rejects the batch unless `SUM(DR) = SUM(CR)`. Enforcement lives in the database so no application path can bypass it.

## Idempotency

`journal_batches.business_key` is unique — for example `payment:{provider_event_id}` or `renewal:{grave_record_id}:{period}`. A retried webhook collides on the key and posts nothing, which is what makes at-least-once delivery safe.

## Corrections

```text
post batch B1
discover error
post batch B2 (reversal, references B1)
post batch B3 (correct entries)
```

B1 is never touched. This is what makes rollback safe: schema and history stay forward-compatible.

## Payable and payout separation

`DIBAYAR` creates a customer-side journal effect. Vendor payable eligibility is a **separate** rule (fulfilment evidence, dispute window). Payout is a third step. Three states, never one — this is the ledger expression of `AGENTS.md` *"Paid does not mean completed."*

## Reconciliation

Scheduled comparison of journal against provider settlement. Differences become `reconciliation_exceptions` requiring an authorized decision. The system never adjusts the ledger to make a statement match.

## Observability

Unbalanced-batch rejections (should be zero), duplicate business-key hits, payable ageing, payout queue age, open exception count and age, reconciliation coverage per period.
