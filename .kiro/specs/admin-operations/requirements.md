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
12. THE SYSTEM SHALL provide within AC7's reporting two derived measures computed from existing timestamps only: median time from first booking-draft creation to order confirmation, and the ratio of paid invoices to issued invoices per period. THE SYSTEM SHALL NOT introduce analytics tables for them and SHALL NOT present targets until the stakeholder sets them.
13. THE SYSTEM SHALL make AC2's facility management select from `docs/product/facility-catalog.md`, and SHALL include in cemetery management the operator contact phone, operating-hours text, and the required-document override from `docs/product/required-document-catalog.md`. The operator panel SHALL read these fields in release 1 and SHALL NOT be required to edit them (ADR-0008).

## Amended (19 Sep 2026)

Acceptance criteria above the original count were added from the YIEM PRD
reconciliation (`docs/product/prd-yiem-2026-09-18.md` §16, decisions Q22, Q24, Q28, Q35, Q40). The PRD is a
stakeholder document subordinate to `docs/product/mvp-scope.md`; these
criteria are the repo-side approval of the decisions it records, in the
same shape `renewal-and-grave-registry/requirements.md`'s `## Superseded`
section uses. Existing numbering is untouched.
