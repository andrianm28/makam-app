# ADR-0022: Standardize Production Observability

## Status
Accepted — 23 July 2026

## Decision
Use structured logs, external error tracking, Horizon, Pulse, uptime/synthetic monitoring, managed DB/Redis metrics, append-only audit, and financial exception reporting with correlation IDs.

## Consequences
Faster incident diagnosis and measurable release gates; requires PII scrubbing, retention, and dashboard authorization.
