# Deployment and Environment Baseline — v0.5

## 1. Environment model

```text
local -> combined development/staging -> production
```

Development and staging may share the temporary host defined in `dev-staging-environment.md`, but remain logically isolated. Production remains a separate environment with separate infrastructure, credentials, and data.

## 2. Combined development/staging topology

```text
Ubuntu 22.04 LTS — 2 vCPU / 4 GB
  -> host reverse proxy/TLS
  -> dev web container
  -> staging web container
  -> staging Horizon container
  -> shared PostgreSQL 18 container, separate DB/users
  -> shared Redis 8.2 container, separate prefixes/queues
  -> external private object storage/provider sandboxes
```

The combined host is not a build server, production host, or full load-test environment.

## 3. Production topology

```text
CDN/WAF where available
  -> reverse proxy / load balancer
  -> Laravel PHP-FPM application
       -> managed PostgreSQL 18
       -> managed Redis 8.2, non-cluster for Horizon
       -> private S3-compatible object storage
       -> external K1–K8/providers

separate processes:
- web
- Horizon workers
- scheduler/outbox publisher
- Pulse ingestion where configured
```

Production uses Ubuntu 24.04 LTS or managed equivalent. Kubernetes and Octane are not baseline requirements.

## 4. Required configuration classes

- isolated database/user and Redis prefixes per environment;
- distinct `APP_KEY`, session cookie/domain, Horizon prefix, and queue names;
- private storage bucket/prefix and quarantine namespace;
- sandbox/production K1–K8 endpoints and credentials;
- map/navigation provider;
- feature gates and capability profiles;
- At-Need hours, area, capacity, SLA, escalation;
- queue supervisors and long-wait thresholds;
- signed URL maximum TTL;
- payment/webhook/notification secrets;
- rate limits, public projections, telemetry sampling.

Secrets come from protected secret management, never committed `.env` files.

## 5. Non-production deployment procedure

1. CI builds and tests an immutable artifact/image.
2. Deploy artifact to development.
3. Run development migration and smoke tests.
4. Promote the same artifact to staging.
5. Run staging migration using staging credentials.
6. Gracefully restart staging Horizon.
7. Run public/admin/vendor smoke tests and provider sandbox checks.
8. Observe host memory, swap, queue wait, PostgreSQL, Redis, and disk.

## 6. Production deployment procedure

The canonical process is `ci-cd-and-release.md`. Database recovery follows `database-backup-and-recovery.md`.

## 7. Rollback

Prefer application rollback with forward-compatible schema. Financial, reservation, certificate, outbox, and audit records are never deleted to simulate rollback. Close affected feature gates and reconcile durable external events before resuming.
