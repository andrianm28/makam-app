# Runbook: Activate the makam-app CI/CD Self-Hosted Deploy Pipeline — v0.1

## Status

**Prepared, not executed.** This runbook is a human-executed artifact, following the same convention as `deploy-stg-vhost.md`/`deploy-production.md`. No command in this document has been run by the preparing agent. It has no execution access to host secrets, GitHub's runner-registration token flow, or sudo. **Do not execute this runbook without a human operator present** — every step below either handles a short-lived credential that must never reach chat/logs, or touches the live `/opt/makam/compose/compose.yml` backing real running production containers.

## Scope

Activate the `deploy-dev`/`deploy-beta` jobs added to `.github/workflows/ci.yml` by `docs/superpowers/plans/2026-09-05-cicd-automation.md` Tasks 2-3. Those jobs target `runs-on: [self-hosted, makam-deploy]` — a runner that does not exist until this runbook is executed. This runbook does not touch application code; it is entirely host/GitHub-repo-setting work.

Related documents:
- `docs/superpowers/specs/2026-09-05-cicd-automation-design.md` §4.2 — the decisions this runbook instantiates
- `docs/operations/dev-staging-environment.md` §10 and `docs/operations/deployment.md` §5 — updated to describe the pipeline this runbook activates
- `docs/operations/runbooks/rollback-deploy.md` — unchanged; still the manual procedure for a bad deploy, automated or not

## Preconditions — do not proceed until all are true

1. **Tasks 1-4 of the implementation plan are merged** to `docs/design-system-and-planning` — `verify-migrations`, `deploy-dev`, `deploy-beta` all exist in the real `.github/workflows/ci.yml` on that branch.
2. **A human operator is present** for the entire runbook, not just the steps that say so explicitly.

## Step 1 — Settle the `.env.dev`/`.env.beta` permission question

The design's own validation pass found a real, unresolved contradiction: `/opt/makam/compose/compose.yml`'s comment on `dev-web` claims `.env.dev`/`.env.beta` are root:root mode 0400 ("`docker compose` commands touching this service need sudo -n to read it"), but an unprivileged `docker compose config` run during that validation succeeded with no sudo and no permission error. Settle this before deciding whether Step 4 is needed at all:

```bash
sudo stat -c '%U:%G %a' /opt/makam/compose/.env.dev
sudo stat -c '%U:%G %a' /opt/makam/compose/.env.beta
```

If both report an owner/mode the `ubuntu` user (or whichever account will run the self-hosted runner) can already read, skip Step 4 entirely and note that in this runbook's evidence record (Step 8). If either is genuinely unreadable by that account, Step 4 is required.

## Step 2 — Generate the runner registration token and register the runner

In GitHub: **Settings → Actions → Runners → New self-hosted runner**, for `andrianm28/makam-app`. Copy the registration command GitHub shows — it includes a short-lived token. Run it yourself, directly (via `!` in an interactive session, or your own terminal) — **never paste that token into a tool call or any AI session's input**, since it would then appear in that session's logs.

```bash
mkdir -p /home/ubuntu/actions-runner-makam-app
cd /home/ubuntu/actions-runner-makam-app
curl -o actions-runner-linux-x64.tar.gz -L <the tarball URL GitHub's own page shows>
tar xzf actions-runner-linux-x64.tar.gz
./config.sh --url https://github.com/andrianm28/makam-app --token <the token GitHub's own page shows> --labels makam-deploy --name makam-host-runner
```

Use the same tarball version and directory-naming convention already proven working for `andrianm28/galangdana` on this same host (`/home/ubuntu/actions-runner-galangdana`) — mirror it exactly for `makam-app`, just with its own directory and labels.

## Step 3 — Install the runner as a systemd service

```bash
cd /home/ubuntu/actions-runner-makam-app
sudo ./svc.sh install ubuntu
sudo ./svc.sh start
sudo systemctl status actions.runner.andrianm28-makam-app.makam-host-runner.service
```

Confirm it shows `enabled`/`active (running)` — matching galangdana's own real, working `actions.runner.andrianm28-galangdana.galangdana-host-runner.service` exactly in shape, just for this repo.

## Step 4 — Add a sudoers grant, ONLY if Step 1 showed it's needed

Skip this step entirely if Step 1's `stat` output showed the runner's user can already read both env files. If not, add a narrowly-scoped rule — a human edits this file directly, never an AI agent:

```bash
sudo visudo -f /etc/sudoers.d/makam-deploy-runner
```

Contents (narrowest working scope — confirm `docker compose` really needs no other sudo-gated action first):

```text
ubuntu ALL=(root) NOPASSWD: /usr/bin/docker compose -f /opt/makam/compose/compose.yml *
```

## Step 5 — Bring `/opt/makam/compose` under git and refactor to `${APP_IMAGE_DEV}`/`${APP_IMAGE_BETA}`

Take a real backup first, matching the existing convention one last time:

```bash
cd /opt/makam/compose
cp compose.yml compose.yml.bak-$(date +%Y%m%d%H%M%S)-pre-git-init
```

Capture what is actually running RIGHT NOW, before touching anything — the compose file still has its old hardcoded `image:` lines at this point, so `docker compose ps`/`docker inspect` work normally:

```bash
DEV_DIGEST=$(docker inspect --format='{{.Image}}' $(docker compose ps -q dev-web) | xargs docker inspect --format='{{index .RepoDigests 0}}')
BETA_DIGEST=$(docker inspect --format='{{.Image}}' $(docker compose ps -q beta-web) | xargs docker inspect --format='{{index .RepoDigests 0}}')
echo "Currently running — dev: $DEV_DIGEST"
echo "Currently running — beta: $BETA_DIGEST"
```

Now bring the directory under git and record a snapshot of the pre-refactor rendered config, to compare the refactor against afterward:

```bash
git init
git add -A
git commit -m "chore: bring compose.yml under version control (was hand-timestamped .bak copies)"
docker compose config > /tmp/compose-config-before-refactor.yml
```

Add a `.gitignore` **before** any further `git add` — this directory's `secrets/` subdirectory holds real database passwords, and `.env.dev`/`.env.beta` hold `APP_KEY`/`DB_PASSWORD`. None of that may ever enter git history:

```bash
cat > .gitignore <<'GITIGNORE'
secrets/
.env.dev
.env.beta
*.bak-*
GITIGNORE
git add .gitignore
git commit -m "chore: exclude secrets/, env files, and backup snapshots from version control"
```

Now edit `compose.yml`: for `dev-web`, replace its hardcoded `image: ghcr.io/andrianm28/makam-app@sha256:...` line with:

```yaml
    image: ${APP_IMAGE_DEV:?APP_IMAGE_DEV is required}
```

For `beta-web`, `beta-worker`, and `beta-scheduler` (all three), replace their hardcoded `image:` lines with:

```yaml
    image: ${APP_IMAGE_BETA:?APP_IMAGE_BETA is required}
```

**Two separate variables, not one shared `${APP_IMAGE}`** — dev and beta are promoted independently (dev first, then the identical digest to beta only after a passing smoke test), and a future manual `docker compose up -d` targeting just one environment must not be able to accidentally move the other. Keep each service's own large historical comment block above the `image:` line as-is — it is now the last entry in that inline history; all future promotions are recorded as real git commits instead.

Create `.env` (auto-loaded by `docker compose` for variable substitution — no `--env-file` flag needed by any future command, automated or manual) seeded with the digests you captured a moment ago, so the very first commit already reflects reality rather than starting empty:

```bash
cat > .env <<ENV
APP_IMAGE_DEV=${DEV_DIGEST}
APP_IMAGE_BETA=${BETA_DIGEST}
ENV
```

Validate before touching any running container — compare the refactored file's rendered config against the snapshot you took before editing:

```bash
docker compose config > /tmp/compose-config-after-refactor.yml
diff /tmp/compose-config-before-refactor.yml /tmp/compose-config-after-refactor.yml
```

The only differences should be cosmetic (e.g. how the image reference was spelled) — the resolved `image:` values for `dev-web`/`beta-web`/`beta-worker`/`beta-scheduler` must be byte-identical to what was running before. If anything else differs, stop and investigate before committing.

```bash
git add compose.yml .env
git commit -m "refactor: parameterize image digests via \${APP_IMAGE_DEV}/\${APP_IMAGE_BETA} (cicd-automation-design.md decision 3, corrected post-final-review to two variables — see plan ledger)"
```

## Step 6 — Branch protection on `docs/design-system-and-planning`

```bash
gh api --method PUT repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection \
  -f 'required_status_checks[strict]=true' \
  -f 'required_status_checks[contexts][]=Docs and design gates' \
  -f 'required_status_checks[contexts][]=Verify pinned runtime versions' \
  -f 'required_status_checks[contexts][]=PHP (validate, lint, analyse, test)' \
  -f 'required_status_checks[contexts][]=Frontend build' \
  -f 'required_status_checks[contexts][]=OpenAPI and YAML validation' \
  -f 'required_status_checks[contexts][]=Dependency audit' \
  -f 'required_status_checks[contexts][]=Browser + a11y smoke test (Playwright)' \
  -f 'required_status_checks[contexts][]=Load and performance benchmarks (k6, AC4)' \
  -f 'required_status_checks[contexts][]=Verify no unapproved destructive migrations' \
  -f 'required_pull_request_reviews[required_approving_review_count]=1' \
  -F 'enforce_admins=true' \
  -F 'restrictions=null'
```

Confirm afterward:

```bash
gh api repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection
```

## Step 7 — Arm the activation gate, then trigger first real activation

Both `deploy-dev` and `deploy-beta`'s `if:` conditions require the repository variable `MAKAM_DEPLOY_RUNNER_ACTIVE` to equal `true` before they will ever run — until now it has never been set, so every trunk push has been safely skipping both jobs outright (not hanging, not failing) rather than queuing for a runner that didn't exist. Set it now, only once the runner (Steps 2-3) is confirmed running and (if needed) the sudoers grant (Step 4) is in place:

```bash
gh variable set MAKAM_DEPLOY_RUNNER_ACTIVE --body true --repo andrianm28/makam-app
```

Merge any small PR to `docs/design-system-and-planning` (or push a trivial change directly, if branch protection from Step 6 permits a final unprotected test push before it's confirmed active) and watch the real run:

```bash
gh run list --branch docs/design-system-and-planning --limit 1
gh run watch
```

Confirm `deploy-dev` and `deploy-beta` both appear and complete, and that `https://dev.makam.co.id/health/live` and `https://makam.co.id/health/live` both reflect the newly deployed digest afterward.

## Step 8 — Record evidence and close this runbook

Record: Step 1's real permission-check output (and whether Step 4 was needed), the registered runner's name/labels, `compose.yml`'s first real git commit hash, Step 6's confirmed protection settings, the `MAKAM_DEPLOY_RUNNER_ACTIVE` repository variable was set (Step 7), and Step 7's first real run URL. Update this runbook's own Status line from "Prepared, not executed" only after Step 7 has genuinely completed — not before.

## Rollback

Activating this runbook does not itself require a rollback procedure — it only adds automation around an already-existing manual process. If `deploy-dev`/`deploy-beta`'s first real run (Step 7) causes a bad deploy, use the existing `docs/operations/runbooks/rollback-deploy.md` exactly as if the bad deploy had been manual — the mechanism it rolls back (`APP_IMAGE` re-pin) is unchanged by this runbook.
