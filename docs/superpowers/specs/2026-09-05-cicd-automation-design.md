# CI/CD Automation for the Combined Dev+Staging+Beta Host — Design

## Status

Approved via interactive brainstorming (2026-09-05). Not yet implemented. This document is the spec an implementation plan (`writing-plans`) will argue from.

## 1. Context and motivation

The user asked to set up "full, complete CI/CD on this host, like galangdana" — referring to `andrianm28/galangdana`, an unrelated project sharing this same host (`yiemvm`), which already has a working self-hosted-runner deploy pipeline.

### 1.1 The galangdana reference model

`galangdana`'s `.github/workflows/ci.yml` has two jobs:

- `test` — runs on a GitHub-hosted `ubuntu-latest` runner: install, lint, migrate, seed, typecheck, unit tests, build, browser/link-check smoke tests against a locally-served build.
- `deploy` — `needs: test`, `if: github.event_name == 'push' && github.ref == 'refs/heads/master'`, `runs-on: [self-hosted, galangdana-deploy]`. Runs on a **self-hosted GitHub Actions runner living on this same host**, registered as a system-level systemd service (`actions.runner.andrianm28-galangdana.galangdana-host-runner.service`). The job updates the live checkout in place (`git fetch && git reset --hard`), rebuilds, restarts two `systemctl --user` services (`galangdana-api.service`, `galangdana-web.service`), then curls both live production domains as a health check. A `concurrency` group prevents two overlapping deploys. The job's own comment explains its safety model explicitly: this is safe specifically *because* the repo requires a passing PR review before merge to `master` — a self-hosted runner triggered by `pull_request` instead would let anyone who opens a PR run code on this host.

galangdana's app runs bare (Bun + systemd), not in Docker; only its infra dependencies (Postgres, Redis, Meilisearch, MinIO, imgproxy) are containerized.

### 1.2 makam-app's current state — confirmed by direct inspection, not assumed

- `.github/workflows/ci.yml` ends at `build-image` (build + push to GHCR). **There is no deploy job at all.** No self-hosted runner exists for `andrianm28/makam-app`.
- Every real deploy today is a **manual hand-edit** of `/opt/makam/compose/compose.yml` on this host: 25+ timestamped `.bak` snapshots of that file exist, each promotion recorded as a large inline comment (which PR, which digest, which migrations, "read individually before running, zero destructive operations"). This file is **not under version control**.
- makam-app runs **three environments in Docker** on this one host, per the real `compose.yml`:
  - `dev-web` — one container, no persistent worker/scheduler (per `dev-staging-environment.md` §9, matches reality).
  - `beta-web` + `beta-worker` (plain `php artisan queue:work ...`, **not Horizon** — despite several docs, including `ci-cd-and-release.md` §5 and `deploy-production.md`, referring to Horizon-specific commands like `horizon:terminate`/`horizon:pause-supervisor`. `compose.yml`'s own comment on `beta-worker`'s disabled healthcheck already acknowledges this doc/reality mismatch) + `beta-scheduler` (`php artisan schedule:work`).
  - `stg-placeholder` — an inert nginx page; `stg.makam.co.id` has never actually been deployed (blocked on DNS per `deploy-stg-vhost.md`, status "prepared, not executed").
- **`beta` is real, live production traffic**, not a staging sandbox. Confirmed two ways: (a) ADR-0027's "Production graduation — single-host decision" (23 Aug 2026) states this explicitly; (b) `curl https://makam.co.id/health/live` was run live during this design's validation pass and returned a real `200 {"status":"ok"}` — `beta-web` serves the actual production apex domain today, following a "makam.co.id cutover" recorded in `compose.yml`'s own comment history (19 Aug 2026).
- The repo is **public**, same as galangdana — the same self-hosted-runner-on-PR risk applies.
- `docs/design-system-and-planning` is the real trunk (every PR in this project's history targets it), but GitHub's own "default branch" setting still points at `master`, and **there is no branch protection on `docs/design-system-and-planning` today** (confirmed via `gh api repos/.../branches/.../protection` → 404).
- DB backups are already automated separately via cron (`/etc/cron.d/makam-pg-backup`) — not part of this gap.

### 1.3 Real bugs found during this design's own validation pass

Before finalizing, the draft design was stress-tested against the real repo and real host, per the user's explicit request ("full deep validation"). Four concrete, would-have-shipped bugs were found and are now baked into this spec as constraints (not left as open risks):

1. **`build-image`'s trigger condition is `if: github.event_name == 'push'` with no ref check at all.** Confirmed live: PR #230's CI run was triggered by a plain `push` event on its own feature branch (`fix/sentry-pii-scrubber`), not by the `pull_request` trigger (`ci.yml`'s `pull_request: branches: [master]` is dead code for this repo — real work targets `docs/design-system-and-planning`). Had the new deploy jobs copied `build-image`'s existing condition, they would deploy on **every push to every branch**, including unreviewed feature branches — the opposite of the intended safety model. **Constraint: every new deploy job's `if:` must include `&& github.ref == 'refs/heads/docs/design-system-and-planning'` explicitly, never inherited from `build-image`.**
2. **A grep/awk-based destructive-migration scanner would false-positive on this codebase specifically.** This repo's migrations write long doc-block comments that frequently *name* `dropColumn()`/`DELETE` while explaining why the migration is safe (two real examples found during validation). **Constraint: the scanner must strip comments before pattern-matching — implemented as a small PHP script using `token_get_all()`, not raw grep/awk over the raw file text.**
3. **Beta's graceful-restart step must not call Horizon commands.** `deploy-production.md`'s own example steps (`horizon:terminate`) do not apply to the real `beta-worker`, which runs plain `queue:work`. **Constraint: rely on the already-configured `stop_grace_period: 90s` + a plain `docker compose up -d`, which lets `queue:work`'s own SIGTERM handler finish its current job — no Horizon-specific command anywhere in the new jobs.**
4. **Job-output wiring gap.** `build-image` does not currently expose an `outputs:` block, so `needs.build-image.outputs.digest` is not available to any job today. **Constraint: `build-image` must gain an explicit `outputs:` block (`image`, `sha_tag`, `digest`), and `deploy-beta` must list `build-image` directly in its own `needs:` array — GitHub Actions job outputs are not transitive through `deploy-dev`.**

One finding surfaced but **not yet resolved by this spec** — flagged for the implementation plan to settle with a human, not assumed either way: `compose.yml`'s own comment claims `.env.dev`/`.env.beta` are root:root mode 0400, requiring `sudo -n` for any `docker compose` command touching those services — but a real, unprivileged `docker compose config` run during validation succeeded with no sudo and no permission error. The implementation plan's first task must include the human running `sudo stat -c '%U:%G %a' /opt/makam/compose/.env.dev` (and the `.beta` equivalent) to settle whether a new sudoers grant is actually needed before that step is either built or dropped.

## 2. Decisions (confirmed with the user; do not re-litigate)

1. **Fully automatic, matching galangdana exactly** — a push to `docs/design-system-and-planning` auto-deploys to `dev-web`, runs an automated smoke test, and — only if that passes — automatically promotes the *same* digest to the `beta-*` containers and smoke-tests beta too. No human approval step in between, for either environment.
2. **Branch protection is a prerequisite of this work**, not optional — `docs/design-system-and-planning` gets required PR review + required status checks (+ "require branches to be up to date before merging", closing the one edge case where a stale PR branch could merge without re-verification against the latest base) before the deploy jobs go live. This is the same trust boundary galangdana's own workflow comment names as the reason a self-hosted deploy job triggered by `push` is safe.
3. **`compose.yml` is refactored to the `${APP_IMAGE:?APP_IMAGE is required}` pattern** (matching the already-documented, currently-aspirational `docs/operations/examples/docker-compose.dev-stg.yml`), replacing the current per-service hardcoded-digest-plus-giant-comment convention. The rich promotion history moves to git commit messages / PR links instead of inline YAML comments.
4. **An automated destructive-migration gate is added to CI**, blocking the build (not just the deploy) if a migration's `up()` contains a destructive operation (`dropColumn`/`dropTable`/`dropForeign`/raw `DELETE`/`TRUNCATE`) that isn't confined to `down()`. Implemented comment-aware (§1.3 finding 2), with an explicit, auditable override mechanism for a deliberate contract-phase migration (§4.2).
5. **A failed post-deploy smoke test fails the job loudly and stops — no automatic rollback**, for either dev or beta. This matches both galangdana's own precedent (its health-check step is a bare `exit 1` on failure, nothing more) and makam-app's own `rollback-deploy.md`, which explicitly frames rollback as "an operational judgment call, not an automated trigger." A human then runs the existing rollback runbook by hand.
6. **`/opt/makam/compose/compose.yml` is brought under git** (`git init` directly on the host, kept out of GitHub — it's live host/container topology, not source code), replacing the current hand-timestamped `.bak`-copy convention with a real, diffable, revertable commit history. The deploy job commits after each successful `APP_IMAGE` bump.

## 3. Architecture

### 3.1 Pipeline shape

```text
docs-gates, verify-versions, php, frontend, contracts,
security-audit, browser-test, load-test, verify-migrations  ─┐
                                                              ├─▶ build-image ─▶ deploy-dev ─▶ deploy-beta
                                                              ┘
```

`verify-migrations` runs on a GitHub-hosted runner (no host access needed) and is also a **required PR status check** — this is where the destructive-migration gate actually lives; it does not need to re-run at deploy time, since branch protection (Decision 2) already guarantees only a commit that passed this check reaches `docs/design-system-and-planning`.

`build-image` gains an explicit `outputs:` block:

```yaml
outputs:
  image: ${{ steps.meta.outputs.image }}
  sha_tag: ${{ steps.meta.outputs.sha_tag }}
  digest: ${{ steps.build.outputs.digest }}
```

### 3.2 `deploy-dev` job

```yaml
deploy-dev:
  name: Deploy to dev.makam.co.id
  needs: [build-image]
  if: github.event_name == 'push' && github.ref == 'refs/heads/docs/design-system-and-planning'
  runs-on: [self-hosted, makam-deploy]
  concurrency:
    group: makam-deploy
    cancel-in-progress: false
  steps:
    - name: Promote the artifact to dev
      working-directory: /opt/makam/compose
      run: |
        export APP_IMAGE="${{ needs.build-image.outputs.image }}@${{ needs.build-image.outputs.digest }}"
        docker compose up -d dev-web
        git add -A && git commit -m "deploy(dev): ${{ needs.build-image.outputs.sha_tag }}" || true

    - name: Run dev migrations
      working-directory: /opt/makam/compose
      run: docker compose exec -T dev-web php artisan migrate --force

    - name: Smoke-test dev
      run: |
        for i in $(seq 1 30); do
          curl -sf https://dev.makam.co.id/health/live > /dev/null && break
          sleep 2
        done
        curl -sf https://dev.makam.co.id/health/live
        curl -sf https://dev.makam.co.id/health/ready
        curl -sf -o /dev/null -w '%{http_code}' https://dev.makam.co.id/ | grep -q 200
```

No worker/scheduler restart step for dev — none exists (§1.2, matches `dev-staging-environment.md` §9).

### 3.3 `deploy-beta` job

```yaml
deploy-beta:
  name: Promote to makam.co.id (beta/production)
  needs: [build-image, deploy-dev]
  if: github.event_name == 'push' && github.ref == 'refs/heads/docs/design-system-and-planning'
  runs-on: [self-hosted, makam-deploy]
  concurrency:
    group: makam-deploy
    cancel-in-progress: false
  steps:
    - name: Promote the same artifact to beta
      working-directory: /opt/makam/compose
      run: |
        export APP_IMAGE="${{ needs.build-image.outputs.image }}@${{ needs.build-image.outputs.digest }}"
        docker compose up -d beta-web beta-worker beta-scheduler
        git add -A && git commit -m "deploy(beta): ${{ needs.build-image.outputs.sha_tag }}" || true

    - name: Run beta migrations
      working-directory: /opt/makam/compose
      run: docker compose exec -T beta-web php artisan migrate --force

    - name: Smoke-test beta (live production domain)
      run: |
        for i in $(seq 1 30); do
          curl -sf https://makam.co.id/health/live > /dev/null && break
          sleep 2
        done
        curl -sf https://makam.co.id/health/live
        curl -sf https://makam.co.id/health/ready
        curl -sf -o /dev/null -w '%{http_code}' https://makam.co.id/ | grep -q 200
```

`needs: [build-image, deploy-dev]` — both listed explicitly (§1.3 finding 4: outputs are not transitive through `deploy-dev` alone). No `horizon:terminate`/`horizon:pause-supervisor` anywhere (§1.3 finding 3) — `docker compose up -d` on `beta-worker` relies on its already-configured `stop_grace_period: 90s` for a graceful `queue:work` shutdown.

A failure at any step in either job stops the pipeline there (default GitHub Actions behavior — no `continue-on-error`). Per Decision 5, no rollback step exists in either job.

### 3.4 Destructive-migration gate (`ci/verify-migrations.php`, run inside `verify-migrations`)

Comment-aware, using `token_get_all()`:

- For each `.php` file changed under `database/migrations/` in the PR's diff (`git diff origin/<base>...HEAD --name-only -- database/migrations`),
- Tokenize the file, discard `T_COMMENT`/`T_DOC_COMMENT` tokens,
- Locate the token range for the `up()` method body specifically (not `down()`),
- Fail if that range contains a call to `dropColumn`, `dropTable`, `dropForeign`, `DB::delete`, `DB::table(...)->truncate()`, or raw `DELETE`/`TRUNCATE` SQL.

**Override mechanism for a deliberate contract-phase migration** (`ci-cd-and-release.md` §4's "Release C — contract", which legitimately needs a destructive `up()` after separate approval): the script recognizes a one-line marker comment immediately preceding the destructive call, e.g. `// contract-approved: <PR-or-ticket-reference>`, and skips only that specific call site — logged in the job output as "contract-phase override present," never silent. This keeps the gate auditable rather than adding a blanket bypass flag.

### 3.5 Branch protection (GitHub repo setting, not host-level)

On `docs/design-system-and-planning`:
- Require a pull request before merging (at least 1 approval).
- Require status checks to pass before merging — naming the existing job set (`docs-gates`, `verify-versions`, `php`, `frontend`, `contracts`, `security-audit`, `browser-test`, `load-test`) plus the new `verify-migrations`.
- Require branches to be up to date before merging (closes the edge case named in §1.1/Decision 2).

Applied via `gh api repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection` (URL-encoded slash) with the implementer's explicit go-ahead at implementation time — a repo governance setting, not a host/security change, but still confirmed with the user before executing since it changes how every future push/merge behaves.

## 4. Host setup — division of labor

Per AGENTS.md's mandatory-human-review rule for production/security-affecting changes, and the system-level prohibition on an AI agent modifying security settings (sudoers) directly:

### 4.1 Prepared by the implementer, as normal PRs (reviewed and merged like any other change)
- The `verify-migrations` job + `ci/verify-migrations.php`.
- The `deploy-dev`/`deploy-beta` jobs and `build-image`'s new `outputs:` block, in `.github/workflows/ci.yml`.
- Doc updates: `dev-staging-environment.md` §10, `deployment.md` §5, `ci-cd-and-release.md` §5.1/§8, and the runbooks — replacing "prepared, not executed"/manual-procedure wording with the real automated pipeline's description once it is live. `rollback-deploy.md` needs no procedural change (rollback stays manual, per Decision 5) but should note that an automated deploy, not just a manual one, can now trigger its use.

### 4.2 One-time, host-level, privileged — requires the human directly, not the implementer alone
1. **Settle the `.env.dev`/`.env.beta` permission question** (§1.3's open finding) — run `sudo stat -c '%U:%G %a' /opt/makam/compose/.env.dev` (and `.env.beta`) and decide, based on the real answer, whether a sudoers grant is needed at all.
2. **Generate the GitHub Actions runner registration token** in the GitHub UI (Settings → Actions → Runners → New self-hosted runner) and run `config.sh --url ... --token ...` directly — this token must never be typed into a tool call or appear in any session log.
3. **Install the runner as a systemd service** (`svc.sh install && svc.sh start`), mirroring galangdana's real, working `actions.runner.andrianm28-galangdana.galangdana-host-runner.service` shape, registered to `andrianm28/makam-app` with the `makam-deploy` label used throughout §3.
4. **If §4.2.1 shows a sudoers grant really is needed**, add it — narrowly scoped (e.g. `ubuntu ALL=(root) NOPASSWD: /usr/bin/docker compose -f /opt/makam/compose/compose.yml *`, or narrower still if a tighter scope is workable) — never done by the implementer.
5. **The one-time `compose.yml` refactor to `${APP_IMAGE}`**, done with the human present: take a real timestamped backup one last time (matching the existing convention), `git init` the directory (Decision 6), apply the refactor diff, run `docker compose config` as a dry-run against the live file before any running container is touched, confirm no drift versus the currently-running containers' actual image references.
6. **Branch protection** (§3.5) — executed via `gh api` with the human's explicit go-ahead at implementation time.

## 5. Scope boundaries — explicitly not covered

- The not-yet-built `prod-*` compose stack (`deploy-production.md`: "prepared, not executed... blocked on real engineering work that has not happened yet"). This pipeline automates `dev`/`beta` only — the two environments already live today.
- `stg.makam.co.id` — still an inert nginx placeholder, blocked on DNS. Not touched.
- Database backup automation — already real, already cron-scheduled (`makam-pg-backup`), untouched by this work.
- Any form of automatic rollback (Decision 5) — a failed smoke test stops the pipeline; a human runs `rollback-deploy.md` by hand.
- Standing up Horizon for beta, or otherwise changing its queue-worker mechanism — out of scope; the plain `queue:work` restart path (§1.3 finding 3) is designed around what's really running today, not a reason to change it.

## 6. Verification

This is a design/planning deliverable — no code has been written yet. Once implemented (worktree isolation, task-scoped-then-whole-branch review, one PR per unit of work, per this repo's Superpowers SDD convention), verification per area:

- **`verify-migrations`**: a real test migration file with a destructive `up()` call is rejected; the same call preceded by a `// contract-approved: ...` marker passes with a logged override notice; a call inside `down()` never triggers the gate; a call mentioned only inside a doc-block comment (the exact false-positive risk found during validation) does not trigger the gate.
- **Branch protection**: a direct push to `docs/design-system-and-planning` is refused; a PR with a failing required check cannot merge; a PR whose branch is behind the base cannot merge until updated.
- **`deploy-dev`/`deploy-beta` ref-gating**: a push to an arbitrary feature branch does not trigger either deploy job (closing §1.3 finding 1) — provable by inspecting a real Actions run's job list for a feature-branch push and confirming neither job appears.
- **`deploy-dev`/`deploy-beta` end-to-end**: a real merge to the trunk results in `dev-web` running the new digest, migrations applied, smoke test green, followed by `beta-web`/`beta-worker`/`beta-scheduler` running the identical digest, migrations applied, smoke test green against the real `makam.co.id` domain — observed via a real run, not asserted from the workflow file alone.
- **Failure path**: a deliberately-broken smoke test (e.g. a temporarily-injected failing health check in a throwaway test digest) causes the job to fail and stop, with beta never touched if dev's own smoke test is what failed, and no automatic reversion of anything.
- **Compose-file git history**: after the refactor, a real `APP_IMAGE` bump produces a real, readable `git log`/`git diff` in `/opt/makam/compose`, replacing the need for a new hand-timestamped `.bak` file.

Standard repo gates apply to every prepared PR: `declare(strict_types=1)` on new PHP, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `bash ci/verify-docs.sh`, real Postgres/Redis (never SQLite) for anything with a test suite.
