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

### 5.1 Release-specific manual steps

Some releases need an operator action inside the same change window as the deploy. These are listed here because they are invisible in the artifact and in the migration set — nothing fails, a flow simply stops working.

**Payment admin authorization hotfix (`fix/payment-controller-authorization`) — role grants required in the same change window as the merge.**

- **What changes.** `POST /admin/pembayaran/pembalikan/{reversalType}` (record a refund or chargeback) and `POST /admin/pembayaran/verifikasi-manual/{paymentVerification}/verifikasi` (approve or reject a manual payment) now require the acting account to hold `finance` **or** `restricted_admin`. Before this release they required only an authenticated session with a recent login.
- **Why a manual step exists.** Roles are granted only by the audited console command; no seeder grants any role. So on deploy **both endpoints refuse everyone, including existing admins**, until an operator grants the roles. That fail-closed outcome is deliberate and must not be softened in code.
- **Who runs it.** The deploy operator, with the release approver naming the accounts. This is a privilege grant, so it needs the same sign-off as any other authorization change.
- **What to run**, once per operator who legitimately performs these actions:

  ```
  php artisan identity:grant-role {actor} finance --reason="<why this operator needs it>"
  ```

  `restricted_admin` may be granted instead; either role unblocks both flows, and there is no per-flow role. **Granting plain `admin` does NOT unblock either flow** — that is deliberate, not an oversight.
- **Until it is run**, both flows stay dark and return 403 to every caller. Each refusal is recorded as an `AuditOutcome::Denied` audit row naming the actor and the role they actually held, so "this operator needs a grant" is distinguishable from "someone is probing this endpoint".
- **Rationale and the authority basis for the role pair:** `docs/superpowers/plans/2026-08-12-payment-controller-auth-hotfix.md` §6.1. Not restated here.

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
- confirm every release-specific manual step in §5.1 that applies to this release was executed; for privileged-role grants, confirm the intended operator can still complete the flow rather than assuming the grant landed.
- Since `docs/superpowers/specs/2026-09-05-cicd-automation-design.md`: the
  first four bullets above (`/health/live`, `/health/ready`, homepage) run
  automatically as part of `deploy-dev`/`deploy-beta`'s own smoke-test
  steps once the self-hosted runner is active (see
  `dev-staging-environment.md` §10's note on activation status). The
  remaining bullets — authenticated Filament smoke checks, outbox/queue
  confirmation, provider-sandbox webhook checks, and §5.1 manual-step
  confirmation — are not automated by this pipeline and remain a human's
  responsibility after an automated deploy, exactly as after a manual one.

## 9. Dependency updates

Use automated pull requests where available, but never auto-deploy dependency changes. Group low-risk patches; isolate framework/Filament/Livewire and security-sensitive package changes.

## 10. Combined dev/staging deployment profile

The Ubuntu 22.04 2/4 host receives a prebuilt immutable artifact/image. Composer dependency resolution, frontend production build, SBOM generation, browser test build, and vulnerability audit run in CI.

Promotion:

```text
commit -> CI artifact -> development -> smoke -> same artifact to staging -> UAT
```

The host may run migrations and application smoke tests, but must not perform routine `composer update`, full asset build, or heavy load generation. Development and staging use distinct secrets/configuration while sharing the image digest.
