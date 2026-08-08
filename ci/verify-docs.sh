#!/usr/bin/env bash
# Mechanical documentation gates for makam-app.
#
# Purpose: this is the guardrail that makes parallel agent fan-out safe.
# N concurrent agents editing docs will drift in convention unless something
# checks them mechanically. See docs/planning/parallelization-analysis.md
# (recommendation 4: build the guardrail before any fan-out).
#
# Runs with no application code present — every check is repo-only.
# Usage:  bash ci/verify-docs.sh
# Exit 0 = all gates pass. Non-zero = at least one gate failed.

set -uo pipefail
cd "$(dirname "$0")/.."

FAIL=0
pass() { printf '  \033[32mPASS\033[0m  %s\n' "$1"; }
fail() { printf '  \033[31mFAIL\033[0m  %s\n' "$1"; FAIL=1; }
head2() { printf '\n== %s ==\n' "$1"; }

# ---------------------------------------------------------------------------
head2 "GATE 1 — WCAG AA contrast (design system)"
# ---------------------------------------------------------------------------
if python3 docs/design/verify-contrast.py --quiet >/tmp/contrast.log 2>&1; then
  pass "46 asserted colour pairs meet WCAG 2.1 AA"
else
  fail "contrast regression — see output below"; cat /tmp/contrast.log
fi

# ---------------------------------------------------------------------------
head2 "GATE 2 — no hardcoded design values outside tokens.css"
# ---------------------------------------------------------------------------
hits=$(grep -rInE '#[0-9A-Fa-f]{6}\b' \
        --include='*.blade.php' --include='*.css' --include='*.js' --include='*.php' \
        resources/ app/ 2>/dev/null | grep -v 'resources/css/tokens.css' \
        | grep -v 'app/Support/Design/generated/' || true)
# app/Support/Design/generated/ (FilamentPalette.php) is machine-generated
# FROM tokens.css by `php artisan design:generate-filament-palette`
# (design-system.md §8.3 OQ-09) — its hex values are a derived artifact, not
# an independent source of truth, the same reasoning tokens.css itself is
# exempted for. `design:verify-filament-palette` (§9.5 gate 6) is what
# actually keeps it honest against drift, not this gate.
if [ -z "$hits" ]; then pass "no hex literals outside tokens.css"
else fail "hex literal outside tokens.css:"; echo "$hits" | head -10; fi

# ---------------------------------------------------------------------------
head2 "GATE 3 — no Tailwind arbitrary values for design decisions"
# ---------------------------------------------------------------------------
hits=$(grep -rInE '\b(text|bg|border|p|m|w|h|gap|z|rounded|shadow|duration)-\[[^]]*\]' \
        --include='*.blade.php' resources/ app/ 2>/dev/null | grep -v 'var(--' || true)
if [ -z "$hits" ]; then pass "no arbitrary values (var() references allowed)"
else fail "arbitrary design value:"; echo "$hits" | head -10; fi

# ---------------------------------------------------------------------------
head2 "GATE 4 — every referenced markdown link resolves"
# ---------------------------------------------------------------------------
broken=0
while IFS= read -r f; do
  d=$(dirname "$f")
  grep -ohE '\]\((\.\.?/)[^)#]+\)' "$f" 2>/dev/null | sed 's/](\(.*\))/\1/' | sort -u | \
  while IFS= read -r l; do
    [ -e "$d/$l" ] || echo "$f -> $l"
  done
done < <(find docs .kiro -name '*.md' 2>/dev/null) > /tmp/broken.log
broken=$(wc -l < /tmp/broken.log)
if [ "$broken" -eq 0 ]; then pass "all relative markdown links resolve"
else fail "$broken broken link(s):"; head -10 /tmp/broken.log | sed 's/^/    /'; fi

# ---------------------------------------------------------------------------
head2 "GATE 5 — spec structural integrity"
# ---------------------------------------------------------------------------
# A Feature Spec's first phase is requirements.md. A Bugfix Spec's first
# phase is bugfix.md instead (kiro.dev/docs/specs/bugfix-specs; this repo's
# own .claude/skills/kiro-bugfix-spec) — a different filename for the same
# EARS-notation phase, not a missing file. Added 8 Aug 2026 when
# .kiro/specs/help-centre-missing-route/ became this repo's first bugfix
# spec and correctly failed the requirements.md-only check below; the fix
# is here, in the gate, not a fake requirements.md added to satisfy it.
missing=0
featureSpecs=0
bugfixSpecs=0
for d in .kiro/specs/*/; do
  if [ -f "$d"requirements.md ]; then
    featureSpecs=$((featureSpecs+1))
    firstPhase=requirements.md
  elif [ -f "$d"bugfix.md ]; then
    bugfixSpecs=$((bugfixSpecs+1))
    firstPhase=bugfix.md
  else
    echo "missing: $d requirements.md or bugfix.md"
    missing=$((missing+1))
    continue
  fi
  for f in "$firstPhase" design.md tasks.md; do
    [ -f "$d$f" ] || { echo "missing: $d$f"; missing=$((missing+1)); }
  done
done
n=$(ls -1d .kiro/specs/*/ 2>/dev/null | wc -l)
if [ "$missing" -eq 0 ]; then pass "$n specs ($featureSpecs feature, $bugfixSpecs bugfix), all with complete triads"
else fail "$missing missing spec file(s)"; fi

# ---------------------------------------------------------------------------
head2 "GATE 6 — every spec declares design-system compliance"
# ---------------------------------------------------------------------------
nods=$(for d in .kiro/specs/*/; do
  grep -q 'design-system.md' "$d/tasks.md" 2>/dev/null || echo "$(basename "$d")"
done)
if [ -z "$nods" ]; then pass "all specs reference docs/design/design-system.md"
else fail "spec(s) without a design-system reference:"; echo "$nods" | sed 's/^/    /'; fi

# ---------------------------------------------------------------------------
head2 "GATE 7 — every 'Covered' traceability row names a test file that exists"
# ---------------------------------------------------------------------------
# AGENTS.md: "Every traceability item marked `Covered` needs test evidence."
#
# LESSON 1 (kept from the original gate, still enforced) — match only STATUS
# CELLS, not every occurrence of the word. The file legitimately defines
# `Covered` as a reserved vocabulary word in its own status legend, and an
# earlier version of this gate counted that definition and failed a correct
# file. Found 25 Jul 2026 by the subagent fixing H-3, which reported the false
# positive instead of deleting the legend to make the gate green. Scoping the
# match to the trailing status column was the fix; weakening the assertion
# would not have been. This version scopes harder: a line counts as a row only
# if it starts with `|` AND its first cell is a traceability ID (HOME-01,
# FAQ-03, ...), so legends, prose, and the section D evidence trail — all of
# which say "Covered" repeatedly — cannot reach the gate at all. The
# ID-shaped-row rule is then cross-checked against a plain status-cell count
# (see g7_loose below) so a `Covered` row with a malformed ID cannot slip
# through the narrower filter unnoticed.
#
# LESSON 2 (kept, and still live) — framework stubs are not evidence. The
# Laravel skeleton ships tests/Unit/ExampleTest.php and tests/Feature/
# ExampleTest.php; two placeholder assertions must never satisfy "test evidence
# exists" for 31 traceability rows. Both stub files have since been deleted
# from this repo (verified 08 Aug 2026), but the exclusion below is retained
# deliberately: `php artisan` scaffolding can recreate them at any time, and a
# row naming one as its evidence must still be rejected.
#
# WHY THE OLD TEST-FILE COUNT IS GONE (finding T-B, 08 Aug 2026) — the previous
# condition was `[ "$covered" -eq 0 ] || [ "$tests" -gt 0 ]`. That was a real
# check only while the repo had zero tests. Once real tests landed (90 files by
# August) the right-hand side became permanently true and the gate passed
# unconditionally — it would have accepted all 31 rows marked `Covered` with
# nothing whatsoever behind them. The existence of tests SOMEWHERE was never
# evidence for a SPECIFIC row. The gate now verifies the mapping instead: every
# `Covered` row must name at least one test path in its own "Test evidence"
# cell, and every path it names must exist on disk. Whether that test actually
# asserts what the row claims is not mechanically checkable and stays a human
# review duty — the matrix's section D records that reading per row so a
# reviewer can re-check it.
#
# Column positions are read from the END of the row (status = last cell,
# evidence = the one before it) rather than by fixed index, so prepending a
# column to the section B table does not silently break the gate.
tm=docs/domain/traceability-matrix.md
G7_STUBS=" tests/Unit/ExampleTest.php tests/Feature/ExampleTest.php "
g7_log=/tmp/g7-traceability.log
: > "$g7_log"
g7_rows=0
g7_paths=0

if [ ! -f "$tm" ]; then
  fail "$tm is missing — traceability cannot be verified"
else
  while IFS=$'\t' read -r id evidence status; do
    g7_rows=$((g7_rows + 1))
    paths=$(printf '%s\n' "$evidence" \
            | grep -oE '(tests|resources/tests)/[A-Za-z0-9_./-]+\.(php|ts|js)' | sort -u || true)
    if [ -z "$paths" ]; then
      echo "$id: status '$status' but its Test evidence cell names no test file" >> "$g7_log"
      continue
    fi
    while IFS= read -r p; do
      g7_paths=$((g7_paths + 1))
      if [ "${G7_STUBS#* $p }" != "$G7_STUBS" ]; then
        echo "$id: names '$p' — framework stub, not test evidence (lesson 2)" >> "$g7_log"
      elif [ ! -f "$p" ]; then
        echo "$id: names '$p' — no such file on disk" >> "$g7_log"
      fi
    done <<< "$paths"
  done < <(awk -F'|' '
    /^\|/ {
      if (NF < 5) next
      id = $2;        gsub(/^[[:space:]]+|[[:space:]]+$/, "", id)
      if (id !~ /^[A-Z]+-[0-9]+$/) next
      st = $(NF - 1); gsub(/^[[:space:]]+|[[:space:]]+$/, "", st)
      if (st !~ /^Covered/) next
      ev = $(NF - 2); gsub(/^[[:space:]]+|[[:space:]]+$/, "", ev)
      # Never emit an empty field: IFS=tab in the reader collapses runs of
      # whitespace delimiters, which would shift the columns for an
      # evidence-less row.
      if (ev == "") ev = "-"
      printf "%s\t%s\t%s\n", id, ev, st
    }' "$tm")

  # Cross-check (lesson 1): every status cell reading `Covered` on any table row
  # must have been one of the ID-shaped rows examined above.
  g7_loose=$(awk -F'|' '
    /^\|/ { if (NF < 3) next
            st = $(NF - 1); gsub(/^[[:space:]]+|[[:space:]]+$/, "", st); if (st ~ /^Covered/) n++ }
    END { print n + 0 }' "$tm")
  if [ "$g7_loose" -ne "$g7_rows" ]; then
    echo "$((g7_loose - g7_rows)) row(s) marked 'Covered' were not examined — first cell is not a traceability ID" >> "$g7_log"
  fi

  if [ ! -s "$g7_log" ]; then
    if [ "$g7_rows" -eq 0 ]; then
      pass "traceability has no 'Covered' rows — nothing to evidence"
    else
      pass "$g7_rows 'Covered' row(s), $g7_paths named test path(s), all exist on disk"
    fi
  else
    fail "unevidenced 'Covered' row(s) — AGENTS.md violation (findings H-3, T-B):"
    sed 's/^/    /' "$g7_log"
  fi
fi

# ---------------------------------------------------------------------------
head2 "GATE 8 — canonical catalogue not duplicated in specs"
# ---------------------------------------------------------------------------
# AGENTS.md: "Do not duplicate canonical catalog data in multiple
# hand-maintained documents or code locations."
if grep -rq 'marketplace-catalog.md' .kiro/specs/funeral-marketplace-and-vendor-portal/ 2>/dev/null; then
  pass "marketplace spec references the canonical catalogue"
else
  fail "marketplace spec does not reference marketplace-catalog.md (finding D1)"
fi

# ---------------------------------------------------------------------------
head2 "GATE 9 — Compose example volume path valid for postgres:18"
# ---------------------------------------------------------------------------
# postgres:18 sets PGDATA=/var/lib/postgresql/18/docker. Mounting the volume at
# /var/lib/postgresql/data leaves PGDATA outside the volume — silent data loss
# on container recreate. Finding H-1.
ex=docs/operations/examples/docker-compose.dev-stg.yml
if [ -f "$ex" ] && grep -qE 'postgres_data:/var/lib/postgresql/data\b' "$ex"; then
  fail "H-1: $ex mounts the volume at /var/lib/postgresql/data — PGDATA falls outside it"
else
  pass "compose example volume path does not strand PGDATA"
fi

# ---------------------------------------------------------------------------
head2 "GATE 10 — no permission bypass in .claude/settings.json"
# ---------------------------------------------------------------------------
# Finding M-2: allowing Bash(cat *) neutralises deny Read(*secret*)/Read(*/.env).
s=.claude/settings.json
if [ -f "$s" ] && grep -q 'Bash(cat \*)' "$s" && grep -qE 'Read\(\*secret\*\)|Read\(\*/\.env\)' "$s"; then
  fail "M-2: Bash(cat *) is allowed while Read(*secret*) is denied — the denial is cosmetic"
else
  pass "no cat-based bypass of secret read denials"
fi

# ---------------------------------------------------------------------------
head2 "GATE 11 — no raw z-index (design-system.md §9.5 gate 4)"
# ---------------------------------------------------------------------------
hits=$(grep -rInE 'z-index\s*:\s*[0-9]' --include='*.css' --include='*.blade.php' resources/ 2>/dev/null \
        | grep -v 'resources/css/tokens.css' || true)
if [ -z "$hits" ]; then pass "no raw z-index (all layering goes through --mk-z-* tokens)"
else fail "raw z-index outside tokens.css:"; echo "$hits" | head -10; fi

# ---------------------------------------------------------------------------
head2 "GATE 12 — no focus suppression without replacement (design-system.md §9.5 gate 5)"
# ---------------------------------------------------------------------------
# design-system.md §9.5's own example (`grep -rIn 'outline:\s*none' --include='*.css'
# resources/ | grep -v 'focus-visible'`) false-positives against this repo's current
# state: both resources/css/app.css:89 and resources/css/tokens.css:276 contain the
# literal string "outline: none" / "outline:none" inside a comment that WARNS against
# ever doing it, not an actual declaration. Both are single-line `/* ... */` comments,
# so filtering out lines containing the comment opener removes the false positive
# without weakening the check against a real `outline: none;` declaration, which would
# never have a `/*` earlier on the same line. Verified empirically against the current
# repo (07/2026): zero real hits either way.
hits=$(grep -rIn 'outline:\s*none' --include='*.css' resources/ 2>/dev/null \
        | grep -v 'focus-visible' | grep -v '/\*' || true)
if [ -z "$hits" ]; then pass "no unreplaced focus suppression"
else fail "outline: none without a focus-visible replacement:"; echo "$hits" | head -10; fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mRESULT: ALL DOC GATES PASS\033[0m\n'; exit 0
else
  printf '\033[31mRESULT: AT LEAST ONE DOC GATE FAILED\033[0m\n'; exit 1
fi
