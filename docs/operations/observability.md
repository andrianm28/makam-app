# Observability Implementation — Dev/Staging Host (S2-T10)

Status: partial implementation. This document is the runbook for what is
actually wired up on the combined dev/staging host today, what a human or
script should look at, and what remains blocked on work outside this task's
scope. It implements a slice of `dev-staging-environment.md` §13 and
`observability-stack.md` §7; it does not replace either — read those first
for the full target state, and `performance-and-capacity.md` for capacity
context.

This document, `config/logging.php`, and
`docs/operations/examples/monitoring-check.sh` are the three files this
task (Batch 2.7 / S2-T10, `docs/planning/agent-execution-plan.md` §4) owns.
Container networking, secrets, nginx, Redis, and backup prep are owned by
other concurrent agents in the same batch and are out of scope here.

## 1. What is monitored, and how

| Area | Mechanism | Status |
|---|---|---|
| Compose config validity, port reachability, bind-mount permissions, Postgres init, environment isolation, secret ownership, dev/prod site reachability, ACME path | `ci/verify-infra.sh` (existing, not owned by this task) | Implemented — run on the deployment host only |
| Host memory / swap / disk / container status | `docs/operations/examples/monitoring-check.sh` (this task) | Implemented — read-only, pass/warn/fail output |
| Structured application/job logs | `config/logging.php` `json` channel (this task) | Implemented, opt-in (see §2) |
| Container restart visibility | `monitoring-check.sh` (heuristic; `docker inspect` restart count) | Implemented, best-effort |
| `/health/live`, `/health/ready` deployment checks (`ci-cd-and-release.md` §8) | Application routes | **Not implemented — blocked, see §4** |
| Staging Horizon queue wait/failure visibility, Pulse | Laravel Horizon/Pulse dashboards | Not started — no Horizon/Pulse configuration exists yet in this repo |
| PostgreSQL connections/locks/storage, Redis memory/latency/eviction | Managed metrics or exporters | Not started — no metrics exporter configured; out of scope for this task (compose/Redis ownership sits with other Batch 2.7 agents) |
| External error tracking | Third-party SDK (Sentry/Flare/etc.) | Not started — no package installed, no `.env` keys, not evaluated in this task |
| Staging uptime check | External uptime service or `ci/verify-infra.sh` GATE I9/I11 | Partially covered by GATE I9/I11 (reachability + noindex), not a dedicated uptime monitor |
| Disk and remote-backup alerts | — | Not started; backup prep is owned by a different Batch 2.7 agent |

`ci/verify-infra.sh` already owns the live-stack gates (compose validity,
network/port topology, bind-mount permissions, Postgres init, isolation,
secret ownership, and the dev/prod HTTP reachability checks). This document
does not duplicate that script's content — see it directly for the current
gate list and the historical defects (C-2, N-4, N-5, N-6) each gate exists
to catch.

## 2. Structured logging

`config/logging.php` adds a `json` Monolog channel (driver `monolog`,
`RotatingFileHandler` + `JsonFormatter`, writing to
`storage/logs/laravel-json.log`, rotated on the same `LOG_DAILY_DAYS`
retention — default 14 days — as the stock `daily` channel). It is **not**
wired into the default `stack` channel, so the existing `LOG_CHANNEL=stack`
/ `LOG_STACK=single` defaults in `.env.example` are unchanged by this
task — that file is not owned by this task and was not edited.

To use it in an environment's `.env` (dev or staging host, set outside this
repo per `ci-cd-and-release.md` §10):

```
LOG_CHANNEL=json
# or, to keep the plain-text file too:
LOG_STACK=single,json
```

### Field shape

Target field shape is the one already specified in
`observability-stack.md` §3 (`timestamp`, `level`, `environment`, `service`,
`release`, `request_id`/`trace_id`, `actor_type`, `domain_reference`,
`operation`, `result`, `latency_ms`, `provider`, `error_class`). Monolog's
`JsonFormatter` emits whatever is in the log record (message, context,
extra, level, channel, datetime) — `config/logging.php` cannot force call
sites to populate that field list. `Log::withContext()` /
`Log::shareContext()` (e.g. a request-id assignment middleware) are how an
application actually gets `request_id` onto every line; no such middleware
exists in this repository yet, so today the `json` channel's records carry
Monolog's default fields only, not the full target shape.

### What IS and IS NOT redacted by default

Verified against the Laravel 13.x docs (`laravel.com/docs/13.x/errors`,
`laravel.com/docs/13.x/validation`, `laravel.com/docs/13.x/logging`,
fetched 25 Jul 2026) plus the framework's long-standing, cross-version
`Illuminate\Foundation\Exceptions\Handler::$dontFlash` behavior, which the
13.x docs pages no longer restate verbatim — noted here as a real limit on
how far this was verified, not asserted from memory alone:

**IS covered, automatically, no configuration needed:**
- When a `ValidationException` redirects back with old input flashed to the
  session (`validation.md`: "the framework will automatically flash all of
  the request's input to the session"), Laravel's base exception handler
  excludes a small hard-coded field-name list — `password`,
  `password_confirmation`, `current_password` — from that flash. This is a
  **session-flash** protection so a failed-login/password form doesn't
  echo the password back into `old()`. It is not configurable from
  `config/logging.php` and, importantly, it is **not a log-output control**
  at all — it never touches what gets written to `storage/logs/`.

**Is NOT covered by any Laravel default — still the calling code's job on
every call site:**
- Anything passed to `Log::info()`/`warning()`/`error()`/etc. as a message
  or context array is written verbatim (JSON-encoded, on the `json`
  channel above).
- `Exception::context()` on a custom exception class, and the global
  `$exceptions->context()` closure in `bootstrap/app.php` (currently
  unused in this repo) — Laravel adds the authenticated user ID to every
  exception log line automatically, but any other fields a developer adds
  here are logged as-is.
- Job/queue payloads, HTTP client request/response logging, webhook
  payload logging, Horizon tags, Pulse entries.
- Full documents, signed URLs, payment credentials, identity numbers,
  or any other restricted data per `observability-stack.md` §3's explicit
  "never log" list and `AGENTS.md` §Observability ("Never place restricted
  data in logs, Pulse, Horizon tags, or error trackers").

**Practical consequence:** enabling the `json` channel changes log *shape*,
not log *content safety*. It does not add or remove any redaction. Every
call site that logs request/job/webhook data is still individually
responsible for not including restricted data, exactly as before this
change.

## 3. Host resource thresholds

Sourced from `dev-staging-environment.md` §6 (resource budget table) and
§15 (upgrade triggers), which is the authority for this host — figures
below are not invented, and where §6 does not specify a number that is
called out explicitly rather than silently filled in.

Host: Ubuntu 22.04, 2 vCPU / 4 GB RAM, 2–4 GB swap (§6 line 10–11; this is
the swap *device size*, not an alert threshold).

| Signal | Source | Threshold used by `monitoring-check.sh` |
|---|---|---|
| Memory | §6: "Persistent normal memory above 80%... trigger[s] capacity review"; §15: "steady memory above 80%" | WARN at ≥70% used, FAIL-tier flag at ≥80% used. The 80% figure is the one directly stated in the spec; 70% is this script's own early-warning margin, not a spec number — labelled as such in the script's output. |
| Swap | §6: "sustained swap use... trigger[s] capacity review"; §15: "sustained swap activity or any OOM kill" | Any non-zero swap usage is flagged WARN, never a hard FAIL from a single reading. §6/§15 both say *sustained*, and one script invocation cannot establish sustained — see the caveat in §3.1 below. |
| Disk | Not specified anywhere in `dev-staging-environment.md` (no disk figure exists in §6 or elsewhere in that document); `observability-stack.md` §7 lists "disk pressure" as an alert category but gives no number either | 80% WARN / 90% FAIL — a conventional default, explicitly **not** sourced from this repo's docs, overridable via `DISK_WARN_PCT`/`DISK_FAIL_PCT` env vars on the script. Flagged here as a gap: `dev-staging-environment.md` §6 should probably get an explicit disk figure in a future revision of that document (out of scope for this task, which owns only the three files listed at the top). |
| Container health/restarts | §13: "container restart and memory visibility" | `docker ps`/`docker inspect` on the `makam-nonprod-*` containers (naming convention taken from `ci/verify-infra.sh`), read-only |

### 3.1 Why "sustained" can't be a single-run threshold

A single execution of `monitoring-check.sh` is a point-in-time sample. It
cannot itself distinguish a one-off blip from the "persistent"/"sustained"
condition §6 and §15 actually gate an upgrade decision on. The script flags
what it sees at the moment it runs; turning that into a sustained-condition
signal requires running it repeatedly (cron, systemd timer, or an
interactive `watch`) and looking at the trend across runs. Nothing in this
task wires up that scheduling — see §5.

## 4. Deployment health checks — blocked dependency

`ci-cd-and-release.md` §8 references `/health/live` and `/health/ready` as
deployment checks:

> - `/health/live`: process alive, no dependency requirement.
> - `/health/ready`: database/Redis/config readiness without exposing
>   secrets.

**Neither route exists in this repository yet.** `routes/web.php` has no
routes at all (see that file's own header comment — nothing is served from
`/` until the homepage spec ships in Sprint 4), and `bootstrap/app.php`
only registers Laravel's single built-in health route at `/up`
(`health: '/up'`, via `withRouting()`), which `ci/verify-infra.sh` GATE I9
already checks. `/up` is a liveness-only check with no dependency
verification — it is not equivalent to `/health/ready`.

This task does not add `/health/live` or `/health/ready` routes. Fabricating
them here would be out of scope (they are application routes, not
observability config/docs/scripts, and this task's file ownership is
explicitly limited to `config/logging.php` and two new docs/scripts files)
and would risk diverging from whatever contract the eventual feature spec
defines for readiness (e.g. what "config readiness" checks, exactly).
**This is a known, explicit dependency this task is blocked on**, not an
oversight: someone implementing the relevant application routes needs to
add `/health/live` and `/health/ready`, at which point
`monitoring-check.sh` and `ci/verify-infra.sh` can both be extended to call
them.

## 5. What happens when a threshold is crossed

This is a non-production, resource-constrained combined dev/staging host,
not a paged production service. `observability-stack.md` §7 (lightweight
non-production profile) already sets this expectation ("Lightweight
non-production observability does not replace production monitoring
acceptance"). Consistent with that:

- A threshold breach is **logged and visible in `monitoring-check.sh`'s
  output** (WARN/FAIL lines to stdout, non-zero exit code on FAIL) — it is
  not wired to page, email, or Slack anyone. Building an alerting channel
  is out of scope for this task and, per the reasoning in §3.1, premature
  before the script is even run on a schedule.
- Running it on a schedule (cron/systemd timer) and routing FAIL exit codes
  somewhere a human will see them (e.g. a daily digest) is a reasonable
  next step, but is not part of this task's deliverables — no cron
  entry, systemd unit, or compose service is added by this change.
- For the checks `ci/verify-infra.sh` already owns (compose/network/mount/
  Postgres/isolation/secret/reachability), that script's existing
  pass/fail/skip output and non-zero exit code remain the mechanism; this
  task does not change that.

## 6. Not tested

This task's own verification (by the preparing agent) was documentation-
and syntax-level only, run from a repository checkout without Docker
access. It was subsequently run for real, from a session with live host
access, 25 Jul 2026 — see below.

- `monitoring-check.sh` **has been executed** against the live
  `makam-nonprod` stack (`DISK_MOUNT=/ bash docs/operations/examples/
  monitoring-check.sh`): memory 51% (PASS), swap 66MiB/9678MiB (WARN, as
  designed — a single reading, not a sustained-use claim), disk 54%
  (PASS), all four `makam-nonprod-*` containers running/healthy with 0
  restarts (PASS), `/health/live`/`/health/ready` correctly SKIP. Exit
  code 0 (`NO FAIL-TIER FINDINGS`). The memory/swap/disk/docker-status
  logic is confirmed working against real host output, not just reasoned
  through — this item is no longer NOT TESTED.
- The `json` log channel was added to `config/logging.php` and syntax-
  checked with `php -l`, but was **not executed** — no `composer install`
  was run in this checkout (per this repo's `CLAUDE.md`: Composer/npm
  builds run in CI only, not on this host), so `php artisan` / actual log
  output was not observed here. Whether `RotatingFileHandler` +
  `JsonFormatter` produce the expected file/JSON shape should be confirmed
  in CI or on a real environment before relying on it.
- The Laravel `dontFlash` behavior described in §2 was verified against
  the live 13.x documentation pages (fetched 25 Jul 2026) and cross-checked
  against validation.md's flash-input description, but the 13.x errors.md
  page itself no longer documents `dontFlash` directly — that specific
  claim rests on documented, stable, cross-version framework behavior
  rather than a single current-version doc citation. Flagged as a residual
  uncertainty rather than stated as fully confirmed.
