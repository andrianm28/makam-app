# Requirements — Funeral Case Management

**Status:** Proposed P0, benchmark-derived; supports K25/K27 execution.

## User stories

- As a grieving family, I receive one accountable coordinator and clear next steps.
- As a case manager, I can manage tasks, deadlines, communications, appointments, vendors, and evidence.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference elsewhere in this spec and in other documents still points at the same requirement.

1. WHEN an At-Need or Urgent intake is accepted THE SYSTEM SHALL create one FuneralCase with urgency, area, owner, and deadlines.
2. THE SYSTEM SHALL apply task templates to a case based on its service type and package.
3. THE SYSTEM SHALL assign critical tasks a due time and an escalation path, and THE SYSTEM SHALL make overdue events observable.
4. WHEN a communication is recorded THE SYSTEM SHALL capture channel, participants, time, purpose, summary, and actor, and THE SYSTEM SHALL NOT store unnecessary sensitive content.
5. WHEN a case manager handover occurs THE SYSTEM SHALL preserve open tasks, deadlines, contacts, and the handover reason.
6. WHEN an operator or vendor is silent THE SYSTEM SHALL create escalation/fallback tasks rather than silently block.
7. THE SYSTEM SHALL require configured critical tasks and evidence to complete a case, and THE SYSTEM SHALL NOT complete a case on payment alone.
8. WHEN a family views the case timeline THE SYSTEM SHALL present an empathetic, simplified view, and THE SYSTEM SHALL NOT expose internal notes.
9. WHILE the area, hours, or capacity gate is unavailable THE SYSTEM SHALL reject or close Urgent requests.

## Negative criteria

- No orphan case without accountable owner.
- No skipped critical task without authorized waiver reason.
- No internal communication note exposed to customer by default.
