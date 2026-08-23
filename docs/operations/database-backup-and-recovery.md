# Managed PostgreSQL, Backup, PITR, and Recovery — v0.5

## Production backup strategy correction (23 Aug 2026)

This document's original premise (§1–§8 below) is a managed-PostgreSQL-with-PITR target for
production, with §9 ("Non-production combined-host policy") treating the shared `yiemvm` host as
non-production only. Per [`ADR-0027`](../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md)'s
"Production graduation — single-host decision" section (23 Aug 2026), production now runs on this
same shared host, using self-managed PostgreSQL 18 — not a managed provider, and not PITR. This
document's former line 88 ("The Ubuntu 22.04 2/4 combined host does not provide production PITR
guarantees") and former line 95 ("Production recovery objectives remain governed by Sections 1–8
and require managed PostgreSQL/PITR") both described that OLD position; §9 below is corrected
accordingly. `ADR-0021` (the original managed-PostgreSQL/PITR decision) is superseded by the same
`ADR-0027` section.

The real, current production backup strategy is the one
[`ADR-0035`](../adr/0035-beta-launch-accepted-risks.md) item 2 already established and ran for
beta: frequent (4–6 hourly) encrypted `pg_dump` snapshots, with a documented, tested restore into a
scratch database — a backup is not considered valid until restored (§4 below already states this
rule; it now governs production, not staging alone). This is not a new strategy invented for this
correction — it is the same one already proven for beta, extended to production per `ADR-0035`
item 2's own 23 Aug 2026 update.

§1–§8's managed-PostgreSQL/PITR content is left in place, unedited, as a description of what
recovery would look like if a future decision reverses `ADR-0027`'s single-host decision — it is
not the current plan for production. Where a section below states or implies "production" without
qualification, read it as that reversal target, not the current state, except where this note and
the corrected §9 say otherwise.

## 1. Production database baseline

Use a managed PostgreSQL 18 service running the current minor release and supporting:

- encrypted storage and TLS connections;
- automated backups;
- point-in-time recovery (PITR);
- monitoring and slow-query visibility;
- controlled maintenance windows;
- restore to a separate instance;
- `pg_trgm` and `unaccent` extensions;
- high availability/failover for transactional production where budget/provider permits.

Application runtime uses pooled connections when supported. Migrations, schema operations, backup/restore, and extension management use a direct connection.

## 2. Provisional recovery objectives

These are default engineering targets pending stakeholder approval:

| Stage | RPO | RTO | Notes |
|---|---:|---:|---|
| Guided beta/manual payment | <= 15 minutes | <= 4 hours | Reduced commercial exposure |
| Online-payment production | <= 5 minutes | <= 1 hour | Requires tested managed HA/PITR and incident staffing |

If budget cannot meet the target, the exception must be documented and accepted before activation.

## 3. Backup policy

Minimum production baseline:

- continuous WAL/PITR where provider supports it;
- automated daily snapshot;
- at least 14 days PITR retention for MVP, preferred 30 days for financial production;
- monthly retained restore point according to approved retention;
- object-storage versioning/lifecycle separately configured;
- secrets and provider credentials backed up through secret-manager recovery, not database dumps.

## 4. Restore testing

A backup is not considered valid until restored.

Schedule:

- staging restore test before initial production;
- quarterly restore test minimum;
- after major database/provider change;
- before destructive migration or major release;
- annual full disaster-recovery exercise.

Evidence records source backup, restore target, duration, row/invariant checks, application smoke test, and sign-off.

## 5. Restore validation

After restore:

1. Validate database version and extensions.
2. Verify migration state.
3. Verify row counts and critical foreign keys.
4. Check financial balanced-batch invariants and unique keys.
5. Check no duplicate active reservation, renewal period, reminder window, or billing cycle.
6. Run authentication, booking, payment-reference, renewal, and file-reference smoke tests.
7. Confirm queue/outbox replay behavior before reconnecting providers.

## 6. Failover and split-brain safety

- Application reconnect strategy uses bounded retries.
- Writes are blocked or placed in maintenance mode when database authority is uncertain.
- Redis/cache state is reconstructable; it is never used to repair authoritative financial state.
- Provider webhooks received during an outage must be durably retried or replayed using provider records.

## 7. Migration connection

When a transaction-mode pooler is used, normal application queries use pooled connection while migrations and maintenance use direct PostgreSQL connection.

## 8. Database security

- separate application and migration roles;
- least-privilege grants;
- SCRAM/TLS or managed identity according to provider;
- no public database endpoint unless strictly controlled;
- credentials rotated and stored in secret manager;
- production data prohibited from developer laptops.

## 9. Combined dev/staging/production host policy

**Corrected 23 Aug 2026 — see the note at the top of this document.** This host (`yiemvm`,
Ubuntu 24.04.4, 8 vCPU/31 GB) is no longer non-production only. Per `ADR-0027`'s single-host
decision, production shares this host with development and staging, running self-managed
PostgreSQL 18 — not the managed-PostgreSQL/PITR target described in §1–§8, which remains
aspirational only if that decision is ever reversed. This host does not provide production PITR
guarantees, for any environment including production.

- Development data is disposable by default.
- Staging receives daily encrypted logical backups to self-hosted object storage, retained at least seven days.
- **Production backup strategy:** the same mitigation `ADR-0035` item 2 established for beta and
  now extends to production — frequent (4–6 hourly) encrypted `pg_dump` snapshots, with a
  documented, tested restore into a scratch database. A backup is not considered valid until
  restored (§4). This is the real, ongoing production backup strategy, not a temporary compromise
  pending a future migration to managed PostgreSQL.
- Local Docker volumes are not backups, for any environment including production.
- Restore staging, and separately restore production per the same tested-restore discipline,
  before high-risk migrations.
- Production data and production database dumps handled outside this policy (e.g. copied off this
  host, such as to a developer laptop) are prohibited unless formally sanitized and approved.
- There is no point-in-time recovery for production under this decision: recovery is bounded by
  the backup interval (4–6 hours), not to an arbitrary moment. This is an accepted risk recorded in
  `ADR-0027`'s "Production graduation — single-host decision" section, not an oversight.
