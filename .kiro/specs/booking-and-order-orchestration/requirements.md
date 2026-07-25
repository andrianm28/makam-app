# Requirements — Booking and Order Orchestration

**Authority:** K25–K28 and Stakeholder Workflow MVP Steps 1–9.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec (`AC4`, `AC6`, `AC8` in `tasks.md`) and in other documents still points at the same requirement.

1. THE SYSTEM SHALL present the canonical nine-step workflow through the shared public entry point.
2. THE SYSTEM SHALL make each booking draft resumable, idempotent, versioned, and owned by exactly one customer/token.
3. WHEN a step is completed THE SYSTEM SHALL follow the field rules defined in `booking-wizard-fields.md`.
4. WHEN a booking is submitted THE SYSTEM SHALL select an explicit product type and workflow.
5. WHEN an At-Need submission occurs THE SYSTEM SHALL create or link a FuneralCase. WHEN a Pre-Need submission occurs THE SYSTEM SHALL create or link a PreNeedCase/interest.
6. WHEN payment is initiated THE SYSTEM SHALL require a valid confirmation/reservation, an accepted active quote, and an authorized opening.
7. WHILE the online payment gate is closed THE SYSTEM SHALL use explicit manual-payment coordination at Step 8, and THE SYSTEM SHALL NOT mark the order as paid.
8. THE SYSTEM SHALL NOT modify a quote after it is issued. WHEN a quote is revised THE SYSTEM SHALL create a new version.
9. WHEN a valid webhook or an approved manual verification occurs THE SYSTEM SHALL create the paid/journal/invoice effects exactly once.
10. THE SYSTEM SHALL keep customer/deceased documents private, purpose-scoped, short-lived, malware-checked, and audited.
11. THE SYSTEM SHALL keep commercial transitions forward-only and separate from case/work/certificate states.
12. WHILE an operator has not responded THE SYSTEM SHALL preserve the manual admin/case-manager fallback.
13. WHEN a customer reaches Step 9 THE SYSTEM SHALL provide order reference, status, invoice state, channel-delivery state, next action, and support.
14. WHEN a required admin/operator notification is triggered THE SYSTEM SHALL follow the notification matrix.

## Negative criteria

- No paid state from browser return.
- No payment for expired reservation or closed gate.
- No loss of draft when changing step or provider failure.
- No internal branching that removes a stakeholder-required entry or leaves the user without confirmation.
