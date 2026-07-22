# Requirements — Funeral Marketplace and Vendor Portal

**Authority:** K29–K30 and Stakeholder Workflow MVP.

## Acceptance criteria

1. MVP catalog contains flower board, flower-petal package, granite gravestone, marble gravestone, calligraphy gravestone, and grave-care plans for monthly, three-month, six-month, and annual periods.
2. Catalog supports product/package, variant, photo, price, stock/availability, service area, schedule, delivery fee, and evidence requirement.
3. User flow is browse/select → cart → checkout → payment/manual fallback → vendor processing → status/evidence.
4. Initial checkout may be one vendor per checkout; constraint and separate-checkout behavior are explicit.
5. Vendor has a dedicated authenticated panel.
6. Vendor manages own products, variants, prices, stock/availability, service areas, and calendar.
7. Vendor receives assigned orders, accepts/rejects where allowed, updates status, and uploads work/delivery evidence.
8. Vendor can view transaction history and payout/reference status for own records.
9. Query-level authorization prevents cross-vendor access.
10. Payment/payable reference the correct `badan_usaha` and vendor allocation.
11. Manual payout records proof, approvals, amount, and audit until auto-payout gate opens.
12. Paid vendor order does not mean fulfillment complete.
13. Customer sees current vendor-processing status.
14. Multi-vendor enablement requires splitting, partial cancellation/refund, fee/tax allocation, dispute, and reconciliation behavior.
15. Land/plot rights marketplace is excluded unless independently approved.
