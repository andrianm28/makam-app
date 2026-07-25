# Requirements — Platform Transactional Outbox

**Authority:** ADR-0019; `docs/architecture/queue-and-outbox.md`; `docs/contracts/outbox-event-contract.md`; `docs/contracts/event-catalog.md`; `AGENTS.md` §Queue and event reliability.

**Status:** Foundation P0. `AGENTS.md` makes this **mandatory** for critical domain events, yet the word `outbox` appeared **0 times** anywhere under `.kiro/` before this spec — see `docs/planning/kiro-specs-analysis.md` §2.3.

## Acceptance criteria

EARS notation ([kiro.dev/docs/specs](https://kiro.dev/docs/specs/feature-specs/)), added 25 Jul 2026. Numbering is unchanged from the previous plain-list form, so every existing cross-reference to these criteria in other documents still points at the same requirement.

1. WHEN a critical domain event occurs THE SYSTEM SHALL insert it into the outbox in the same database transaction as the state mutation. A committed mutation with no outbox row is a defect.
2. THE SYSTEM SHALL use the versioned envelope defined in `outbox-event-contract.md` for every event: event id, type, version, occurred-at, aggregate reference, correlation id, and payload.
3. THE SYSTEM SHALL use event types from `event-catalog.md` and SHALL NOT restate the catalogue.
4. THE SYSTEM SHALL publish events at-least-once. Consumers SHALL be idempotent by contract, keyed on event id.
5. THE SYSTEM SHALL make a publisher's claim on an event atomic. THE SYSTEM SHALL NOT let two concurrent publishers publish the same event twice.
6. WHEN publication fails THE SYSTEM SHALL retry with bounded backoff. THE SYSTEM SHALL NOT lose the event; the row SHALL survive until published.
7. THE SYSTEM SHALL NOT include restricted data in an event — references only, same rule as audit and notification payloads.
8. THE SYSTEM SHALL route queues per the names and priorities in `queue-and-outbox.md`. THE SYSTEM SHALL NOT let `imports`, `media`, or `reports` starve `critical` or `urgent`.
9. THE SYSTEM SHALL keep critical queue wait within 10 seconds and urgent queue wait within 15 seconds, per `performance-and-capacity.md` §3.
10. WHEN a consumer fails THE SYSTEM SHALL NOT roll back the producing transaction; the event SHALL remain available for retry.
11. THE SYSTEM SHALL support replay from the outbox for a bounded window; replay SHALL be safe because consumers are idempotent.
12. THE SYSTEM SHALL make outbox depth, age, and publication lag observable and alertable.
13. THE SYSTEM SHALL propagate correlation id from the request into the event and onward into queue jobs, provider calls, and notifications.

## Negative criteria

- No critical event dispatched inline with the mutation, bypassing the outbox.
- No event published twice under concurrent publishers or retries.
- No event loss on dispatcher crash between commit and publish.
- No restricted data in an event payload.
- No batch job starving the critical or urgent queue.
