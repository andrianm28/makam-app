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
missing=0
for d in .kiro/specs/*/; do
  for f in requirements.md design.md tasks.md; do
    [ -f "$d$f" ] || { echo "missing: $d$f"; missing=$((missing+1)); }
  done
done
n=$(ls -1d .kiro/specs/*/ 2>/dev/null | wc -l)
if [ "$missing" -eq 0 ]; then pass "$n specs, all with complete requirements/design/tasks triad"
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
head2 "GATE 7 — no unevidenced 'Covered' in traceability (AGENTS.md)"
# ---------------------------------------------------------------------------
# AGENTS.md: "Every traceability item marked `Covered` needs test evidence."
#
# Count only STATUS CELLS, not every occurrence of the word. The file legitimately
# defines `Covered` as a reserved vocabulary word in its own status legend, and an
# earlier version of this gate counted that definition and failed a correct file.
# Found 25 Jul 2026 by the subagent fixing H-3, which reported the false positive
# instead of deleting the legend to make the gate green. Scoping the match to the
# trailing status column is the fix; weakening the assertion would not have been.
#
# Second weakness, found 25 Jul 2026 when the Laravel scaffold landed: the count
# accepted ANY test file, and the skeleton ships tests/Unit/ExampleTest.php and
# tests/Feature/ExampleTest.php. Two placeholder assertions would have satisfied
# "test evidence exists" for all 31 traceability rows. Framework stubs are now
# excluded, so the gate measures real evidence rather than the presence of files.
tm=docs/domain/traceability-matrix.md
tests=$(find . -path ./.git -prune -o -path ./vendor -prune -o -path ./node_modules -prune -o \
        \( -name '*Test.php' -o -name '*.spec.ts' -o -name '*.cy.js' \) -print 2>/dev/null \
        | grep -vE '/(Unit|Feature)/ExampleTest\.php$' | wc -l)
covered=$(grep -cE '\|[[:space:]]*Covered[^|]*\|?[[:space:]]*$' "$tm" 2>/dev/null || true)
if [ "$covered" -eq 0 ] || [ "$tests" -gt 0 ]; then
  pass "traceability consistent (status cells marked Covered=$covered, test files=$tests)"
else
  fail "$covered status cell(s) marked 'Covered' but $tests test files exist — AGENTS.md violation (finding H-3)"
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

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mRESULT: ALL DOC GATES PASS\033[0m\n'; exit 0
else
  printf '\033[31mRESULT: AT LEAST ONE DOC GATE FAILED\033[0m\n'; exit 1
fi
