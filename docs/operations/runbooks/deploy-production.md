# Runbook: Deploy to Production — v0.1

## Status

**Prepared, not executed, and cannot be executed today.** Unlike `deploy-stg-vhost.md` (blocked only on a DNS decision), this runbook has two structural blockers with no target to execute against yet:

- **OQ-4 (object-storage provider): undecided.** `docs/planning/sprint-plan.md:191`, `docs/testing/release-gates.md:99,114`. Production requires private S3-compatible storage per `docs/operations/deployment.md` §3 — no provider is chosen, so no real storage endpoint/credentials exist to reference below.
- **OQ-6 (managed PostgreSQL provider): undecided.** `docs/planning/sprint-plan.md`'s own text: "ADR-0021 requires managed + PITR. Undecided — blocks production planning." No managed database exists to migrate against.
- **No production host, DNS name, or infrastructure-procurement decision exists at all.** ADR-0027 item 8 remains in force: the shared dev/staging host "is not accepted as production."

No command in this document has been run. It cannot be run correctly until the three items above are resolved — this document prepares the STEPS that will apply once they are, using this repository's own real, already-working mechanisms wherever one exists today, and marks every step that still needs a real value with an explicit `[BLOCKED: <what decision resolves this>]` marker rather than a placeholder that reads as real.

## Scope

Deploy a CI-built, digest-pinned application image to a production environment, once one exists. Explicitly NOT covered here: provisioning the production environment itself (a separate, infra-procurement-led effort once OQ-4/OQ-6/the hosting decision land), or the object-storage/managed-database setup themselves.

Related documents:
- `docs/operations/deployment.md` (`../deployment.md` from this file's own real location) §3 (topology), §4 (required configuration classes)
- `docs/operations/ci-cd-and-release.md` (`../ci-cd-and-release.md` from this file's own real location) §2 (CI pipeline this runbook's artifact comes from), §5 (deployment sequence this runbook instantiates), §8 (deployment checks) — `deployment.md` §6 names `ci-cd-and-release.md` as the canonical production deployment process; this runbook is that process's concrete, step-by-step instantiation
- [`../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`](../../adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md) — the boundary this runbook is on the far side of (item 8: dev/staging "is not accepted as production")
- [`deploy-stg-vhost.md`](deploy-stg-vhost.md) — the sibling staging runbook this one's structure mirrors
- [`rollback-deploy.md`](rollback-deploy.md) — the reverse procedure once a real deploy exists to reverse; see this document's own Rollback section below

## Preconditions — do not proceed until all are true

1. **OQ-4 resolved**: a real object-storage provider is chosen and provisioned, with real credentials available through this project's secret-management mechanism (never committed to this repository).
2. **OQ-6 resolved**: a real managed-PostgreSQL provider is chosen and provisioned, with PITR enabled per ADR-0021, and real connection credentials available the same way.
3. **A real production host/hosting decision exists** — whatever infrastructure-procurement decision resolves ADR-0027's boundary (a specific cloud provider's managed compute, a dedicated server, etc. — this runbook does not assume which).
4. **Every configuration class in `deployment.md` §4 has a real, provisioned, production-distinct value** — see §4 for the authoritative list (not reproduced here, per this repository's own rule against duplicating canonical data across documents). In particular: no dev/staging secret, sandbox K1–K8 credential, or shared value is ever reused for production.
5. **CI is green on the commit being promoted.** Verify via:
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

## Step 2 — [BLOCKED: OQ-4/OQ-6/infra-procurement] Provision environment configuration

Once preconditions 1-4 are real: write the production environment's config file (mirroring `docker-compose.dev-stg.yml`'s `.env.dev`/`.env.stg` pattern, but for production — no such file can be drafted with real values here, since every value in it depends on the blocked decisions above). Confirm every value in `deployment.md` §4's list is set and genuinely distinct from dev/staging's own values (never copy a dev/staging secret into production).

## Step 3 — Promote the artifact

Once a real production compose/deployment mechanism exists (mirroring the proven `APP_IMAGE` pattern already real in `docker-compose.dev-stg.yml`, e.g. `image: ${APP_IMAGE:?APP_IMAGE is required}`):

```bash
export APP_IMAGE="ghcr.io/<repo-lowercased>@sha256:<digest-from-step-1>"
docker compose -f <production-compose-file> up -d
```

`<production-compose-file>` does not exist yet — creating it is part of the infra-procurement execution phase, once precondition 3 resolves; this runbook names the mechanism it will use (identical to the real, working dev/staging one), not a file that can be written today without a real target.

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

This runbook cannot name a real production domain, compose file path, or app-service name anywhere above — every `<production-...>` placeholder in Steps 2-6 stands for a value that genuinely does not exist yet, not an oversight. Resolving OQ-4, OQ-6, and the infra-procurement decision is what turns each placeholder into a real, executable value; this document's job is to have every OTHER step (artifact identification, migration sequencing, restart discipline, deployment checks, gate rollout) already correct and ready the moment that happens.
