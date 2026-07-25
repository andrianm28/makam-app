# Requirements — Funeral Marketplace and Vendor Portal

**Authority:** K29–K30 and Stakeholder Workflow MVP.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL include in the MVP catalog: flower board, flower-petal package, granite gravestone, marble gravestone, calligraphy gravestone, and grave-care plans for monthly, three-month, six-month, and annual periods.
2. THE SYSTEM SHALL support, for each catalog entry, product/package, variant, photo, price, stock/availability, service area, schedule, delivery fee, and evidence requirement.
3. THE SYSTEM SHALL guide the user through browse/select, cart, checkout, payment or manual fallback, vendor processing, and status/evidence in sequence.
4. WHILE the MVP constraint is in effect THE SYSTEM SHALL allow at most one vendor per checkout, and THE SYSTEM SHALL make the constraint and the separate-checkout behavior explicit to the user.
5. THE SYSTEM SHALL provide vendors a dedicated, authenticated panel.
6. WHEN a vendor uses the panel THE SYSTEM SHALL allow the vendor to manage its own products, variants, prices, stock/availability, service areas, and calendar.
7. WHEN an order is assigned to a vendor THE SYSTEM SHALL let the vendor receive the order, accept or reject it where allowed, update its status, and upload work/delivery evidence.
8. THE SYSTEM SHALL let a vendor view transaction history and payout/reference status for its own records.
9. THE SYSTEM SHALL enforce query-level authorization, and THE SYSTEM SHALL NOT allow cross-vendor access.
10. WHEN a payment or payable is created THE SYSTEM SHALL reference the correct `badan_usaha` and vendor allocation.
11. WHILE the auto-payout gate is closed THE SYSTEM SHALL record manual payout proof, approvals, amount, and an audit trail.
12. THE SYSTEM SHALL NOT treat a paid vendor order as fulfillment complete.
13. THE SYSTEM SHALL show the customer the current vendor-processing status.
14. THE SYSTEM SHALL NOT enable multi-vendor checkout until it supports order splitting, partial cancellation/refund, fee/tax allocation, dispute handling, and reconciliation.
15. THE SYSTEM SHALL NOT include a land/plot rights marketplace unless independently approved.
