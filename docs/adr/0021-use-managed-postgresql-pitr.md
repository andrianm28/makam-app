# ADR-0021: Use Managed PostgreSQL with PITR and Restore Tests

## Status
Accepted — 23 July 2026

## Decision
Production uses managed PostgreSQL 18 with encryption, backups, PITR, monitoring, restore-to-new-instance capability, and required extensions. Financial production targets managed HA/failover.

## Consequences
Higher infrastructure cost than self-hosted database, but materially lower data-loss and operational risk.
