#!/usr/bin/env bash
# Infrastructure gates for the makam-nonprod stack.
#
# Companion to ci/verify-docs.sh. That one checks the repository and runs
# anywhere; this one inspects the LIVE stack and therefore only runs on a host
# with docker access. It is an operations gate, not a CI gate.
#
# READ-ONLY. This script must never modify configuration, data, or containers.
# If you are tempted to add a fix here, add it to compose.yml instead.
#
# Every gate below exists because the defect it checks for actually happened,
# was silent, and cost real time:
#
#   C-2  Postgres reported healthy for a day while the application databases
#        did not exist. pg_isready proved liveness, not function.
#   N-4  A container attached only to an internal:true network silently ignores
#        its ports: mapping. Both placeholders were unreachable while reporting
#        Up, for reasons unrelated to nginx.
#   N-5  A bind-mounted asset was mode 0660 owned by the host user, unreadable
#        by the container's runtime uid. Same class as C-2's secret failure.
#   N-6  sudo mkdir yields 0750 under root umask 077, making an ACME webroot
#        untraversable by www-data and breaking renewal silently 89 days later.
#
# Usage:  bash ci/verify-infra.sh
# Exit 0 = all gates pass. Non-zero = at least one gate failed.

set -uo pipefail

COMPOSE=${COMPOSE:-/opt/makam/compose/compose.yml}
FAIL=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=1; }
skip() { printf '  \033[33mSKIP\033[0m  %s\n' "$1"; }
head2() { printf '\n== %s ==\n' "$1"; }

if ! command -v docker >/dev/null 2>&1; then
  echo "docker not available — this gate only runs on the deployment host"; exit 2
fi
if [ ! -f "$COMPOSE" ]; then
  echo "compose file not found at $COMPOSE"; exit 2
fi

# ---------------------------------------------------------------------------
head2 "GATE I1 — compose file is valid"
# ---------------------------------------------------------------------------
if docker compose -f "$COMPOSE" config -q 2>/dev/null; then
  pass "docker compose config parses"
else
  fail "compose config invalid"; docker compose -f "$COMPOSE" config -q
fi

# ---------------------------------------------------------------------------
head2 "GATE I2 — every service with ports: is on a routable network  [N-4]"
# ---------------------------------------------------------------------------
# Docker silently drops the ports: mapping for a container attached only to an
# internal:true network. The declaration stays in the file and looks correct.
out=$(docker compose -f "$COMPOSE" config 2>/dev/null | python3 -c '
import sys, yaml
cfg = yaml.safe_load(sys.stdin)
nets = cfg.get("networks") or {}
internal = {n for n, v in nets.items() if (v or {}).get("internal")}
bad = []
for svc, s in (cfg.get("services") or {}).items():
    if s.get("ports"):
        snets = set((s.get("networks") or {}).keys())
        if not (snets - internal):
            bad.append(f"{svc} (networks={sorted(snets)}, all internal)")
print("\n".join(bad))
')
if [ -z "$out" ]; then
  pass "no service publishes a port from an internal-only network"
else
  fail "ports: will be silently ignored for:"; echo "$out" | sed 's/^/        /'
fi

# ---------------------------------------------------------------------------
head2 "GATE I3 — declared ports are actually listening  [N-4]"
# ---------------------------------------------------------------------------
# I2 checks the config; this checks reality. They can disagree if a container
# was created before the network was fixed.
declared=$(docker compose -f "$COMPOSE" config 2>/dev/null | python3 -c '
import sys, yaml
cfg = yaml.safe_load(sys.stdin)
for svc, s in (cfg.get("services") or {}).items():
    for p in (s.get("ports") or []):
        pub = p.get("published") if isinstance(p, dict) else None
        if pub: print(f"{svc} {pub}")
')
if [ -z "$declared" ]; then
  skip "no published ports declared"
else
  miss=0
  while read -r svc port; do
    [ -z "${port:-}" ] && continue
    if ss -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]"; then
      pass "$svc publishes $port and it is listening"
    else
      fail "$svc declares $port but nothing is listening on it"; miss=1
    fi
  done <<< "$declared"
  [ "$miss" -eq 0 ] || true
fi

# ---------------------------------------------------------------------------
head2 "GATE I4 — bind mounts readable by the container runtime uid  [N-5]"
# ---------------------------------------------------------------------------
# A bind-mounted asset must be readable by the uid the container actually runs
# as, not merely by its owner on the host.
check_mount() {
  local c="$1" u="$2" p="$3"
  docker ps --format '{{.Names}}' | grep -qx "$c" || { skip "$c not running"; return; }
  local res
  res=$(docker exec -u "$u" "$c" sh -c "
    if [ -d '$p' ]; then
      ls '$p' >/dev/null 2>&1 || { echo DIR-DENIED; exit; }
      for f in '$p'/*; do
        [ -f \"\$f\" ] || continue
        cat \"\$f\" >/dev/null 2>&1 || { echo FILE-DENIED; exit; }
      done
      echo OK
    elif [ -f '$p' ]; then
      cat '$p' >/dev/null 2>&1 && echo OK || echo FILE-DENIED
    else echo NOT-FOUND; fi" 2>/dev/null)
  case "$res" in
    OK)        pass "$c as $u can read $p" ;;
    NOT-FOUND) skip "$c: $p not present" ;;
    *)         fail "$c as $u CANNOT read $p ($res)" ;;
  esac
}
check_mount makam-nonprod-postgres-1        postgres /docker-entrypoint-initdb.d
check_mount makam-nonprod-postgres-1        postgres /run/secrets
check_mount makam-nonprod-dev-placeholder-1 nginx    /usr/share/nginx/html
check_mount makam-nonprod-stg-placeholder-1 nginx    /usr/share/nginx/html

# ---------------------------------------------------------------------------
head2 "GATE I5 — application databases and extensions exist  [C-2]"
# ---------------------------------------------------------------------------
PG=makam-nonprod-postgres-1
if docker ps --format '{{.Names}}' | grep -qx "$PG"; then
  for db in makam_dev makam_stg; do
    if docker exec "$PG" psql -U postgres_admin -d postgres -tAc \
         "select 1 from pg_database where datname='$db'" 2>/dev/null | grep -q 1; then
      exts=$(docker exec "$PG" psql -U postgres_admin -d "$db" -tAc \
             "select string_agg(extname,',' order by extname) from pg_extension
              where extname in ('pg_trgm','unaccent')" 2>/dev/null)
      if [ "$exts" = "pg_trgm,unaccent" ]; then
        pass "$db exists with pg_trgm and unaccent"
      else
        fail "$db exists but extensions are '$exts' (want pg_trgm,unaccent)"
      fi
    else
      fail "$db does not exist — init failed silently (finding C-2)"
    fi
  done
else
  skip "$PG not running"
fi

# ---------------------------------------------------------------------------
head2 "GATE I6 — environment isolation holds"
# ---------------------------------------------------------------------------
# dev must not reach stg, and neither may reach the maintenance databases.
if docker ps --format '{{.Names}}' | grep -qx "$PG"; then
  res=$(docker exec -i -u postgres "$PG" bash -s <<'INNER' 2>/dev/null
DEV=$(cat /run/secrets/makam_dev_db_password 2>/dev/null) || exit 3
t() { PGPASSWORD="$2" psql -h 127.0.0.1 -U "$1" -d "$3" -tAc 'select 1' >/dev/null 2>&1 && echo OPEN || echo DENIED; }
echo "own=$(t makam_dev_user "$DEV" makam_dev) cross=$(t makam_dev_user "$DEV" makam_stg) maint=$(t makam_dev_user "$DEV" postgres)"
INNER
)
  case "$res" in
    "own=OPEN cross=DENIED maint=DENIED") pass "dev can reach only its own database" ;;
    "") fail "isolation probe could not run (secret unreadable by uid 999?)" ;;
    *)  fail "isolation broken: $res" ;;
  esac
else
  skip "$PG not running"
fi

# ---------------------------------------------------------------------------
head2 "GATE I7 — datastores are NOT reachable from the host"
# ---------------------------------------------------------------------------
for svc in postgres redis; do
  c="makam-nonprod-$svc-1"
  docker ps --format '{{.Names}}' | grep -qx "$c" || { skip "$c not running"; continue; }
  if [ -z "$(docker port "$c" 2>/dev/null)" ]; then
    pass "$svc publishes no port to the host"
  else
    fail "$svc publishes a port to the host: $(docker port "$c" | tr '\n' ' ')"
  fi
done

# ---------------------------------------------------------------------------
head2 "GATE I8 — secret files owned by the Postgres runtime uid"
# ---------------------------------------------------------------------------
# The declarative Compose fix (secrets uid/gid/mode) is NOT supported on this
# host, so host ownership is the only control. Re-check after any restore.
sec=$(dirname "$COMPOSE")/secrets
if [ -d "$sec" ]; then
  bad=$(sudo -n find "$sec" -name '*.txt' \! -uid 999 -printf '%p (uid %U)\n' 2>/dev/null)
  if [ -z "$bad" ]; then
    pass "all secret files owned by uid 999"
  else
    fail "secret files not owned by uid 999 — init will fail silently:"; echo "$bad" | sed 's/^/        /'
  fi
else
  skip "no secrets directory at $sec"
fi

# ---------------------------------------------------------------------------
head2 "GATE I9 — dev.makam.co.id is not public and not indexable"
# ---------------------------------------------------------------------------
# dev-staging-environment.md line 75 requires access restriction.
# release-gates.md section I requires noindex.
code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 https://dev.makam.co.id/ 2>/dev/null)
case "$code" in
  401|403) pass "dev.makam.co.id refuses unauthenticated access ($code)" ;;
  000)     skip "dev.makam.co.id not reachable from here" ;;
  *)       fail "dev.makam.co.id returned $code without credentials — it is PUBLIC" ;;
esac
hdr=$(curl -sI --max-time 8 https://dev.makam.co.id/ 2>/dev/null | grep -ic 'x-robots-tag')
if [ "${hdr:-0}" -ge 1 ]; then pass "X-Robots-Tag present on dev"
elif [ "$code" = "000" ]; then skip "cannot check noindex header"
else fail "X-Robots-Tag missing on dev — it may be indexed"; fi

# ---------------------------------------------------------------------------
head2 "GATE I10 — ACME challenge path bypasses auth  [renewal precondition, N-6]"
# ---------------------------------------------------------------------------
# If basic auth covers /.well-known/acme-challenge/, renewal 401s and the
# certificate expires silently. A 404 is fine — it proves auth was bypassed.
# A 401 is a failure.
acme=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 \
       http://dev.makam.co.id/.well-known/acme-challenge/gate-probe 2>/dev/null)
case "$acme" in
  404|200) pass "ACME path reachable without auth ($acme) — renewal can succeed" ;;
  000)     skip "dev.makam.co.id not reachable from here" ;;
  401|403) fail "ACME path returns $acme — renewal WILL fail when the cert expires" ;;
  *)       fail "ACME path returned unexpected $acme" ;;
esac

# ---------------------------------------------------------------------------
head2 "GATE I11 — production sites unaffected"
# ---------------------------------------------------------------------------
# Any nginx change on this host can break the live apex. This is the regression
# check that must pass after every vhost edit.
for u in https://makam.co.id/ https://www.makam.co.id/; do
  c=$(curl -s -o /dev/null -w '%{http_code}' --max-time 8 "$u" 2>/dev/null)
  case "$c" in
    200) pass "$u returns 200" ;;
    000) skip "$u not reachable from here" ;;
    *)   fail "$u returns $c — expected 200" ;;
  esac
done

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mRESULT: ALL INFRA GATES PASS\033[0m\n'; exit 0
else
  printf '\033[31mRESULT: AT LEAST ONE INFRA GATE FAILED\033[0m\n'; exit 1
fi
