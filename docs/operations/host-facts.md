---
last-verified: 2026-09-13
verified-by: AI agent (Claude Opus 5), executing on the host itself
canonical-for: host hardware, OS, container runtime, running services, firewall
---

# Canonical host facts — `yiemvm`

**This file is the single source of truth for what the non-production host
actually is.** Per `AGENTS.md` §Documentation ("Do not duplicate canonical
catalog data in multiple hand-maintained documents or code locations"), no
other document may restate these numbers. Reference this file instead.

`ci/verify-docs.sh` **GATE 16** enforces that: the host-spec literals below
may not appear outside this file, outside `docs/adr/`, and outside the dated
historical records listed in the gate's own allowlist.

## Why this file exists

Before it, the host's specification was restated in at least seven documents,
and **every one of them was wrong**. They described a 2 vCPU / 4 GB /
Ubuntu 22.04 machine — the retired `adrivm` host — while the stack has run on
an 8 vCPU / 31 GB / Ubuntu 24.04 machine since the 17 Aug 2026 migration
(`2026-08-17-makam-migration-to-yiemvm.md`). Capacity planning, swap
thresholds and "this host is too small for X" judgements were all being made
against a machine that no longer existed.

That is the failure mode this file plus GATE 16 exist to prevent: not one
wrong number, but the same wrong number copied into seven places where
correcting one does not correct the rest.

## Hardware and OS

| Fact | Value | Verified by |
|---|---|---|
| Hostname | `yiemvm` | `hostname` |
| OS | Ubuntu 24.04.4 LTS | `/etc/os-release` |
| Kernel | 6.8.0-49-generic | `uname -r` |
| CPU | 8 vCPU | `nproc` |
| RAM | 31 GB | `free -g` |
| **Swap** | **0 MiB — there is no swap device** | `free -m` |
| Disk | 96 GB total, 21 GB free (79% used) | `df -h /` |

The swap row matters more than it looks. Two documents previously disagreed
with each other about it — one said "2–4 GB swap", another recorded a measured
9678 MiB — and **both were wrong**: this host has no swap at all. Any alert,
threshold or capacity argument that assumes swap exists as a cushion is
assuming something the machine does not have. An OOM here kills a container
outright; it does not swap first.

## Container runtime

| Fact | Value | Verified by |
|---|---|---|
| Docker | 29.1.3 | `docker --version` |
| Compose | 2.40.3 | `docker compose version` |
| Compose project | `makam-nonprod` | `docker compose ps` |
| Compose file | `/opt/makam/compose/compose.yml` | on disk |

## Services actually running

Seven, and no others:

| Compose service | Container | Notes |
|---|---|---|
| `postgres` | `makam-nonprod-postgres-1` | PostgreSQL 18.6 |
| `redis` | `makam-nonprod-redis-1` | Redis 8.2.9 |
| `dev-web` | `makam-nonprod-dev-web-1` | PHP 8.5.8 |
| `beta-web` | `makam-nonprod-beta-web-1` | |
| `beta-worker` | `makam-nonprod-beta-worker-1` | **plain `php artisan queue:work`, not Horizon** |
| `beta-scheduler` | `makam-nonprod-beta-scheduler-1` | |
| `stg-placeholder` | `makam-nonprod-stg-placeholder-1` | `nginx:alpine` — a placeholder page, not the application |

Three consequences that contradict what several runbooks still assume:

- **There is no `stg-web` and no `stg-horizon`.** Staging is a static nginx
  placeholder. Any runbook step that restarts, rolls back or exec's into a
  staging application container is describing something that does not exist.
- **There is no `dev-worker` and no `dev-scheduler`.** Queued work and
  scheduled tasks do not run for the dev environment at all.
- **`beta-worker` runs `queue:work`, not Horizon** — the exact command is
  `php artisan queue:work --queue=critical,urgent,notifications,default
  --tries=3 --max-time=3600`. `config/horizon.php` does exist in the
  repository, but nothing on this host supervises queues with it. A rollback
  or hardening step that expects a persistent Horizon supervisor to restart
  has nothing to restart.

## Application health endpoints

Both exist and are routed: `/health/live` and `/health/ready`
(`routes/web.php`, `HealthLiveController` / `HealthReadyController`), plus the
framework's own `/up`. Documents claiming these routes do not exist are
describing a state that ended before this file was written.

## Firewall

`ufw` active; `22/tcp`, `80/tcp`, `443/tcp` and `4096/tcp` allowed.

## How to re-verify

Every row above came from one of these, run on the host:

```bash
hostname; uname -r; nproc
free -g; free -m; df -h /
. /etc/os-release && echo "$PRETTY_NAME"
docker --version; docker compose version
docker compose -f /opt/makam/compose/compose.yml ps
docker inspect makam-nonprod-beta-worker-1 --format '{{json .Config.Cmd}}'
sudo ufw status
```

When you re-run them, update the table AND the `last-verified` date in this
file's front matter. A stale `last-verified` is the honest signal that these
numbers may have drifted; silently leaving the date alone while editing a
number is worse than not editing it.
