# Requirements — Admin Operations

**Authority:** K35 and Stakeholder Workflow MVP — Dashboard Admin.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC4`, `AC6`, `AC8` in `tasks.md`) and in other documents still points at the same requirement.

1. THE SYSTEM SHALL provide an admin dashboard with modules for TPU/TPS, vendor, transaction, payment, order status, FAQ, and report.
2. THE SYSTEM SHALL allow an admin to manage city, cemetery, package, class, service, facility, price/tariff, map point, and availability without deployment.
3. THE SYSTEM SHALL allow an admin to manage vendor, product, variant, category, service area, and vendor status.
4. THE SYSTEM SHALL allow an admin to manage booking, marketplace, and renewal order workflows with PIC assignment and audited communication.
5. THE SYSTEM SHALL allow an admin to view payment/transaction references and record outgoing/manual payment with proof.
6. THE SYSTEM SHALL allow an admin to manage FAQ category, article, draft, preview, publish/unpublish, and ordering.
7. THE SYSTEM SHALL allow an admin to report on orders, receipts, outgoing payments, vendor performance, and renewal by period where data exists.
8. THE SYSTEM SHALL require dedicated authorization and audit for sensitive actions.
9. THE SYSTEM SHALL NOT allow an admin to bypass payment/state invariants through UI or bulk actions.
10. THE SYSTEM SHALL scope export/report queries to the requesting admin's role and business-entity permissions.
11. THE SYSTEM SHALL include dashboard exception queues for failed payment, missing operator response, vendor delay, and unmatched renewal.
