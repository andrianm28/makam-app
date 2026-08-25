# Design — Grave Care Fulfillment

Entities: `care_plans`, `work_orders`, `work_order_tasks`, `work_evidence`, `service_acceptances`, `service_complaints`, `make_good_orders`.

## Table ownership (normative)

This spec owns **fulfilment**: the seven tables above. Billing is owned by `recurring-care-subscriptions` (`subscriptions`, `subscription_cycles`, `subscription_invoices`, `subscription_payment_references`); this spec consumes a paid-cycle intent and must not define or migrate billing tables.

`care_cycles` is **removed** from this list — it duplicated `subscription_cycles`. One-off (non-subscription) care attaches a `work_order` directly to a `care_plan`, so no second cycle table is needed. Resolves `docs/planning/kiro-specs-analysis.md` §5.3.

Schedulers use cycle idempotency. Evidence processing uses private file adapter. Compensation/refund integrates with approved financial process.
