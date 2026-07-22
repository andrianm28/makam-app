# Design — Grave Care Fulfillment

Entities: `care_plans`, `care_cycles`, `work_orders`, `work_order_tasks`, `work_evidence`, `service_acceptances`, `service_complaints`, `make_good_orders`.

Schedulers use cycle idempotency. Evidence processing uses private file adapter. Compensation/refund integrates with approved financial process.
