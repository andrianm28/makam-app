# Requirements — Grave Care Fulfillment

**Authority:** K29/K33; benchmark refinement.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. WHEN one-off or recurring care is scheduled THE SYSTEM SHALL create an explicit care cycle/work order.
2. THE SYSTEM SHALL keep billing state separate from work order status.
3. THE SYSTEM SHALL include in each work order a schedule, assignee/vendor, service checklist, and evidence requirements.
4. THE SYSTEM SHALL keep before/after evidence private to authorized parties unless it is explicitly published.
5. THE SYSTEM SHALL allow the customer to accept, complain, or request make-good, according to policy.
6. WHEN a service is failed or missed THE SYSTEM SHALL NOT alter payment history, and THE SYSTEM SHALL create an operational/financial exception.
7. WHEN a vendor is replaced or a service is rescheduled THE SYSTEM SHALL record an audit entry.
8. THE SYSTEM SHALL NOT create a duplicate invoice or work order for one care cycle under retries.
