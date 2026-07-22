# ADR-0019: Use Transactional Outbox for Durable Domain Events

## Status
Accepted — 23 July 2026

## Decision
Persist critical business events in the same PostgreSQL transaction as aggregate changes, then publish asynchronously with at-least-once delivery and idempotent consumers.

## Consequences
Prevents lost post-commit events and improves replay/audit. Adds an outbox table, publisher, retention, and duplicate-consumer controls.
