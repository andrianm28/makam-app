# Funeral Case Event Catalog

| Event | Trigger | Consumers |
|---|---|---|
| `FuneralCaseCreated.v1` | At-Need intake accepted | Operations, notification |
| `CaseManagerAssigned.v1` | Owner assigned/changed | Operations, audit |
| `CriticalTaskOverdue.v1` | Deadline missed | Escalation |
| `AvailabilityConfirmed.v1` | Manual or authoritative evidence | Quote workflow |
| `PlotReservationAcquired.v1` | Atomic hold succeeds | Case/quote |
| `QuoteAccepted.v1` | Customer accepts version | Payment gate |
| `ServiceStarted.v1` | Field work begins | Customer notification |
| `FuneralCaseCompleted.v1` | Required tasks/evidence complete | Reporting/certificate |
| `FuneralCaseEscalated.v1` | Operational risk | On-call/admin |

Events contain case ID, actor, occurred time, trace ID, and versioned payload. No restricted document content in event payload.

### Amendment — `actor` is reserved-and-null in this version (07 Sep 2026, Batch M4b)

"actor" above describes the envelope shape, not current behaviour: every
outbox event (these funeral-case events included) is published with a null
`actor.type`/`actor.id`, per `outbox-event-contract.md`'s own amendment of
the same date. `App\Platform\Outbox\Outbox::record()` has no actor
parameters and `outbox_events` has no actor columns — a deliberate decision
(finding N-11, `docs/planning/sprint-plan.md`), not an oversight introduced
here.

Attribution is available via `audit_events`: every producer of a funeral-case
event pairs its `Outbox::record()` call with an `Audit::record()`/`Audit::wrap()`
call in the same transaction, carrying a real `actor_ref`/`actor_role`. A
consumer needing "who did this" should join `audit_events` on the same
aggregate/correlation id rather than expect it inside the event payload.

Populating `actor` on the outbox envelope for real is a schema/behaviour
change (new `outbox_events` columns, `ActorContext` threaded through
`Outbox::record()`) and needs human sign-off per AGENTS.md §Infrastructure-agent
execution — not done here; flagged as an available future change.
