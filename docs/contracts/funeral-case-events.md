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
