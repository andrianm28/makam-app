# Redis Hardening — `requirepass` + Per-Environment Isolation

Status: **prepared, not applied.** Sprint task S2-T6 (`docs/planning/sprint-plan.md`
line 495), human gate **G4** (`docs/planning/agent-execution-plan.md` §4,
Batch 2.6 Agent B). This document and
[`compose.redis-auth-snippet.yml`](examples/compose.redis-auth-snippet.yml)
are the deliverables; neither has been executed against the live
`makam-nonprod` stack. A human must review, apply, and restart.

## 1. Why this is human-gated

- Enabling `requirepass` on a Redis instance that every app container
  already talks to **requires a coordinated restart** of `redis` and every
  service that connects to it (`dev-web`, `stg-web`, `stg-horizon`,
  `dev-worker`, `stg-batch-worker` — see
  [`docker-compose.dev-stg.yml`](examples/docker-compose.dev-stg.yml)).
  A restart on a live shared instance is exactly the class of change
  `AGENTS.md` §Infrastructure-agent execution requires a human to review
  before it happens.
- If the app-side password doesn't match before the restart lands, every
  container loses its cache/session/queue backend at once — see §5
  Rollback below.

## 2. Current state vs. required state

Per `docs/security/security-baseline.md` line 58: "Development and staging
use separate keys, database users, Redis namespaces... PostgreSQL/Redis
ports are private." The port-privacy half is already true (`redis` sits
only on the `backend` internal Docker network — see
[`compose.deployed-reference.yml`](examples/compose.deployed-reference.yml)
lines 101–111 — not published to the host, so it is unreachable from
outside the `makam-nonprod` Docker Compose project). The gap this task
closes:

| Requirement | Current | After this change |
|---|---|---|
| `requirepass` set | **No** — `redis-cli ping` succeeds unauthenticated from any container on `backend` | Yes, via docker secret file |
| Redis client-level prefix differs dev/stg | Not yet configured on the live host (no `.env.dev`/`.env.stg` exist yet — app containers are still placeholders per `compose.deployed-reference.yml`) | `REDIS_PREFIX=makam_dev:` / `makam_stg:` |
| Cache prefix differs dev/stg | Not yet configured | `CACHE_PREFIX=makam_dev_cache:` / `makam_stg_cache:` |
| Horizon namespace differs dev/stg | Not yet configured; only `stg-horizon` runs Horizon today | `HORIZON_PREFIX=makam-dev:` / `makam-stg:` |
| Queue name strings differ dev/stg | **No** — see §4.4 finding | Not resolved by this task (out of file scope, flagged below) |

The internal-network-only mitigation reduces exploitability but does not
satisfy the security-baseline requirement itself, per the task brief this
document was written against — an unauthenticated data store is still a
real gap even with no published port (e.g. any future container attached
to `backend`, or a compromised app container, has unauthenticated Redis
access today).

## 3. `requirepass` via docker secret file

Full wiring: [`compose.redis-auth-snippet.yml`](examples/compose.redis-auth-snippet.yml).
Summary of the approach and why it mirrors the Postgres pattern:

- **Secret file, not inline `environment:`.** `docker-compose.dev-stg.yml`
  already sets this precedent for Postgres —
  `POSTGRES_PASSWORD_FILE: /run/secrets/postgres_admin_password` plus a
  top-level `secrets:` block backed by `./secrets/postgres_admin_password.txt`
  — specifically so the password never appears in `docker inspect` or
  `docker compose config` output. This task adds one new secret,
  `redis_password`, backed by `./secrets/redis_password.txt`, following the
  identical shape.
- **Redis has no `_FILE` convention.** Unlike Postgres's image, plain
  `redis-server` cannot be told "read the password from this file" via an
  environment variable — `--requirepass` only accepts a literal value.
  Pointing `--requirepass` directly at the secret's path would set the
  password to the literal string `/run/secrets/redis_password`, not the
  file's contents. The compose snippet replaces `command:` with a shell
  wrapper (`sh -c '... redis-server --requirepass "$(cat
  /run/secrets/redis_password)" ...'`) that reads the file at container
  start and passes its contents to `redis-server`. Two variants are given
  in the snippet file — see that file for the full trade-off discussion:
  - **Variant A** (recommended default): password lands in the
    `redis-server` process's own argv. Not visible via `docker inspect` or
    `docker compose config`, but visible to `docker top`/`docker exec ps aux`
    for the lifetime of the process — acceptable given that tier of access
    is already host-privileged on this deployment, but confirm before
    merging.
  - **Variant B** (harder, no argv exposure): the wrapper writes
    `requirepass <value>` into a config file inside the container's own
    ephemeral filesystem, then launches `redis-server <that file>`. This
    is **NOT TESTED** against the actual `redis:8.2-alpine` entrypoint
    behavior (see the caveat in the snippet file about uid/gosu) and needs
    a human to validate on a disposable container first.
- Host file ownership for `./secrets/redis_password.txt` is **unverified**.
  The Postgres secret's uid-999 requirement was empirically confirmed on
  this host (`docker-compose.dev-stg.yml`'s own comment, dated 25 Jul
  2026). No equivalent test has been run for the redis image under either
  wrapper variant in this task. Do not assume uid 999 carries over —
  confirm with `docker exec <container> id` after a scratch-container test
  (§6 step 4) before setting host ownership on the real secret file.

## 4. Per-environment isolation

`dev-staging-environment.md` §4 requires distinct values for "Redis
prefix, cache prefix, queue names, Horizon prefix, lock namespace." An
env-var baseline for this is already specified in
[`ai-agent-dev-stg-setup-prompt.md`](ai-agent-dev-stg-setup-prompt.md)
(Phase 7, lines 422–450) — this document does not redefine those values,
it reuses them, and adds the Redis-auth-specific pieces that prompt didn't
cover (`REDIS_PASSWORD`) plus the queue-name gap that prompt didn't
resolve either.

### 4.1 Redis client-level prefix (`REDIS_PREFIX`)

`config/database.php` sets the PhpRedis client-level prefix from
`env('REDIS_PREFIX', ...)` (line 152). This prefix is applied by the
PhpRedis client to **every** key touched over that connection — cache,
queue, session (if Redis-backed), and Horizon's internal bookkeeping keys
all inherit it automatically, because they all go through the same
prefixed client connection. Use the values already established in the
setup prompt:

```env
# .env.dev
REDIS_PREFIX=makam_dev:

# .env.stg
REDIS_PREFIX=makam_stg:
```

Because this prefix is client-level, it is the mechanism that actually
prevents dev and stg Redis keys from colliding even when higher-level
names (queue names, below) happen to match.

### 4.2 Cache prefix (`CACHE_PREFIX`)

`config/cache.php` line 121 reads a separate, Laravel-Cache-specific
prefix from `env('CACHE_PREFIX', ...)`. This stacks on top of, and is
independent from, `REDIS_PREFIX` — it applies whether the cache store is
`database` or `redis`. Continue the existing convention:

```env
# .env.dev
CACHE_PREFIX=makam_dev_cache:

# .env.stg
CACHE_PREFIX=makam_stg_cache:
```

### 4.3 Horizon namespace (`HORIZON_PREFIX`)

`laravel/horizon` (`^5.0`, already in `composer.json`) reads its own
internal key namespace — separate from both prefixes above — from
`env('HORIZON_PREFIX', 'horizon:')` once `config/horizon.php` is published
(`php artisan horizon:install`; that file does not exist in this repo yet,
so there is nothing to edit here — this is a note for whoever publishes
it). Horizon uses this prefix for its supervisor/master locks, metrics,
and internal queue bookkeeping, on top of whatever `REDIS_PREFIX` already
scopes at the client level. Only `stg-horizon` runs Horizon today
(`docker-compose.dev-stg.yml` line 70); `dev-worker` uses plain
`queue:work`, which does not touch Horizon's namespace at all. Set the
value now regardless, so it is correct the day dev also runs Horizon:

```env
# .env.dev
HORIZON_PREFIX=makam-dev:

# .env.stg
HORIZON_PREFIX=makam-stg:
```

### 4.4 Queue name strings — gap found, not fixed here

`dev-staging-environment.md` §4 lists "queue names" as a value that "must
differ per environment," separately from the Redis/cache/Horizon
prefixes. As currently written,
[`docker-compose.dev-stg.yml`](examples/docker-compose.dev-stg.yml) does
**not** satisfy that literally:

```text
line 85  dev-worker:     --queue=critical,urgent,notifications,default
line 73  stg-horizon:    (queues: critical, urgent, notifications, default per dev-staging-environment.md §9)
```

Both environments use the identical queue-name strings
`critical,urgent,notifications,default`. Once `REDIS_PREFIX` differs
per §4.1, the *physical* Redis keys backing those queues no longer
collide (`makam_dev:queues:default` vs `makam_stg:queues:default`), so
this is not a data-corruption risk once §4.1 ships — but it is still a
literal non-compliance with §4's explicit list, and it means anyone
reading queue names alone (dashboards, Horizon UI, ad-hoc `redis-cli`
without the prefix in view) cannot visually distinguish which
environment a queue belongs to. **This is a finding, not a fix**:
`docker-compose.dev-stg.yml` is out of scope for this task (owned by a
different concern in the execution plan). Recommend a follow-up task
change the queue names themselves (e.g. `dev_critical,dev_urgent,...` /
`stg_critical,stg_urgent,...`) or explicitly accept `REDIS_PREFIX`
isolation as sufficient and update §4's wording — either way needs a
human decision, not a silent doc edit.

### 4.5 Lock namespace

`config/cache.php` line 84 sets the atomic-lock connection via
`REDIS_CACHE_LOCK_CONNECTION` (defaults to the `default` Redis
connection). Because that connection carries the client-level
`REDIS_PREFIX` from §4.1, lock keys are already isolated per environment
as soon as §4.1 is applied — no separate env var is needed for this one;
it is listed here so the mapping from `dev-staging-environment.md` §4's
five items to concrete config is complete and traceable.

## 5. Rollback

Because this change requires a restart, prepare the rollback before
applying, not after something breaks:

1. **Before restarting anything**, snapshot the current (no-auth) compose
   service definition for `redis` (e.g. `docker compose config` output,
   or just keep a copy of the pre-change `compose.yml`).
2. Apply the `requirepass` change and restart `redis` plus every service
   that depends on it, in the same maintenance window — a partial restart
   (Redis authenticated, one app container still running with the old
   `.env` lacking `REDIS_PASSWORD`) is the primary failure mode: that
   container will fail every Redis-backed operation (cache, session,
   queue, Horizon) until it is restarted with the matching password.
3. **If the app cannot connect to Redis after enabling auth:**
   - Fastest safe rollback: revert `redis`'s `command:` to the pre-change
     value (no `--requirepass`, keep `--appendonly yes --appendfsync
     everysec --maxmemory-policy noeviction`) and restart just the `redis`
     service. No app-side `.env` change is needed to roll back, because an
     app with `REDIS_PASSWORD` set against an unauthenticated Redis
     server still connects fine — Redis without `requirepass` ignores the
     `AUTH` command's relevance and simply doesn't require it. Verified by
     protocol design, not tested on this host — confirm this is still
     true for Redis 8.2 before relying on it under pressure.
   - Do not delete the `redis_password` secret or its host file as part
     of rollback — keep it in place so re-applying the forward change
     doesn't require regenerating and redistributing a new password.
4. Record whichever direction was taken (applied / rolled back) and why,
   per `AGENTS.md`'s evidence requirements for infrastructure changes.

## 6. Exact commands a human would run

None of the following has been executed as part of preparing this
document — shown as instructions only, per this task's constraint against
running `docker compose`, `systemctl`, or touching the live container.

1. Generate the password value out-of-band (a password manager or
   `openssl rand -base64 32`, run interactively, not captured in any log
   this task produces) and write it — with no trailing newline
   inconsistency versus how the Postgres secrets were written — to:
   ```bash
   # on the deployment host, in the compose project directory
   umask 077
   openssl rand -base64 32 > ./secrets/redis_password.txt
   ```
2. Set host ownership once the correct uid is confirmed per §3's caveat
   (do not assume uid 999 — test first, per §6 step 4 below):
   ```bash
   sudo chown <confirmed-uid>:<confirmed-gid> ./secrets/redis_password.txt
   sudo chmod 0400 ./secrets/redis_password.txt
   ```
3. Merge [`compose.redis-auth-snippet.yml`](examples/compose.redis-auth-snippet.yml)
   into `/opt/makam/compose/compose.yml` by hand (do not `docker compose
   -f` it as an overlay unless it has been reviewed as a proper Compose
   override file — this fragment is written as a documentation aid, not a
   deploy-ready overlay).
4. Validate the merged file without starting anything:
   ```bash
   docker compose config
   ```
5. **Before touching the shared `makam-nonprod` project**, prove the
   wrapper and secret wiring work on a disposable container:
   ```bash
   docker run --rm -d --name redis-auth-scratch \
     -v "$(pwd)/secrets/redis_password.txt:/run/secrets/redis_password:ro" \
     redis:8.2-alpine sh -c \
     'exec redis-server --requirepass "$(cat /run/secrets/redis_password)"'
   docker exec redis-auth-scratch id
   docker exec redis-auth-scratch ps aux
   docker exec redis-auth-scratch redis-cli -a "$(cat ./secrets/redis_password.txt)" ping
   docker rm -f redis-auth-scratch
   ```
   Use the `id` output to settle §3's open uid question before setting
   host ownership on the real secret in step 2.
6. Update `.env.dev` and `.env.stg` (not committed — see
   `ai-agent-dev-stg-setup-prompt.md` Phase 7) with:
   ```env
   REDIS_PASSWORD=<same value as ./secrets/redis_password.txt>
   REDIS_PREFIX=makam_dev:        # or makam_stg: in .env.stg
   CACHE_PREFIX=makam_dev_cache:  # or makam_stg_cache: in .env.stg
   HORIZON_PREFIX=makam-dev:      # or makam-stg: in .env.stg
   ```
   Copy the value by piping between files, not by typing it visibly:
   ```bash
   printf 'REDIS_PASSWORD=%s\n' "$(cat ./secrets/redis_password.txt)" >> .env.dev
   printf 'REDIS_PASSWORD=%s\n' "$(cat ./secrets/redis_password.txt)" >> .env.stg
   ```
7. Apply and restart in one maintenance window per §5 step 2:
   ```bash
   docker compose up -d redis dev-web stg-web stg-horizon dev-worker stg-batch-worker
   ```
8. Smoke test each app container's Redis connectivity (cache write/read,
   a trivial queued job, Horizon dashboard reachable) before closing the
   maintenance window.

## 7. What this task did not do

- Did not generate a real password value. §6 step 1 shows the mechanism
  (`openssl rand -base64 32` into a secret file) without producing actual
  secret content anywhere in this repo.
- Did not run `docker compose`, `systemctl`, or any command against the
  live `makam-nonprod` stack.
- Did not modify `docker-compose.dev-stg.yml`, `compose.deployed-reference.yml`,
  `.env.dev`/`.env.stg` (they do not exist in this repo — they are
  deployment-host artifacts per `ai-agent-dev-stg-setup-prompt.md` Phase
  7), `config/*.php`, or any nginx/backup/observability file.
- Did not verify the `redis:8.2-alpine` entrypoint's uid-drop behavior
  (§3, §6 step 5) — flagged NOT TESTED throughout rather than assumed.
