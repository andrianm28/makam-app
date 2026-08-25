# Runbook: Rotate the `makam_dev`/`makam_stg`/`postgres_admin` database access values — v0.1

## Status

**Prepared, not executed.** This runbook closes Lane D6 ("broader credential hygiene") from
[`docs/adr/0035-beta-launch-accepted-risks.md`](../../adr/0035-beta-launch-accepted-risks.md)
item 8: *"Lane D6 (broader credential hygiene — e.g. rotating anything else touched by the dev
admin password's git-history exposure) remains not built."* No command in this document has been
run by the preparing agent. It has no execution access to the host, the live database, or the
secret files described here.

Do not execute this runbook without a human operator present. Every step that touches a live
database role's access value or a running container's environment is production-adjacent even
though this is the dev/staging host — a mistake here can lock every app instance out of its own
database. This is exactly the class of action `AGENTS.md`'s Infrastructure-agent-execution rule
requires a human for.

## Why this exists

Per [`docs/operations/2026-08-17-makam-migration-to-yiemvm.md`](../2026-08-17-makam-migration-to-yiemvm.md)
§2 ("Carried (moved)"), the three DB-access files —
`makam_dev_db_password.txt`, `makam_stg_db_password.txt`, `postgres_admin_password.txt` — were
carried **verbatim** from the retired `adrivm` host to the current `yiemvm` host during last
month's migration. They have never been rotated. The original beta-launch plan
([`docs/superpowers/plans/2026-08-18-public-beta-release.md`](../../superpowers/plans/2026-08-18-public-beta-release.md)
Lane D6) treats the dev admin credential as **permanently compromised** by its presence in git
history at some point before this repo's current hygiene discipline was established — not a
specific, dated incident, a standing policy: assume compromise, rotate rather than try to scrub
history. `makam_beta` already got fresh, never-reused access values as a separate, already-built
mitigation (item 8's own "built" note) — this runbook is the same treatment for `makam_dev` and
`makam_stg`, the two databases that still use the carried-over values.

This is a live, present-tense risk, not archival cleanup: `dev.makam.co.id` has been public and
unauthenticated since 25 Jul 2026 ([ADR-0031](../../adr/0031-make-dev-environment-public.md)),
and its nginx access log already shows automated scanner probes. The database access value itself
is not reachable from that public surface (Postgres is on the `backend` network, `internal:
true`, never published to a host port — see
[`docker-compose.dev-stg.yml`](../examples/docker-compose.dev-stg.yml)), so this is defense in
depth, not a response to a demonstrated live compromise path. Rotate it anyway: a value known (or
presumed) to have leaked should not stay valid indefinitely just because no exploit path is
currently visible.

## A real operational trap this runbook exists to avoid

**Replacing the `secrets/*.txt` files and restarting the `postgres` container does NOT rotate
anything.** [`postgres-init/01-create-databases.sh`](../examples/postgres-init/01-create-databases.sh)
(mounted read-only into `/docker-entrypoint-initdb.d/`) only runs once, the first time Postgres
starts against an **empty** data directory. On every later start — including a full
`docker compose restart postgres` or even a container recreate, as long as the `postgres_data`
volume already has data — Postgres logs "Skipping initialization" and the live role's access
value stays exactly what it was when the volume was first created. This is the same failure class
`compose.deployed-reference.yml`'s own comment already documents for a different mistake (silent
`init` skip masking a missing database) — the fix here is the same lesson: **the value must be
changed on the live role directly via SQL**, and the files updated to match, not the other way
around.

## Preconditions — do not proceed until all are true

1. **SSH access to the host confirmed**, per the same pattern
   [`deploy-stg-vhost.md`](deploy-stg-vhost.md) uses:
   ```bash
   ssh -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new yiemvm 'hostname; docker compose -f /opt/makam/compose/compose.yml ps'
   ```
2. **All app services report healthy before you start**, to establish a known-good baseline so
   any regression from this runbook is attributable to it:
   ```bash
   docker compose -f /opt/makam/compose/compose.yml ps
   curl -sS -o /dev/null -w 'dev /up: %{http_code}\n' http://127.0.0.1:8081/up
   curl -sS -o /dev/null -w 'stg /up: %{http_code}\n' http://127.0.0.1:8082/up
   ```
   Both must return `200`. If either does not, stop — fix that first, separately from this runbook.
3. **A backup exists.** Per
   [`docs/operations/backup-and-restore-runbook.md`](../backup-and-restore-runbook.md), take a
   fresh `pg_dump` of both `makam_dev` and `makam_stg` before touching any access value, so there
   is a known-good restore point if a rotation step is fumbled.
4. **Maintenance window, not silent** — this touches live dev/staging database access. Announce
   the window to anyone actively using either environment before Step 2.

## Step 1 — Back up the current files

Do this before generating anything new, so there is a rollback path.

```bash
cd /opt/makam/compose
sudo mkdir -p /opt/makam/backups/secrets
sudo cp -a ./secrets /opt/makam/backups/secrets/secrets-$(date +%Y%m%d-%H%M%S)
```

## Step 2 — Generate three new random values

Use a real random generator, not a memorable/documented value — the entire point of this runbook
is to stop repeating the mistake that made this rotation necessary.

```bash
openssl rand -base64 32 | tr -d '\n=+/' | cut -c1-32 > /tmp/new_postgres_admin_password.txt
openssl rand -base64 32 | tr -d '\n=+/' | cut -c1-32 > /tmp/new_makam_dev_db_password.txt
openssl rand -base64 32 | tr -d '\n=+/' | cut -c1-32 > /tmp/new_makam_stg_db_password.txt
```

Do not echo these values to the terminal, a chat session, or any log. Do not paste them into this
runbook or any commit.

## Step 3 — Rotate the live database role values via SQL (the step the init script cannot do)

Connect as the current admin user (the value for this connection is the OLD, still-valid
`postgres_admin_password` — read it from the current, not-yet-replaced file) and issue
`ALTER ROLE` directly:

```bash
export PGPASSWORD="$(cat ./secrets/postgres_admin_password.txt)"
docker compose -f /opt/makam/compose/compose.yml exec -T postgres \
  psql -U postgres_admin -v ON_ERROR_STOP=1 <<EOSQL
ALTER ROLE postgres_admin WITH PASSWORD '$(cat /tmp/new_postgres_admin_password.txt)';
ALTER ROLE makam_dev_user WITH PASSWORD '$(cat /tmp/new_makam_dev_db_password.txt)';
ALTER ROLE makam_stg_user WITH PASSWORD '$(cat /tmp/new_makam_stg_db_password.txt)';
EOSQL
unset PGPASSWORD
```

Confirm each `ALTER ROLE` returned `ALTER ROLE` (success), not an error, before proceeding — a
partial failure here (e.g. rotating `postgres_admin` but not the two app roles) leaves the system
in a mixed state that Step 5's verification will not cleanly explain.

## Step 4 — Update the files and `.env.*` files to match

The files feed the (now-inert, until a full volume recreate) init script and nothing else
live-reads them again — but keep them accurate for the next time this host's Postgres volume is
genuinely rebuilt from scratch, so a future recreate doesn't silently reintroduce the old values.

```bash
cd /opt/makam/compose
sudo cp /tmp/new_postgres_admin_password.txt ./secrets/postgres_admin_password.txt
sudo cp /tmp/new_makam_dev_db_password.txt ./secrets/makam_dev_db_password.txt
sudo cp /tmp/new_makam_stg_db_password.txt ./secrets/makam_stg_db_password.txt
sudo chown 999:999 ./secrets/*.txt
sudo chmod 0400 ./secrets/*.txt
```

Then update `DB_PASSWORD` in `.env.dev` (the `makam_dev_user` value) and `.env.stg` (the
`makam_stg_user` value) to the same new values — these are the files the running `dev-web`,
`stg-web`, `stg-horizon`, `dev-worker`, and `stg-batch-worker` containers actually read their
connection values from (per `docker-compose.dev-stg.yml`'s `env_file:` entries), separately from
the `secrets:` mechanism which only `postgres` itself consumes. Edit these directly on the host;
do not create a script that echoes the new value to stdout or a log.

## Step 5 — Recreate the app containers so they pick up the new `.env.*` values

`docker compose restart` does not re-read `env_file:` changes — only a recreate does:

```bash
cd /opt/makam/compose
docker compose up -d --force-recreate dev-web stg-web stg-horizon dev-worker stg-batch-worker
```

`postgres` itself does not need recreating — its live role values were already changed in-place
by Step 3; the container process keeps running throughout.

## Step 6 — Verify

```bash
docker compose -f /opt/makam/compose/compose.yml ps
curl -sS -o /dev/null -w 'dev /up: %{http_code}\n' http://127.0.0.1:8081/up
curl -sS -o /dev/null -w 'dev /health/ready: %{http_code}\n' http://127.0.0.1:8081/health/ready
curl -sS -o /dev/null -w 'stg /up: %{http_code}\n' http://127.0.0.1:8082/up
curl -sS -o /dev/null -w 'stg /health/ready: %{http_code}\n' http://127.0.0.1:8082/health/ready
```

All four must return `200`. `/health/ready` specifically exercises a real `select 1` against
Postgres (per `docs/superpowers/plans/2026-08-18-public-beta-release.md` Lane E1), so a stale
value surfaces here even if `/up` alone would not catch it. Also confirm no container is
crash-looping on the new values:

```bash
docker compose -f /opt/makam/compose/compose.yml logs --since 5m postgres dev-web stg-web | grep -iE "authentication failed|FATAL"
```

Expect no output. Any hit here means Step 3 or Step 4 was incomplete or inconsistent — stop and
diagnose before declaring this rotation done; do not proceed to Step 7 with a service silently
failing to connect.

## Step 7 — Clean up

```bash
shred -u /tmp/new_postgres_admin_password.txt /tmp/new_makam_dev_db_password.txt /tmp/new_makam_stg_db_password.txt
```

Confirm the Step 1 backup directory (`/opt/makam/backups/secrets/secrets-<timestamp>`) contains
the OLD values, not the new ones — that backup is now sensitive (it holds the just-retired but
recently-valid values) and should be handled with the same care as the live files (owner-only
permissions), not left world-readable.

## Rollback

If Step 5 or Step 6 shows a broken app container that cannot be quickly diagnosed:

```bash
cd /opt/makam/compose
export PGPASSWORD="$(cat /opt/makam/backups/secrets/secrets-<timestamp>/postgres_admin_password.txt)"
docker compose exec -T postgres psql -U postgres_admin -v ON_ERROR_STOP=1 <<EOSQL
ALTER ROLE postgres_admin WITH PASSWORD '$(cat /opt/makam/backups/secrets/secrets-<timestamp>/postgres_admin_password.txt)';
ALTER ROLE makam_dev_user WITH PASSWORD '$(cat /opt/makam/backups/secrets/secrets-<timestamp>/makam_dev_db_password.txt)';
ALTER ROLE makam_stg_user WITH PASSWORD '$(cat /opt/makam/backups/secrets/secrets-<timestamp>/makam_stg_db_password.txt)';
EOSQL
unset PGPASSWORD
sudo cp /opt/makam/backups/secrets/secrets-<timestamp>/*.txt ./secrets/
sudo chown 999:999 ./secrets/*.txt && sudo chmod 0400 ./secrets/*.txt
# Revert DB_PASSWORD in .env.dev / .env.stg to the old values, then:
docker compose up -d --force-recreate dev-web stg-web stg-horizon dev-worker stg-batch-worker
```

This restores the pre-rotation state exactly — the old values are still valid at this point
(Step 3 only changed them forward; nothing before that has been undone) — and re-diagnose from a
known-good baseline before attempting the rotation again.

## What this runbook does not cover

- **`makam_beta`'s access values** — already rotated (fresh, never-reused values) as a separate,
  already-built mitigation per ADR-0035 item 8.
- **Redis authentication** — ADR-0035 Lane D5 (`requirepass` not yet configured at all) is a
  distinct, separate, not-yet-built item, not a rotation of an existing value.
- **Host-level access** (`gh` auth, SSH keys, the Cloudflare token, `~/.docker/config.json`)
  named in the 17 Aug migration's "Carried" inventory — those were moved, not exposed via git
  history the way the dev admin database value was; no evidence found that they need the same
  treatment. If a future audit finds otherwise, treat as a separate runbook.
- **Production access values** — no production environment exists yet (per this repo's own
  Phase 0/Phase 3 release-readiness roadmap); this runbook is dev/staging-only.
