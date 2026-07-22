# Managed PostgreSQL, Backup, PITR, and Recovery — v0.4

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

## 9. Non-production combined-host policy

The Ubuntu 22.04 2/4 combined host does not provide production PITR guarantees.

- Development data is disposable by default.
- Staging receives daily encrypted logical backups to remote object storage, retained at least seven days.
- Local Docker volumes are not backups.
- Restore staging before initial production and before high-risk migrations.
- Production data and production database dumps are prohibited unless formally sanitized and approved.
- Production recovery objectives remain governed by Sections 1–8 and require managed PostgreSQL/PITR.
