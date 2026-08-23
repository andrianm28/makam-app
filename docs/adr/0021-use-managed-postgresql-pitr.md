# ADR-0021: Use Managed PostgreSQL with PITR and Restore Tests

## Status
Accepted — 23 July 2026

## Decision
Production uses managed PostgreSQL 18 with encryption, backups, PITR, monitoring, restore-to-new-instance capability, and required extensions. Financial production targets managed HA/failover.

## Consequences
Higher infrastructure cost than self-hosted database, but materially lower data-loss and operational risk.

## Status update (23 Aug 2026)

**Superseded by `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`'s "Production graduation — single-host decision" section.** Production uses self-managed PostgreSQL on the shared dev/staging host instead of a managed provider with PITR — this ADR's original Decision (managed PostgreSQL 18 with PITR) is not the plan going forward. Left in place as the historical record of what was originally decided and why (materially lower data-loss/operational risk was the real tradeoff being weighed) — that reasoning remains true and is the real cost being accepted by the newer decision, not erased by it.
