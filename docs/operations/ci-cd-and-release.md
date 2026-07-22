# CI/CD, Migration, Deployment, and Rollback — v0.4

## 1. Principles

- Build once, promote the same immutable artifact.
- Production installs lockfiles without dependency resolution.
- Database changes use expand/contract and forward compatibility.
- Rollback means application rollback plus safe schema compatibility; financial/audit history is never deleted.
- Deployments are observable and reversible.

## 2. Required CI pipeline

```text
checkout
-> verify pinned runtime versions
-> composer validate
-> composer install --no-interaction --prefer-dist
-> PHP syntax + Pint
-> static analysis (Larastan/PHPStan approved level)
-> unit and feature tests
-> authorization/cross-scope tests
-> financial/idempotency/concurrency tests
-> OpenAPI/YAML/document validation
-> npm clean install from lockfile
-> frontend build
-> browser/critical journey tests
-> dependency vulnerability audit
-> build immutable artifact/image
-> generate SBOM and checksums where supported
```

Pull requests cannot merge when required checks fail.

## 3. Environments

```text
local -> development/preview -> staging -> production
```

- Staging uses provider sandboxes and synthetic data.
- Production credentials never exist in preview/development.
- Environment-specific values come from secret/config management.
- Feature gates are server-side and audited.

## 4. Migration strategy

Use expand/contract:

### Release A — expand

- add nullable/new column or table;
- add index concurrently where supported and appropriate;
- write both old/new representations if necessary;
- deploy compatible code.

### Backfill

- resumable, chunked, observable queue/command;
- idempotent and safe to rerun;
- does not block critical transactions.

### Release B — switch

- read new representation;
- monitor correctness;
- keep rollback compatibility.

### Release C — contract

- remove old column/path only after evidence and separate approval.

Production rollback must not depend on destructive `down()` migrations.

## 5. Deployment sequence

1. Confirm change ticket, artifact checksum, release notes, and gate plan.
2. Confirm backup/PITR and recent restore evidence for high-risk changes.
3. Put risky schedulers/jobs into controlled mode if required.
4. Deploy backward-compatible code.
5. Run safe migrations using direct DB connection.
6. Restart PHP-FPM/application, Horizon, scheduler, and Pulse gracefully.
7. Run health and smoke tests.
8. Verify outbox publication, queue lag, error rate, payment/webhook, authorization, and database metrics.
9. Enable gates progressively where applicable.
10. Observe defined release window and record outcome.

## 6. Rollback triggers

- elevated 5xx/error rate;
- failed critical journey;
- cross-scope authorization defect;
- payment/journal inconsistency;
- outbox/critical queue blockage;
- database saturation or migration regression;
- document exposure/security issue.

## 7. Rollback actions

1. Close affected feature/payment gate.
2. Stop unsafe consumers while preserving durable events.
3. Roll application artifact back.
4. Keep forward-compatible schema.
5. Reprocess outbox/queue only after idempotency review.
6. Reconcile payments/provider events received during incident.
7. Record incident, affected references, and corrective action.

## 8. Deployment checks

- `/health/live`: process alive, no dependency requirement.
- `/health/ready`: database/Redis/config readiness without exposing secrets.
- authenticated smoke checks for each Filament panel.
- public homepage and booking draft check.
- test outbox publisher and queue workers.
- provider sandbox/synthetic webhook test in staging.

## 9. Dependency updates

Use automated pull requests where available, but never auto-deploy dependency changes. Group low-risk patches; isolate framework/Filament/Livewire and security-sensitive package changes.

## 10. Combined dev/staging deployment profile

The Ubuntu 22.04 2/4 host receives a prebuilt immutable artifact/image. Composer dependency resolution, frontend production build, SBOM generation, browser test build, and vulnerability audit run in CI.

Promotion:

```text
commit -> CI artifact -> development -> smoke -> same artifact to staging -> UAT
```

The host may run migrations and application smoke tests, but must not perform routine `composer update`, full asset build, or heavy load generation. Development and staging use distinct secrets/configuration while sharing the image digest.
