# Observability and Service Objectives — v0.4

## Mandatory/source indicators

| Indicator | Requirement |
|---|---|
| Grave search latency | Under 500 ms at 100,000 records |
| Bulk import | 10,000 rows via queue with row-level errors |
| Signed deceased-document URL | Maximum 5 minutes |
| Grave reminder | Exactly one per grave per window |
| Subscription billing | Exactly one invoice per cycle |

## Tooling baseline

- structured JSON application/job logs;
- external error tracking with PII scrubbing;
- Laravel Horizon for queue metrics;
- Laravel Pulse for slow requests/jobs and application usage;
- managed PostgreSQL and Redis metrics;
- uptime and synthetic critical-journey monitoring;
- append-only audit and dedicated financial exception reporting.

See `observability-stack.md` for controls.

## Provisional service objectives

Until general NFR is approved, use engineering targets from `performance-and-capacity.md` and recovery targets from `database-backup-and-recovery.md`. They are release guardrails, not contractual SLA.

## Domain indicators

### At-Need/FuneralCase

- first human acknowledgement;
- availability/reservation confirmation;
- overdue critical tasks;
- operator fallback rate;
- paid-to-service-start and completion time.

### Financial

- webhook storage/processing latency and rejection reason;
- outbox age and duplicate-consumer suppression;
- journal/invoice exception;
- reconciliation mismatch count/age;
- vendor payable/payout aging;
- refund/chargeback status.

### Search/import

- search p50/p95/p99 and zero-result rate;
- import throughput, row error, duplicate/conflict counts.

### Security/files

- restricted-file access anomaly;
- quarantine/scanner failure;
- signed URL generation/access;
- privileged action and MFA failure.

## Critical alerts

- Urgent accepted without owner/acknowledgement;
- critical or urgent queue wait breach;
- outbox critical event stuck;
- authoritative plot source stale or reservation invariant failure;
- paid without journal reference;
- payment/merchant/amount mismatch;
- cross-scope or document exposure;
- database backup/PITR failure;
- queue/provider credential/signature failure;
- search latency breach.
