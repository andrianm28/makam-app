# Host RAM Reclamation Design — `adrivm` (2 vCPU / 3.8 GiB)

**Date:** 15 Aug 2026
**Status:** Draft (approved by user 15 Aug 2026, pending written review)
**Scope:** Reclaim RAM/swap on the combined development/staging host to keep it viable on 2 vCPU / 4 GB, deferring the documented upgrade trigger in `docs/operations/dev-staging-environment.md` §15.
**Depends on:** nothing (host-level ops work; no application code changes).

## 1. Goal

Reduce swap pressure and stop OOM risk on `adrivm` (Ubuntu 22.04, 2 vCPU / 3.8 GiB) by stopping idle AI-tooling processes that are not part of the application stack, then add a lightweight guardrail so leftover dev servers cannot silently re-degrade the host. Per `performance-and-capacity.md` §9 and `dev-staging-environment.md` §15, sustained memory above 80% remains an upgrade trigger; this change is a stopgap, not a capacity fix.

Baseline (15 Aug 2026, pre-change):

| Metric | Value |
|---|---|
| Total RAM | 3.8 GiB |
| Used | 3.1 GiB |
| Available | ~393 MiB (~10%) |
| Swap | 3.5 GiB of 9.5 GiB used (8G swapfile + 1.5G zram, zram full) |
| Load average | 2.26 on 2 vCPU |

## 2. In scope

- Stop 4 idle processes + 1 container that consume ~1 GB of swap and ~40 MB RSS (all verified idle for ≥2 days).
- One cron-driven guardrail script with `--dry-run` mode that stops `vite` / `php -S` dev servers aged >24 h whose cwd is under `.worktrees/*`.
- Hourly one-line memory/swap snapshot log with a WARN marker when available RAM <15%.
- Documentation: runbook entry and a note in `dev-staging-environment.md`.
- Verification: memory/swap measurements, app + container health checks, dry-run review before enabling cron.

## 3. Out of scope / explicitly kept

| Keep | Reason |
|---|---|
| opencode session PID 1212184 (1.57 GB RSS + 2.63 GB swap) | user decision |
| opencode current session PID 3604763 | active session |
| claude makamapp PID 38904 (~581 MB swap) | user decision |
| hermes-agent gateway PID 3125130 (~151 MB RSS + 306 MB swap) | user decision |
| All Postgres containers (main + 4 verify DBs) | user decision — even though they carry no memory limits |
| Exited Docker containers | user decision |
| php-fpm / nginx / Redis / makam-notify / compose web container | application stack |

Not included: Approach B (tuning app stack to the documented budget) — `shared_buffers=256MB` would *increase* PG memory, the doc budget sums to >4 GB, and marginal real savings do not justify the churn. No application code changes anywhere.

## 4. Design

### 4.1 Immediate reclamation (one-time)

SIGTERM the following (all verified idle):

| Target | PID / name | Swap | Notes |
|---|---|---|---|
| `kirocrew gateway --port 5476` | 1273441 | ~585 MB | idle 7 days; no supervisor (PPID 1), restart manually |
| vite dev server, `.worktrees/opencode-playwright-harness` | 1777496 | ~181 MB | leftover from a branch session |
| vite dev server, `.worktrees/opencode-playwright-harness` | 1782159 | ~175 MB | leftover from a branch session |
| `php8.5 -S 127.0.0.1:8090`, `.worktrees/fix-booking-step5-handoff` | 2026727 | ~53 MB | leftover dev server |
| github-mcp-server container | `friendly_northcutt` | ~5 MB | **broken**: env `GITHUB_PERSONAL_ACCESS_TOKEN` contains an error string from a failed `gh auth` lookup at creation; non-functional |

Restart commands (documented, not run automatically):
- kirocrew: `/home/ubuntu/.local/bin/kirocrew gateway --port 5476`
- vite: `npm run dev` from the worktree
- php -S: re-run the `artisan serve`-equivalent for the branch
- mcp server: `docker run` with a correctly-resolved `GITHUB_PERSONAL_ACCESS_TOKEN` via `gh auth token`

Expected effect: ~1 GB swap freed, ~40 MB RSS freed, zram pressure drops.

### 4.2 Guardrail — idle-leftover cleaner (cron, no continuous workers)

One bash script `/opt/makam/scripts/cleanup-idle-dev.sh` + a root cron entry running hourly:

- Selects processes matching `node .../node_modules/.bin/vite` or `php8.5 -S` **whose cwd is under `/home/ubuntu/makam-app/.worktrees/`** and whose elapsed time is **>24 h**.
- SIGTERM each match; append an action line (timestamp, PID, command) to `/opt/makam/logs/cleanup-idle-dev.log`.
- `--dry-run` prints what it would stop without acting (used for verification before enabling).
- Never matches opencode/claude/hermes/kirocrew (different command patterns) or any compose-managed container (checked via `docker ps` only for the specific mcp container name if that decision is reversed).
- Age threshold (>24 h) prevents killing a dev server started in the current session.

Per `dev-staging-environment.md` §14 and AGENTS.md, a cron job is not a continuous worker; no new always-on daemons are added.

### 4.3 Memory watch (same cron entry)

The same script appends a one-line snapshot to `/opt/makam/logs/mem-watch.log` hourly:
`timestamp | available MiB | used MiB | swap used MiB | top-5 RSS commands`
with a `WARN` prefix when available RAM <15% (~570 MiB). This is durable signal for the §15 capacity review — no alerts, no daemons.

### 4.4 Documentation

- New entry in the operations runbooks (`docs/operations/runbooks.md` or a host-runbook file): baseline numbers, stop list, restart commands, guardrail script location + cron line, memory-watch log location.
- Note in `docs/operations/dev-staging-environment.md` §6/§15: the reclamation was applied 15 Aug 2026; the >80% upgrade trigger remains active.

## 5. Error handling and failure modes

- **Wrong process killed:** guardrail matches are narrow (cwd under `.worktrees/` + age >24 h + exact command patterns). The one-time stop list is PID-specific after individual verification.
- **Cron script errors:** every branch is guarded; script exits nonzero on unknown/unreadable paths but takes no destructive action on error; logs the error line.
- **Restart need later:** documented restart commands; kirocrew is PPID 1 with no supervisor, so a manual restart is explicit (no silent auto-restart hiding the reclamation).
- **Broken mcp container:** stopped; it was non-functional due to the bad token env — no functional loss.

## 6. Verification & success criteria

1. Post-change `free -h`: swap ≤ ~2.5 Gi (baseline 3.5), available ≥ ~500 MiB (baseline 393), load decreased.
2. `dev.makam.co.id` serves; nginx, php-fpm, Postgres (main + verify), Redis, compose web container all healthy (`docker ps`, systemctl).
3. `cleanup-idle-dev.sh --dry-run` output reviewed and no live targets listed before enabling the cron entry.
4. Guardrail cron entry installed; first run logs a memory snapshot with no unintended stops (confirmed via log + `ps`).
5. No OOM events (`dmesg`/`journalctl -k` scan).
6. Documentation committed.

## 7. Effort and risk

- Effort: ~1–2 h (one-time stop + script + cron + docs + verification).
- Risk: Low. Everything stopped is provably idle; guardrail is conservative and dry-run verifiable; no application-stack changes.
