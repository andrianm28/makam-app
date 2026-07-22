# ADR-0026: Require Performance Evidence Before Scaling Complexity

## Status
Accepted — 23 July 2026

## Decision
Benchmark representative 100k-search, 10k-import, queue isolation, payment webhook, and concurrent invariants before production. Add OpenSearch, Octane, brokers, read replicas, or service extraction only after measured bottlenecks and operational readiness.

## Consequences
Avoids premature complexity; capacity evidence becomes a release artifact.
