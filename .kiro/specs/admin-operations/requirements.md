# Requirements — Admin Operations

**Authority:** K35 and Stakeholder Workflow MVP — Dashboard Admin.

## Acceptance criteria

1. Admin dashboard has modules for TPU/TPS, vendor, transaction, payment, order status, FAQ, and report.
2. Admin manages city, cemetery, package, class, service, facility, price/tariff, map point, and availability without deployment.
3. Admin manages vendor, product, variant, category, service area, and vendor status.
4. Admin manages booking, marketplace, and renewal order workflows with PIC assignment and audited communication.
5. Admin can view payment/transaction references and record outgoing/manual payment with proof.
6. Admin manages FAQ category, article, draft, preview, publish/unpublish, and ordering.
7. Admin reports orders, receipts, outgoing payments, vendor performance, and renewal by period where data exists.
8. Sensitive actions require dedicated authorization and audit.
9. Admin cannot bypass payment/state invariants through UI or bulk actions.
10. Export/report queries respect role and business-entity scope.
11. Dashboard includes exception queues for failed payment, missing operator response, vendor delay, and unmatched renewal.
