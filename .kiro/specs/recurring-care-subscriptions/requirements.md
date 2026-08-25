# Requirements — Recurring Care Subscriptions

**Authority:** K33. Fulfillment behavior is detailed in `grave-care-fulfillment`.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. THE SYSTEM SHALL support monthly, quarterly, six-month, and annual care plans.
2. THE SYSTEM SHALL create at most one invoice per subscription cycle.
3. WHILE a provider gate is active THE SYSTEM SHALL follow that gate to determine payment-link or auto-charge behavior.
4. THE SYSTEM SHALL NOT change a paid subscription's or cycle's status except in response to a validated webhook.
5. THE SYSTEM SHALL create at most one care work order per paid or eligible cycle.
6. THE SYSTEM SHALL represent billing, work scheduling, completion evidence, complaint, and make-good as separate states.
7. THE SYSTEM SHALL NOT apply cancellation, pause, failed-payment, grace/dunning, or price-change behavior until the corresponding policy is explicitly configured.
8. THE SYSTEM SHALL NOT store a raw payment instrument.
