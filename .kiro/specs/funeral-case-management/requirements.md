# Requirements — Funeral Case Management

**Status:** Proposed P0, benchmark-derived; supports K25/K27 execution.

## User stories

- As a grieving family, I receive one accountable coordinator and clear next steps.
- As a case manager, I can manage tasks, deadlines, communications, appointments, vendors, and evidence.

## Acceptance criteria

1. Accepted At-Need/Urgent intake creates one FuneralCase with urgency, area, owner, and deadlines.
2. Case has task templates by service type and package.
3. Critical tasks have due time and escalation; overdue events are observable.
4. Communications record channel, participants, time, purpose, summary, and actor without storing unnecessary sensitive content.
5. Case manager handover preserves open tasks, deadlines, contacts, and reason.
6. Operator/vendor silence creates escalation/fallback tasks rather than silent blocking.
7. Case completion requires configured critical tasks/evidence; payment alone cannot complete the case.
8. Family sees an empathetic, simplified timeline, not internal notes.
9. Urgent is rejected/closed when area, hours, or capacity gate is unavailable.

## Negative criteria

- No orphan case without accountable owner.
- No skipped critical task without authorized waiver reason.
- No internal communication note exposed to customer by default.
