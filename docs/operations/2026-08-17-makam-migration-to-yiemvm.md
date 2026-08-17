# Makam Environment Migration — adrivm → yiemvm

**Date:** 17 Aug 2026
**Status:** Draft (approved by user 17 Aug 2026, pending written review)
**Scope:** Full replacement of the combined dev+staging host. yiemvm (`103.92.214.243`, user `ubuntu`, SSH alias in `~/.ssh/config`) becomes the new single host for the makam non-production stack; adrivm (this host, `Ubuntu 22.04`, 2 vCPU/3.8 GB, 59 GB disk at 87%) is retired after verification.
**Authorities:** `docs/operations/ai-agent-dev-stg-setup-prompt.md` (combined-host provisioning), `docs/operations/ai-agent-dev-stg-execution-checklist.md` (gates before SSH/DNS/firewall/destructive ops), `docs/operations/dev-staging-environment.md` (environment constraints), AGENTS.md (secrets/keys discipline, no production data).

## 1. Goal

Move the complete makam non-production environment to yiemvm with zero data loss and a reversible cutover: images + volume data + repo (with its worktrees/harness/ledgers) + all workflow credentials + config/secrets. Pre-stage everything, verify by direct IP, flip ONE DNS record, keep adrivm running until verification passes, rollback = flip the record back.

## 2. Scope

**Carried (moved):**
- Container images (image-migrate mandate): the deployed `ghcr.io/andrianm28/makam-app` digest, `postgres:18`, `redis:8.2-alpine` — `docker save` → transfer → `docker load`.
- PostgreSQL data (`makam_dev` + `makam_stg`): the `makam-nonprod_postgres_data` volume via volume-backup tar → restore into a fresh volume on yiemvm; `pg_dump` of both DBs as the verification cross-check.
- The repo (`/home/ubuntu/makam-app`): `git bundle` (ALL refs — the lane/worktree branches) + tar of untracked state (`.worktrees/` incl. the opencode-playwright harness, `.superpowers/` ledgers, `opencode.json`, `database.sqlite`); verify `git rev-parse HEAD` + `git worktree list` on both ends.
- Compose + env + scripts: `/opt/makam/compose/compose.yml`, `.env.dev`, `cleanup-idle-dev.sh`.
- Secrets: the three DB-password files (`makam_dev_db_password.txt`, `makam_stg_db_password.txt`, `postgres_admin_password.txt`).
- Credentials (inventory first, encrypted transfer, verified on yiemvm): `gh` auth (`~/.config/gh`), docker registry auth (`~/.docker/config.json` — ghcr pull), `~/.ssh/` (the new ed25519 key + `authorized_keys`), `~/.gitconfig` credential helper, the Cloudflare token (DNS zone — used for the A-record flip or the flip runs via the dashboard), kiro CLI state, and any other store the inventory finds. Provider-sandbox creds (SumoPod etc.) live inside `.env.dev` and move with it.
- `APP_KEY` from `.env.dev` — carries WITH the environment: encrypted-at-rest state (MFA enrolments, vault documents) must stay decryptable. (The "separate keys across environments" rule is about dev-vs-stg-vs-prod; dev's key moves to the new dev host.)

**Rebuilt fresh on yiemvm:** redis (disposable cache/sessions), docker networks (`makam-nonprod_backend`/`egress`), secret file ownership (`lxd:docker` per the compose notes), the `stg-placeholder` nginx container.

**NOT moved:** production data (none exists — gates closed); CI (GitHub Actions, untouched).

## 3. Phases

### Phase 0 — Access + checklist gate (BLOCKING)
- Verify SSH reachability to yiemvm: `ssh -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=accept-new yiemvm 'hostname; uname -r; nproc; free -h; df -h /; docker --version; docker compose version'` (bypass the kiro SSH wrapper with explicit `-o`/`-F /dev/null` as needed).
- Run `docs/operations/ai-agent-dev-stg-execution-checklist.md` before ANY yiemvm write; PAUSE on: SSH lockout risk, DNS ownership ambiguity, missing provider secrets, destructive database/volume changes.
- Confirm yiemvm: Ubuntu 22.04/24.04, Docker + Compose plugin, ≥ 2 vCPU/4 GB, ≥ 15 GB free disk (the adrivm stack uses ~8 GB + the repo ~2 GB + headroom).

### Phase 1 — Inventory + backup (adrivm, read-only)
- Credential inventory: `gh auth status`, `~/.docker/config.json`, `~/.ssh/`, `~/.gitconfig`, cloudflare token stores (`~/.cloudflare`, env, token files), kiro state, plus a scan for other secret files in `~` (`find ~ -maxdepth 3 -name '*token*' -o -name '*credential*'` reviewed by the user).
- DB inventory: `pg_dump` both DBs to `/opt/makam/backups/` (ensure disk headroom — stream if tight), record row counts per table + one known UAT record per DB (the verification baseline).
- Image inventory: `docker image ls` digests; record the exact deployed app digest from compose.yml.

### Phase 2 — Transfer (adrivm → yiemvm)
- Images: `docker save` (app digest + postgres:18 + redis:8.2-alpine) → scp → `docker load`; verify digests.
- Volume: volume-backup tar (`docker run --rm -v makam-nonprod_postgres_data:/data -v /opt/makam/backups:/backup alpine tar -C /data -czf /backup/pg-data.tar.gz .`) → scp → restore into a fresh volume on yiemvm.
- Repo: `git bundle create /opt/makam/backups/makam-all.bundle --all` + tar of the untracked state → scp → extract; verify HEAD + worktree list.
- Config/secret/scripts: scp with preserved modes; re-apply the secret ownership + 0400 perms on yiemvm.
- Credentials: one encrypted archive (tar + age/gpg if available on both hosts, else scp over the SSH channel) → extract on yiemvm → **verify each**: `gh auth status`, `docker pull` (ghcr), `ssh -T git@github.com` (with the new key), a Cloudflare read-only API call (list the dev.makam.co.id record), kiro CLI smoke.

### Phase 3 — Stage + verify on yiemvm (no DNS change)
- `docker compose up -d` on yiemvm (same project name `makam-nonprod`, same ports `127.0.0.1:8081`).
- Health: `docker compose ps` all healthy; `curl --resolve dev.makam.co.id:443:103.92.214.243 https://dev.makam.co.id/up` → 200.
- Data verification: PG row counts match the Phase-1 baseline; the known UAT records exist; a `psql` spot check of `makam_dev` + `makam_stg`.
- App smoke by direct IP: `/admin` login (MFA recovery code — MFA must still verify, proving APP_KEY carried correctly), one public surface (`/preneed`, `/kunjungan`, `/m/{token}`), one admin resource.
- PAUSE GATE (DNS ownership): present the verification results; the A-record flip requires explicit user confirmation (Cloudflare token use OR the user runs the flip in the dashboard).

### Phase 4 — Cutover (ONE DNS flip)
- Update the `dev.makam.co.id` A record: `43.133.147.136` → `103.92.214.243` (Cloudflare; record change only, no zone transfer).
- Post-cutover verification over the public domain: TLS, `/up`, `/admin` login, the UAT surfaces.
- adrivm's stack stays RUNNING (rollback = flip the record back; nothing on adrivm changes).

### Phase 5 — Rollback path (if post-cutover verification fails)
- Flip the A record back to `43.133.147.136`; adrivm serves as before (its compose stack was never stopped). Diagnose on yiemvm while traffic is back on adrivm.

### Phase 6 — Decommission (only after ≥ 7 days green on yiemvm)
- Stop adrivm's compose stack (`docker compose down` — keep the data volume as a cold standby; no deletion without user confirmation).
- Retire adrivm (user decision; host out of scope).

## 4. Verification checklist (the definition of done)

- [ ] SSH to yiemvm works with the checklist gates passed.
- [ ] `docker images` digests match adrivm's.
- [ ] PG row counts match per table; the known UAT records present on yiemvm.
- [ ] `git rev-parse HEAD` + `git worktree list` match; the harness runs on yiemvm.
- [ ] `gh auth status`, docker ghcr pull, `ssh -T git@github.com`, Cloudflare read, kiro smoke all pass on yiemvm.
- [ ] Stack healthy on yiemvm; direct-IP `/up` + `/admin` login (MFA) + public surfaces green.
- [ ] DNS flip done with user confirmation; public-domain verification green.
- [ ] Rollback path documented and untouched (adrivm stack still running until green).

## 5. Risks & mitigations

| Risk | Mitigation |
|---|---|
| APP_KEY mismatch breaks encrypted state | Carried verbatim; verified by an MFA login on yiemvm BEFORE cutover. |
| PG version/architecture drift | Both hosts run PostgreSQL 18; verification via pg_dump cross-check. |
| Disk-full during transfer (adrivm 87%) | Phase-1 inventory first; stream/size the artifacts; abort-safe if `/opt/makam/backups` lacks headroom (stage on yiemvm directly via pipe). |
| DNS flip failure/ambiguity | Pause gate before the flip; rollback = flip back; adrivm untouched. |
| SSH lockout on yiemvm | Checklist gate; the existing `authorized_keys` preserved; new key ADDED, never replacing, until verified. |
| Credential leak in transit | SSH transport is encrypted; the archive is integrity-checked; Cloudflare token handled per the checklist. |
| Lost adrivm state | Everything carried is verified on yiemvm before cutover; adrivm's volumes untouched until decommission. |

## 6. Out of scope

- Production provisioning (gates closed; yiemvm is the nonprod host).
- CI reconfiguration (GitHub Actions untouched).
- DNS zone transfer or any Cloudflare change beyond the single A-record flip.

## Addendum (17 Aug 2026, same day) — coming-soon landing page + makam.co.id TLS

Executed after the core cutover, per user request:

- **Landing page (makam.co.id + www):** the nginx site (`makam.co.id.conf`, static root `/var/www/makam.co.id`, HSTS/security headers, static caching) carried from adrivm to yiemvm; the apex + www A records already pointed at yiemvm (Domainesia, user-flipped).
- **Notify service:** `makam-notify.service` (node `--experimental-sqlite` email subscriber service on 127.0.0.1:3001, proxied by nginx at `/api/notify`) migrated — files (`/opt/makam-notify/server.js`), the subscribers SQLite DB (`/var/lib/makam-notify/subscribers.db` — carried data verified), the systemd unit; Node 22 LTS installed on yiemvm (required for `node:sqlite`; adrivm ran v24).
- **TLS fix:** the carried Let's Encrypt cert for makam.co.id serves correctly (valid to 8 Oct 2026); certbot renewal on yiemvm was BROKEN ("nginx plugin not installed") — fixed by installing `python3-certbot-nginx`; `certbot renew --dry-run` now succeeds for makam.co.id. The other carried renewal entries (adri.web.id, fund*) are out of scope (their vhosts live on other hosts/accounts).
- **Verified:** landing 200 over TLS, HTTP→HTTPS 301, HSTS headers, notify POST 200 with the carried subscriber data intact.
