# Requirements — Public Booking Wizard

**Authority:** Stakeholder Workflow MVP — Pemesanan Makam Steps 1–9.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL show nine numbered steps, with labels from `booking-wizard-fields.md`, in the public booking flow.
2. THE SYSTEM SHALL list Jakarta, Bogor, Depok, Tangerang, and Bekasi in Step 1.
3. THE SYSTEM SHALL show TPU/TPS type, name, photo, address, Google Maps navigation, facilities, price, and availability in Step 2.
4. THE SYSTEM SHALL offer Makam Baru, Makam Tumpang (when supported), Urgent, and Pre-Need in Step 3.
5. THE SYSTEM SHALL offer all basic and additional services in `service-catalog.md` in Step 4.
6. THE SYSTEM SHALL show immutable quote line items and total in Step 5.
7. THE SYSTEM SHALL capture required customer data and privacy consent in Step 6.
8. THE SYSTEM SHALL capture deceased data and KTP/KK/death-certificate uploads privately in Step 7.
9. WHILE the online-payment gate is open THE SYSTEM SHALL support online payment in Step 8; WHILE the gate is closed THE SYSTEM SHALL provide an explicit manual fallback in Step 8.
10. THE SYSTEM SHALL show order reference, status, invoice, email/WhatsApp delivery state, next step, and support in Step 9.
11. THE SYSTEM SHALL autosave every step and allow it to be resumed.
12. WHEN a user navigates back or forward THE SYSTEM SHALL preserve valid data.
13. THE SYSTEM SHALL NOT allow a required step to be skipped or a stale quote or stale payment to be accepted.
14. WHILE the Urgent or Pre-Need flow branches internally THE SYSTEM SHALL keep a clear progress/outcome consistent with the nine-step entry.
15. WHEN a required workflow event occurs THE SYSTEM SHALL create an admin/operator notification.
