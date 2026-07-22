# ADR-0012: Case Manager Owns Urgent Fulfillment

## Status
Accepted

## Decision
Every accepted Urgent/At-Need request has an accountable case manager and operational tasks, deadlines, communications, and escalation.

## Consequences
`order.status` cannot be used as the sole operational model. Staffing and capacity gates are required.
