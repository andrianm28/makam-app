# ADR-0003: Manual Confirmation Before Payment

- **Status:** Accepted from RKS

## Decision

A payment session may only be created after availability is manually confirmed, a quote is issued, and the customer accepts the active quote. Admin explicitly opens the payment gate.

## Consequences

- Reduces collecting money for unavailable service.
- Adds operational latency and admin workload.
- Requires auditable confirmation evidence and timeout/escalation monitoring.
