# Requirements — At-Need Booking

**Authority:** K25/K27; refined by local benchmark patterns.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC4`, `AC6`, `AC8` in `tasks.md`) and in other documents still points at the same requirement.

1. WHEN a user begins at-need intake THE SYSTEM SHALL request only the minimum data: contact, deceased identity where available, current location, desired service area/time, and urgency.
2. WHILE a service area is accepted THE SYSTEM SHALL keep a human contact route visible.
3. WHEN intake is submitted THE SYSTEM SHALL create a FuneralCase before requesting noncritical data.
4. WHILE operational/legal policy approves post-service document completion THE SYSTEM SHALL allow required documents to be completed after service.
5. THE SYSTEM SHALL support availability/plot decisions through manual confirmation or authoritative reservation.
6. WHEN a quote is generated THE SYSTEM SHALL display land/package, funeral service, add-ons, delivery/transport, and exclusions.
7. THE SYSTEM SHALL follow approved policy for payment timing. THE SYSTEM SHALL NOT imply a down-payment or partial-payment requirement that is not part of that policy.
8. WHEN a service time/address or transport milestone occurs THE SYSTEM SHALL record it.
9. WHEN a case is completed THE SYSTEM SHALL deliver eligible receipt, evidence, and certificate status as separate items.

## Negative criteria

- No long Pre-Need wizard imposed on urgent family.
- No promise of same-day service outside configured capacity.
