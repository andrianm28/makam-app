# Requirements — Public Booking Wizard

**Authority:** Stakeholder Workflow MVP — Pemesanan Makam Steps 1–9.

## Acceptance criteria

1. Public booking shows nine numbered steps with labels from `booking-wizard-fields.md`.
2. Step 1 lists Jakarta, Bogor, Depok, Tangerang, and Bekasi.
3. Step 2 shows TPU/TPS type, name, photo, address, Google Maps navigation, facilities, price, and availability.
4. Step 3 offers Makam Baru, Makam Tumpang when supported, Urgent, and Pre-Need.
5. Step 4 offers all basic and additional services in `service-catalog.md`.
6. Step 5 shows immutable quote line items and total.
7. Step 6 captures required customer data and privacy consent.
8. Step 7 captures deceased data and KTP/KK/death-certificate uploads privately.
9. Step 8 supports online payment when gate open and explicit manual fallback otherwise.
10. Step 9 shows order reference, status, invoice, email/WhatsApp delivery state, next step, and support.
11. Every step autosaves and can be resumed.
12. Back/forward navigation preserves valid data.
13. Server prevents skipping required steps and stale quote/payment.
14. Urgent and Pre-Need may branch internally but keep a clear progress/outcome consistent with the nine-step entry.
15. Admin/operator notification is created at the required workflow event.
