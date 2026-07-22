# Requirements — Renewal and Grave Registry

**Authority:** K31–K32 and Stakeholder Workflow MVP.

## Acceptance criteria

1. Public flow visibly implements six steps: city, TPU/TPS, grave search, fee, payment, confirmation/invoice.
2. City selection includes the five MVP launch areas.
3. Search supports fuzzy deceased name, block, and death date.
4. Search latency target is below 500 ms at 100,000 records.
5. Empty result provides honest manual-entry/customer-service path when allowed.
6. Tariff displays amount, source, and last update time.
7. Platform does not calculate late fine without written operator basis.
8. Payment supports online mode or explicit manual fallback.
9. Confirmation shows renewal reference, status, invoice state, and resulting due date when available.
10. Admin/operator can mark external renewal/payment with evidence.
11. Duplicate renewal for the same grave period is prevented.
12. Grave records include deceased name, location, block, death date, due date, and heir contact.
13. Import validates up to 10,000 rows asynchronously and reports row-level errors.
14. Search access mode can be open, limited, or closed.
15. Reminder is idempotent: exactly one per grave per window.
16. Search/reminder feature is disabled with explanation when data gate closed.
