# Design — Recurring Care Subscriptions

## Data

```text
subscriptions
subscription_cycles
subscription_invoices
subscription_payment_references
```

## Table ownership (normative)

This spec owns **billing only**: the four tables above. Work orders and evidence are **owned by `grave-care-fulfillment`** as `work_orders` / `work_evidence`; a paid cycle emits an intent that `grave-care-fulfillment` turns into a work order.

`care_work_orders` and `care_evidence` were previously listed here and are **removed** — they duplicated `work_orders` / `work_evidence` under different names, and `care_cycles` duplicated `subscription_cycles`. One concept, one table, one owner. Resolves `docs/planning/kiro-specs-analysis.md` §5.3.

This is what makes AC6 (billing, work scheduling, completion evidence, complaint, and make-good are separate states) enforceable rather than aspirational.

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
