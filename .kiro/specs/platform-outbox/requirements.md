# Requirements — Platform Transactional Outbox

**Authority:** ADR-0019; `docs/architecture/queue-and-outbox.md`; `docs/contracts/outbox-event-contract.md`; `docs/contracts/event-catalog.md`; `AGENTS.md` §Queue and event reliability.

**Status:** Foundation P0. `AGENTS.md` makes this **mandatory** for critical domain events, yet the word `outbox` appeared **0 times** anywhere under `.kiro/` before this spec — see `docs/planning/kiro-specs-analysis.md` §2.3.

## Acceptance criteria

1. Critical domain events are inserted into the outbox **in the same database transaction** as the state mutation. A committed mutation with no outbox row is a defect.
2. Events use the versioned envelope in `outbox-event-contract.md`: event id, type, version, occurred-at, aggregate reference, correlation id, and payload.
3. Event types come from `event-catalog.md`. This spec implements the catalogue and does not restate it.
4. Publication is at-least-once. Consumers are therefore **idempotent by contract**, keyed on event id.
5. A publisher claim is atomic; two concurrent publishers never publish the same event twice.
6. Publication failure is retried with bounded backoff and never loses the event; the row survives until published.
7. Events carry no restricted data — references only, same rule as audit and notification payloads.
8. Queue routing follows the names and priorities in `queue-and-outbox.md`. `imports`, `media`, and `reports` must never starve `critical` or `urgent`.
9. Critical queue wait stays within 10 seconds and urgent within 15 seconds, per `performance-and-capacity.md` §3.
10. Consumer failure does not roll back the producing transaction; the event remains available for retry.
11. Replay is possible from the outbox for a bounded window, and replay is safe because consumers are idempotent.
12. Outbox depth, age, and publication lag are observable and alertable.
13. Correlation id is propagated from the request into the event and onward into queue jobs, provider calls, and notifications.

## Negative criteria

- No critical event dispatched inline with the mutation, bypassing the outbox.
- No event published twice under concurrent publishers or retries.
- No event loss on dispatcher crash between commit and publish.
- No restricted data in an event payload.
- No batch job starving the critical or urgent queue.
