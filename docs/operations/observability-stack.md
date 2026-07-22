# Production Observability Stack — v0.4

## 1. Components

| Component | Responsibility |
|---|---|
| Structured application logs | Request/job/provider diagnostic events |
| External error tracking | Exceptions, stack traces, release correlation |
| Laravel Horizon | Redis queue throughput, runtime, wait, failures |
| Laravel Pulse | Slow requests/jobs, usage and application bottlenecks |
| Uptime monitoring | Public availability and synthetic journey checks |
| Managed PostgreSQL metrics | CPU, storage, connections, locks, replication/failover |
| Redis metrics | memory, evictions, connection, latency, failover |
| Audit log | Security/privileged business evidence |
| Financial exception dashboard | Reconciliation, refund, payout, webhook exceptions |

Pulse and Horizon production dashboards require explicit privileged authorization.

## 2. Correlation

Every request/job/event carries an opaque `trace_id`/`request_id`. Financial and operational logs include domain references but avoid full PII. Webhook record, outbox event, queue job, journal reference, and notification should be traceable through safe identifiers.

## 3. Structured log fields

```text
timestamp
level
environment
service/application
release
request_id / trace_id
actor_type and safe actor reference
domain_reference
operation
result
latency_ms
provider and provider_reference when allowed
error_class
```

Never log secrets, raw documents, signed URLs, full identity numbers, payment credentials, or unrestricted provider payloads.

## 4. Alerts

### Immediate/high severity

- cross-scope access or restricted document exposure;
- paid state without validated journal reference;
- payment for expired reservation;
- duplicate financial effect/invariant failure;
- critical/urgent queue wait breach;
- database unavailable or failover failure;
- outbox critical event age above threshold;
- malware scanner bypass or accepted file without scan.

### Operational

- high 5xx/error rate;
- grave search p95 breach;
- notification provider failure;
- operator/vendor response backlog;
- reconciliation exception aging;
- storage capacity/backup failure;
- Redis eviction or memory pressure.

## 5. Retention

Retention differs by data type. Debug/application logs should be short and minimized; audit/financial evidence follows approved legal policy. Error tracking must scrub PII before transmission.

## 6. Deployment integration

Every deploy records release identifier and restarts Horizon/Pulse gracefully. Dashboards and alerts are checked in release smoke tests.

## 7. Lightweight non-production profile

For the Ubuntu 22.04 2/4 host:

- monitor host CPU, RAM, swap, load, OOM, disk, and container restarts;
- keep structured logs short and environment-tagged;
- use external error tracking with separate dev/staging environments;
- enable Horizon visibility for staging only;
- Pulse is optional in development and reduced/sampled in staging;
- alert on memory above 80%, sustained swap, critical queue delay, Redis no-memory errors, PostgreSQL connection saturation, disk pressure, and failed remote backup.

Lightweight non-production observability does not replace production monitoring acceptance.
