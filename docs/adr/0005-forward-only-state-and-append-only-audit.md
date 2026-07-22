# ADR-0005: Forward-Only State and Append-Only Audit

- **Status:** Accepted from RKS

## Decision

Order transitions do not move backward. Corrections create a new reasoned status/event or financial compensating action. All operational decisions and sensitive accesses emit append-only audit records.

## Consequences

- Strong traceability and easier incident investigation.
- UI must expose correction workflows instead of arbitrary status editing.
- Reporting must understand compensating events.
