# ADR-0025: Use Immutable Builds and Expand/Contract Database Delivery

## Status
Accepted — 23 July 2026

## Decision
CI builds an immutable artifact from lockfiles. Production migrations are forward-compatible expand/backfill/switch/contract changes; rollback does not depend on destructive down migrations.

## Consequences
Safer rollbacks and reduced downtime, with more deliberate multi-release schema changes.
