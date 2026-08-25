#!/usr/bin/env bash
# Lightweight host-level monitoring checks for the makam-nonprod dev/staging
# host (S2-T10, docs/planning/agent-execution-plan.md §4, Batch 2.7).
#
# READ-ONLY. This script must never modify configuration, data, or
# containers. It only reads system and docker state (free, swapon, df,
# docker ps, docker inspect). Safe to run repeatedly, including outside a
# cron/systemd schedule, with zero side effects.
#
# This is NOT a replacement for ci/verify-infra.sh, which is the existing,
# separately-owned live-stack gate (compose validity, network/port
# topology, bind-mount permissions, Postgres init, environment isolation,
# secret ownership, dev/prod HTTP reachability). Run that script for those
# checks; this one only covers host memory/swap/disk and container
# health/restart visibility, per dev-staging-environment.md §13.
#
# Thresholds and their sourcing are documented in
# docs/operations/observability.md §3 — read that before changing a number
# here. In short:
#   - memory:  WARN >=70%, "elevated" flag >=80% (80% is the number stated
#              in dev-staging-environment.md §6/§15; 70% is this script's
#              own early-warning margin, not a spec number).
#   - swap:    any non-zero usage is WARN, never FAIL from one reading —
#              §6/§15 both gate on *sustained* swap use, which a single
#              run cannot establish (see observability.md §3.1).
#   - disk:    80% WARN / 90% FAIL by default. NOT sourced from
#              dev-staging-environment.md, which specifies no disk figure
#              at all — these are conventional defaults, overridable below.
#
# Usage:  bash docs/operations/examples/monitoring-check.sh
#         DISK_MOUNT=/opt/makam bash docs/operations/examples/monitoring-check.sh
#
# Exit 0 = no FAIL-tier finding. Non-zero = at least one FAIL-tier finding.
# WARN findings do not affect the exit code — this host is non-production
# and the realistic response to a warning is "visible here", not "block
# something" (observability.md §5).

set -uo pipefail

MEM_WARN_PCT=${MEM_WARN_PCT:-70}
MEM_ELEVATED_PCT=${MEM_ELEVATED_PCT:-80}
DISK_MOUNT=${DISK_MOUNT:-/}
DISK_WARN_PCT=${DISK_WARN_PCT:-80}
DISK_FAIL_PCT=${DISK_FAIL_PCT:-90}
CONTAINER_PREFIX=${CONTAINER_PREFIX:-makam-nonprod-}
RESTART_WARN_COUNT=${RESTART_WARN_COUNT:-3}

FAIL=0
WARNS=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
warn() { printf '  \033[33mWARN\033[0m  %s\n' "$1"; WARNS=$((WARNS+1)); }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=1; }
skip() { printf '  \033[33mSKIP\033[0m  %s\n' "$1"; }
head2() { printf '\n== %s ==\n' "$1"; }

# ---------------------------------------------------------------------------
head2 "HOST M1 — memory usage (dev-staging-environment.md §6/§15)"
# ---------------------------------------------------------------------------
if command -v free >/dev/null 2>&1; then
  read -r total used <<<"$(free -m | awk '/^Mem:/ {print $2, $3}')"
  if [ -n "${total:-}" ] && [ "$total" -gt 0 ] 2>/dev/null; then
    pct=$(( used * 100 / total ))
    if [ "$pct" -ge "$MEM_ELEVATED_PCT" ]; then
      fail "memory ${pct}% used (${used}MiB/${total}MiB) — at/above the ${MEM_ELEVATED_PCT}% figure §6/§15 name for capacity review. One reading is not proof of 'persistent'/'steady' — confirm with repeated runs before treating as a real breach."
    elif [ "$pct" -ge "$MEM_WARN_PCT" ]; then
      warn "memory ${pct}% used (${used}MiB/${total}MiB) — approaching the ${MEM_ELEVATED_PCT}% threshold (${MEM_WARN_PCT}% is this script's own margin, not a spec number)"
    else
      pass "memory ${pct}% used (${used}MiB/${total}MiB), below ${MEM_WARN_PCT}%"
    fi
  else
    skip "could not parse 'free -m' output"
  fi
else
  skip "'free' not available on this host"
fi

# ---------------------------------------------------------------------------
head2 "HOST M2 — swap usage (dev-staging-environment.md §6/§15)"
# ---------------------------------------------------------------------------
if command -v free >/dev/null 2>&1; then
  read -r swtotal swused <<<"$(free -m | awk '/^Swap:/ {print $2, $3}')"
  if [ -n "${swtotal:-}" ]; then
    if [ "${swused:-0}" -gt 0 ] 2>/dev/null; then
      warn "swap in use: ${swused}MiB/${swtotal}MiB — §6/§15 flag 'sustained' swap use for capacity review; this single reading cannot establish 'sustained', re-check across repeated runs (see observability.md §3.1)"
    else
      pass "no swap in use (0MiB/${swtotal}MiB configured)"
    fi
  else
    skip "could not parse 'free -m' swap line"
  fi
else
  skip "'free' not available on this host"
fi

# ---------------------------------------------------------------------------
head2 "HOST M3 — disk usage on $DISK_MOUNT (thresholds not sourced from §6 — see observability.md §3)"
# ---------------------------------------------------------------------------
if command -v df >/dev/null 2>&1; then
  line=$(df -P "$DISK_MOUNT" 2>/dev/null | awk 'NR==2 {print $5, $6}')
  if [ -n "$line" ]; then
    pct=${line%% *}; pct=${pct%\%}
    mnt=${line#* }
    if [ "$pct" -ge "$DISK_FAIL_PCT" ] 2>/dev/null; then
      fail "disk ${pct}% used on $mnt — at/above conventional ${DISK_FAIL_PCT}% fail threshold"
    elif [ "$pct" -ge "$DISK_WARN_PCT" ] 2>/dev/null; then
      warn "disk ${pct}% used on $mnt — at/above conventional ${DISK_WARN_PCT}% warn threshold"
    else
      pass "disk ${pct}% used on $mnt, below ${DISK_WARN_PCT}%"
    fi
  else
    skip "could not read disk usage for $DISK_MOUNT"
  fi
else
  skip "'df' not available on this host"
fi

# ---------------------------------------------------------------------------
head2 "HOST M4 — makam-nonprod container status (dev-staging-environment.md §13)"
# ---------------------------------------------------------------------------
if ! command -v docker >/dev/null 2>&1; then
  skip "docker not available — this check only runs on the deployment host"
else
  names=$(docker ps -a --filter "name=${CONTAINER_PREFIX}" --format '{{.Names}}' 2>/dev/null || true)
  if [ -z "$names" ]; then
    skip "no containers matching '${CONTAINER_PREFIX}*' found"
  else
    while IFS= read -r name; do
      [ -z "$name" ] && continue
      status=$(docker inspect --format '{{.State.Status}}' "$name" 2>/dev/null || echo unknown)
      health=$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}' "$name" 2>/dev/null || echo unknown)
      case "$status" in
        running)
          case "$health" in
            healthy)   pass "$name running, healthcheck healthy" ;;
            unhealthy) fail "$name running but healthcheck UNHEALTHY" ;;
            starting)  warn "$name running, healthcheck still starting" ;;
            none)      pass "$name running (no healthcheck defined on this service)" ;;
            *)         warn "$name running, healthcheck status '$health' (unrecognized)" ;;
          esac
          ;;
        restarting) fail "$name is restarting" ;;
        exited)     fail "$name has exited" ;;
        *)          warn "$name status '$status' (unrecognized)" ;;
      esac
    done <<< "$names"
  fi
fi

# ---------------------------------------------------------------------------
head2 "HOST M5 — container restart counts (dev-staging-environment.md §13, heuristic)"
# ---------------------------------------------------------------------------
# RESTART_WARN_COUNT is this script's own heuristic, not a number from any
# spec doc — dev-staging-environment.md §13 asks only for "restart ...
# visibility", not a specific count threshold.
if ! command -v docker >/dev/null 2>&1; then
  skip "docker not available — this check only runs on the deployment host"
else
  names=$(docker ps -a --filter "name=${CONTAINER_PREFIX}" --format '{{.Names}}' 2>/dev/null || true)
  if [ -z "$names" ]; then
    skip "no containers matching '${CONTAINER_PREFIX}*' found"
  else
    while IFS= read -r name; do
      [ -z "$name" ] && continue
      restarts=$(docker inspect --format '{{.RestartCount}}' "$name" 2>/dev/null || echo "")
      if [ -z "$restarts" ]; then
        skip "$name: could not read restart count"
      elif [ "$restarts" -ge "$RESTART_WARN_COUNT" ] 2>/dev/null; then
        warn "$name has restarted $restarts time(s) (>= heuristic threshold $RESTART_WARN_COUNT)"
      else
        pass "$name restart count: $restarts"
      fi
    done <<< "$names"
  fi
fi

# ---------------------------------------------------------------------------
head2 "HOST M6 — /health/live, /health/ready (ci-cd-and-release.md §8)"
# ---------------------------------------------------------------------------
# Deliberately not fabricated. Neither route exists in this repository as
# of S2-T10 — see docs/operations/observability.md §4 for the full
# dependency note. routes/web.php has no routes at all yet, and
# bootstrap/app.php only registers Laravel's own /up (liveness-only, no
# dependency check), which ci/verify-infra.sh GATE I9 already covers.
skip "/health/live not implemented — blocked on application routes (observability.md §4)"
skip "/health/ready not implemented — blocked on application routes (observability.md §4)"

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  if [ "$WARNS" -gt 0 ]; then
    printf '\033[33mRESULT: NO FAIL-TIER FINDINGS (%d warning(s) — see above)\033[0m\n' "$WARNS"
  else
    printf '\033[32mRESULT: ALL CHECKS PASS\033[0m\n'
  fi
  exit 0
else
  printf '\033[31mRESULT: AT LEAST ONE CHECK FAILED\033[0m\n'
  exit 1
fi
