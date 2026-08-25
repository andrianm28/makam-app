# Runbook: Deploy to Production — v0.1

## Status

**Prepared, not executed.** OQ-4, OQ-6, and "no production host exists" — the three blockers this runbook was originally written around — are all resolved:

- **OQ-4 (object-storage provider): resolved.** Production uses self-hosted object storage (`App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage`) on the shared `yiemvm` host, not an external S3-compatible provider. See ADR-0027's "Production graduation — single-host decision" section (23 Aug 2026).
- **OQ-6 (managed PostgreSQL provider): resolved.** Production uses self-managed PostgreSQL 18 on the same shared host, not a managed provider — this decision supersedes ADR-0021. See ADR-0027's "Production graduation — single-host decision" section.
- **Production host: resolved.** The target is the shared `yiemvm` host — the same host as development and staging, not a separate environment. See ADR-0027's "Production graduation — single-host decision" section (23 Aug 2026) and `docs/operations/deployment.md` §3.

No command in this document has been run, so this runbook remains genuinely "prepared, not executed" — but the reason has changed. It is no longer blocked on an undecided external vendor; it is blocked on real engineering work that has not happened yet: a production compose service definition (`prod-web`/`prod-worker`/`prod-scheduler`, following ADR-0035 item 3's already-built `beta-web`/`beta-worker`/`beta-scheduler` pattern) does not exist yet. This document prepares the STEPS that will apply once that service definition exists, using this repository's own real, already-working mechanisms wherever one exists today, and still marks every step that needs a value only that future compose file/config can supply.

**OQ-7 (production malware scanner) is separate and still open.** This decision does NOT resolve OQ-7 — a real, fail-closed malware scanner for production document uploads remains undecided (ADR-0027's own framing: "always-on ClamAV... prohibited" is not reversed). OQ-7 does not block deploying the application itself — the steps below apply regardless — but it DOES block safely accepting real document uploads in production. Do not treat this runbook's steps as authorization to accept real production document uploads until OQ-7 is resolved separately.

## Scope

Deploy a CI-built, digest-pinned application image to production, once its compose service definition exists. Explicitly NOT covered here: creating that production compose service definition itself, or the self-managed PostgreSQL/object-storage provisioning on the shared host (real engineering work, tracked separately from this deployment procedure) — and explicitly NOT covered here: resolving OQ-7, which this runbook's execution does not depend on but real document-upload acceptance does.

Related documents:
- `docs/operations/deployment.md` (`../deployment.md` from this file's own real location) §3 (topology), §4 (required configuration classes)
- `docs/operations/ci-cd-and-release.md` (`../ci-cd-and-release.md` from this file's own real location) §2 (CI pipeline this runbook's artifact comes from), §5 (deployment sequence this runbook instantiates), §8 (deployment checks) — `deployment.md` §6 names `ci-cd-and-release.md` as the canonical production deployment process; this runbook is that process's concrete, step-by-step instantiation
- [`../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`](../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md) — the "Production graduation — single-host decision" section (23 Aug 2026) names the shared `yiemvm` host as the real production target this runbook deploys to
- [`../../adr/0035-beta-launch-accepted-risks.md`](../../adr/0035-beta-launch-accepted-risks.md) item 3 — the real, already-built precedent (`beta-web`/`beta-worker`/`beta-scheduler`, own database/role/secret, raised memory limits) that a production compose service definition follows
- [`deploy-stg-vhost.md`](deploy-stg-vhost.md) — the sibling staging runbook this one's structure mirrors
- [`rollback-deploy.md`](rollback-deploy.md) — the reverse procedure once a real deploy exists to reverse; see this document's own Rollback section below

## Preconditions — do not proceed until all are true

1. **A real production compose service definition exists**, following ADR-0035 item 3's already-built beta-stack pattern (own `prod-web`/`prod-worker`/`prod-scheduler` containers, own database/role/secret distinct from dev/staging/beta, memory limits sized for a fourth concurrent application on this host). This runbook does not create that compose service definition itself — that is real infra-engineering work, not part of preparing this deployment procedure.
2. **Every configuration class in `deployment.md` §4 has a real, provisioned, production-distinct value** — see §4 for the authoritative list (not reproduced here, per this repository's own rule against duplicating canonical data across documents). In particular: no dev/staging/beta secret, sandbox K1–K8 credential, or shared value is ever reused for production.
3. **CI is green on the commit being promoted.** Verify via:
   ```bash
   gh pr checks <PR-number>
   # or, once merged:
   gh run list --branch <base-branch> --limit 1
   ```

## Step 1 — Identify the real artifact to promote

The immutable reference is the image digest, not either moving tag (`.github/workflows/ci.yml`'s "Build and push image" job — see this runbook's own citation above). `.github/workflows/ci.yml`'s "Generate SBOM" step is the earliest point the digest (`steps.build.outputs.digest`) is used; the later "Record image reference" step only writes it to the job summary (`$GITHUB_STEP_SUMMARY`), not reliably to the plain step log — so grep for the `ghcr.io/...@sha256:...` pattern itself across the whole log rather than anchoring to one step name and a fixed line window (the same approach `rollback-deploy.md`'s preconditions already use for this exact lookup):

```bash
gh run list --branch <base-branch> --status success --limit 5
# then, for the run building the commit being promoted:
gh run view <run-id> --log | grep -oE 'ghcr\.io/[^[:space:]"]+@sha256:[a-f0-9]{64}' | sort -u
# or, from the registry directly, once you have registry access:
docker buildx imagetools inspect ghcr.io/<repo-lowercased>:sha-<12-char-SHA>
```

Record the real `ghcr.io/<repo>@sha256:<digest>` reference — this is what every later step pins to, never a moving tag.

## Step 2 — Provision environment configuration

Once precondition 1 is real: write the production environment's config file, following `docker-compose.dev-stg.yml`'s real `.env.dev`/`.env.stg` env-file pattern, extended to a production equivalent (e.g. `.env.production`) — no such file can be drafted with real values here, since every value in it is a real secret and this repository never commits `.env` files. Follow ADR-0035 item 3's own beta-stack precedent for the shape: its own `makam_beta` database/role/secret, provisioned distinct from `makam_dev`/`makam_stg`, is the exact pattern a production `.env.production` and its own database/role/secret should follow — never reusing a dev/staging/beta value. Confirm every value in `deployment.md` §4's list is set and genuinely distinct from dev/staging/beta's own values.

## Step 3 — Promote the artifact

Once the production compose service definition exists (mirroring the proven `APP_IMAGE` pattern already real in `docker-compose.dev-stg.yml`, e.g. `image: ${APP_IMAGE:?APP_IMAGE is required}`):

```bash
export APP_IMAGE="ghcr.io/<repo-lowercased>@sha256:<digest-from-step-1>"
docker compose -f <production-compose-file> up -d
```

`<production-compose-file>` does not exist yet, but not because of an undecided vendor anymore — it is the concrete next real engineering step: adding `prod-web`/`prod-worker`/`prod-scheduler` service definitions to the shared host's compose file, in the exact same shape ADR-0035 item 3 already used to add `beta-web`/`beta-worker`/`beta-scheduler` alongside `dev-web`/`stg-web`. This runbook names the mechanism it will use (identical to the real, working dev/staging/beta ones), not the file's real content — writing that content is real infra-engineering work, not this documentation task's job.

## Step 4 — Run migrations

Per `ci-cd-and-release.md` §5 step 5 ("Run safe migrations using direct DB connection") and §4's expand/contract discipline:

```bash
docker compose -f <production-compose-file> exec <app-service> php artisan migrate --force
```

Confirm the migration set being applied only contains expand-phase or already-safe changes per §4 — a contract-phase migration (dropping an old column/path) requires the separate approval §4 names, not routine deploy sign-off.

## Step 5 — Restart application processes gracefully

Per `ci-cd-and-release.md` §5 step 6 ("Restart PHP-FPM/application, Horizon, scheduler, and Pulse gracefully"):

```bash
docker compose -f <production-compose-file> exec <app-service> php artisan horizon:terminate
docker compose -f <production-compose-file> restart <app-service> <horizon-service> <scheduler-service> <pulse-service>
```

Omit `<pulse-service>` if this production environment does not run separate Pulse ingestion (`deployment.md` §3 lists it as "where configured", not mandatory). Horizon's graceful terminate (not a hard kill) lets in-flight jobs finish or safely retry, per this repo's own established Horizon deployment discipline (`docs/architecture/queue-and-outbox.md` §9, already cited elsewhere in this codebase). `<scheduler-service>` here is real (unlike dev/staging, which has no persistent scheduler service — see `rollback-deploy.md`'s Environment note — a real production environment per `deployment.md` §3 runs a genuine scheduler process to restart here).

## Step 6 — Run the deployment checks

Per `ci-cd-and-release.md` §8, every one of these against the real production URL once it exists:

```bash
curl -sS https://<production-domain>/health/live
curl -sS https://<production-domain>/health/ready
```

Plus: authenticated smoke checks for each Filament panel (admin, vendor), the public homepage and booking-draft check, outbox publisher/queue-worker confirmation, and — for a release with a `§5.1`-style manual step named in `ci-cd-and-release.md` — confirmation that step was actually executed, not merely that the deploy succeeded (§8's own wording: for a privileged-role grant, confirm the intended operator can still complete the flow rather than assuming the grant landed). §8's provider-sandbox/synthetic-webhook check is explicitly staging-scoped and does not apply here.

## Step 7 — Enable gates progressively; observe the release window

Per `ci-cd-and-release.md` §5 steps 9-10. No feature gate is force-opened by this runbook — gate state changes go through the existing, real Feature Gate admin panel mechanism this codebase already has (`App\Platform\FeatureGate`), with the same audited human action `G-PAY-01`'s own activation already requires.

## Rollback

See [`rollback-deploy.md`](rollback-deploy.md) — a dedicated procedure, not duplicated here.

## Finding surfaced, not resolved

This runbook cannot name a real production compose file path or app-service name anywhere above — every `<production-compose-file>`/`<app-service>`-shaped placeholder in Steps 3-6 stands for a value that genuinely does not exist yet, not an oversight, since the production compose service definition itself has not been created. This is no longer because no target host exists — the target is the shared `yiemvm` host, per ADR-0027's "Production graduation — single-host decision" section — it is because creating that compose service definition (`prod-web`/`prod-worker`/`prod-scheduler`, following ADR-0035 item 3's beta-stack precedent) is real engineering work this document does not do itself.

`<production-domain>` (Step 6) remains a genuinely separate, still-open placeholder — likely `makam.co.id` itself once production graduates from beta, but this plan does not make that decision, so no real value is named here.

Separately, and not resolved by any of the above: **OQ-7** (a real, fail-closed malware scanner for production document uploads) remains open. Every step in this runbook becomes executable once the compose service definition exists, independent of OQ-7 — but OQ-7 being unresolved means production is not yet safe to accept real document uploads, regardless of whether this runbook's deployment steps have run.
