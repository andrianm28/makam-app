# Requirements — Booking and Order Orchestration

**Authority:** K25–K28 and Stakeholder Workflow MVP Steps 1–9.

## Acceptance criteria

1. Shared public entry presents the canonical nine-step workflow.
2. Draft is resumable, idempotent, versioned, and owned by one customer/token.
3. Step completion follows `booking-wizard-fields.md`.
4. Submission selects explicit product type and workflow.
5. At-Need creates/links a FuneralCase; Pre-Need creates/links a PreNeedCase/interest.
6. Payment requires valid confirmation/reservation, accepted active quote, and authorized opening.
7. If online payment gate is closed, Step 8 uses explicit manual-payment coordination without marking paid.
8. Quote is immutable after issue; revision creates a new version.
9. Valid webhook or approved manual verification creates paid/journal/invoice effects exactly once.
10. Customer/deceased documents are private, purpose-scoped, short-lived, malware-checked, and audited.
11. Commercial transitions are forward-only and separate from case/work/certificate states.
12. Operator silence preserves manual admin/case-manager fallback.
13. Step 9 provides order reference, status, invoice state, channel-delivery state, next action, and support.
14. Required admin/operator notifications follow the notification matrix.

## Negative criteria

- No paid state from browser return.
- No payment for expired reservation or closed gate.
- No loss of draft when changing step or provider failure.
- No internal branching that removes a stakeholder-required entry or leaves the user without confirmation.
