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
  pass "49 asserted colour pairs meet WCAG 2.1 AA"
else
  fail "contrast regression — see output below"; cat /tmp/contrast.log
fi

# ---------------------------------------------------------------------------
head2 "GATE 2 — no hardcoded design values outside tokens.css"
# ---------------------------------------------------------------------------
hits=$(grep -rInE '#[0-9A-Fa-f]{6}\b' \
        --include='*.blade.php' --include='*.css' --include='*.js' --include='*.php' \
        resources/ app/ 2>/dev/null | grep -v 'resources/css/tokens.css' \
        | grep -v 'app/Support/Design/generated/' \
        | grep -v 'app/Support/Design/BrandAssetBuilder.php' \
        | grep -v 'resources/views/errors/404.blade.php' \
        | grep -v 'resources/views/errors/500.blade.php' || true)
# app/Support/Design/generated/ (FilamentPalette.php) is machine-generated
# FROM tokens.css by `php artisan design:generate-filament-palette`
# (design-system.md §8.3 OQ-09) — its hex values are a derived artifact, not
# an independent source of truth, the same reasoning tokens.css itself is
# exempted for. `design:verify-filament-palette` (§9.5 gate 6) is what
# actually keeps it honest against drift, not this gate.
# app/Support/Design/BrandAssetBuilder.php contains exactly one literal,
# #FFFFFF — the inverse-mark recolour target / apple-touch-icon flatten
# backdrop for the raster logo pipeline (brand-identity-adoption plan, Task
# 3). This is a generated-artwork recolour target, not a UI design decision
# tokens.css governs, the same reasoning as the generated/ exemption above.
# resources/views/errors/404.blade.php and 500.blade.php are the third,
# deliberate exception (found and documented 26 Aug 2026 fixing a real CI
# regression — see 404.blade.php's own doc block for the full incident):
# error-boundary views must render even when the Vite build pipeline is
# broken or missing, so they cannot depend on `@vite`/tokens.css's normal
# `@theme` pipeline the way every other view does. Both files' inline
# <style> blocks copy their literal values verbatim from tokens.css, with
# the exact source line documented per value — the same "derived artifact,
# not an independent source of truth" reasoning as the two exemptions
# above, just copied by hand instead of by a generator command, because an
# error page cannot safely invoke one at render time either.
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
      if (id !~ /^[A-Z]+(-[A-Z]+)*-[0-9]+$/) next
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

# ---------------------------------------------------------------------------
head2 "GATE 13 — sql/revoke-journal-mutations.sql conforms to the canonical revoke shape"
# ---------------------------------------------------------------------------
# The append-only DB revoke for the financial ledger must stay a faithful
# sibling of the audit twin (app/Platform/Audit/sql/revoke-audit-mutations.sql):
# reference-only (NOT executed), N-1 role-split blocker stated, per-table
# REVOKE/GRANT, the SET ROLE verification snippet, and NEVER applied via
# ALTER DEFAULT PRIVILEGES. If N-1 is ever resolved and a real revoke is
# written, this gate is what keeps the two files from drifting apart.
rj=sql/revoke-journal-mutations.sql
rj_fail=0
check_rj() { if grep -qE "$1" "$rj"; then :; else echo "    missing: $2 ($1)" >&2; rj_fail=1; fi; }
if [ ! -f "$rj" ]; then
  fail "sql/revoke-journal-mutations.sql is missing"
else
  check_rj '^-- NOT executed' 'reference-only header'
  check_rj 'Finding N-1' 'N-1 role-split blocker'
  check_rj '^REVOKE UPDATE, DELETE ON journal_batches FROM <app_role>;' 'REVOKE on journal_batches'
  check_rj '^REVOKE UPDATE, DELETE ON journal_entries FROM <app_role>;' 'REVOKE on journal_entries'
  check_rj '^GRANT SELECT, INSERT ON journal_batches TO <app_role>;' 'GRANT on journal_batches'
  check_rj '^GRANT SELECT, INSERT ON journal_entries TO <app_role>;' 'GRANT on journal_entries'
  check_rj 'SET ROLE <app_role>;' 'verification snippet (SET ROLE)'
  check_rj 'expected: ERROR: permission denied' 'verification snippet (permission-denied expectation)'
  if grep -E 'ALTER DEFAULT PRIVILEGES' "$rj" | grep -vq '^--'; then
    echo "    ALTER DEFAULT PRIVILEGES used outside a warning comment" >&2; rj_fail=1
  fi
  if [ "$rj_fail" -eq 0 ]; then pass "sql/revoke-journal-mutations.sql is a canonical-shaped reference revoke"
  else fail "sql/revoke-journal-mutations.sql drifted from the canonical shape"; fi
fi

# ---------------------------------------------------------------------------
head2 "GATE 14 — image weight budget (DS-01, docs/superpowers/plans/2026-09-06-batch2g-cicd-hardening.md)"
# ---------------------------------------------------------------------------
# DS-01 replaced the 7.2MB public/images/hero/cemetery-garden-daylight.jpg
# with responsive AVIF/WebP derivatives (see hero.blade.php's <picture>
# markup). This gate keeps the mistake from recurring: no image checked
# into public/images or resources/images may exceed 300KB (comfortably
# above the largest single derivative, ~110KB, while still catching an
# accidentally-committed full-resolution photo), and AVIF/WebP derivatives
# specifically (the ones actually served to most browsers) must stay
# under the 120KB budget DS-01 set.
img_fail=0
while IFS= read -r -d '' f; do
  sz=$(stat -c%s "$f" 2>/dev/null || stat -f%z "$f" 2>/dev/null)
  [ -z "$sz" ] && continue
  case "$f" in
    *.avif|*.webp)
      if [ "$sz" -gt 122880 ]; then
        echo "    $f is $((sz / 1024))KB, exceeds the 120KB AVIF/WebP budget" >&2
        img_fail=1
      fi
      ;;
    *)
      if [ "$sz" -gt 307200 ]; then
        echo "    $f is $((sz / 1024))KB, exceeds the 300KB general image budget" >&2
        img_fail=1
      fi
      ;;
  esac
done < <(find public/images resources/images -type f \
    \( -iname '*.jpg' -o -iname '*.jpeg' -o -iname '*.png' -o -iname '*.webp' -o -iname '*.avif' -o -iname '*.gif' \) \
    -print0 2>/dev/null)
if [ "$img_fail" -eq 0 ]; then pass "no oversized images under public/images or resources/images"
else fail "image weight budget exceeded"; fi

# ---------------------------------------------------------------------------
head2 "GATE 15 — remediation findings ledger is well-formed"
# ---------------------------------------------------------------------------
# docs/remediation/findings.yml is the single source of truth for the 343
# audit findings (W0). Before it existed the only copy lived in an ephemeral
# agent scratchpad outside version control, so the programme could not be
# stopped safely. A ledger nobody validates drifts back into uselessness, so
# this gate asserts the shape every consumer relies on: unique ids, a closed
# status vocabulary, and a status_note wherever a status was hand-corrected
# away from the mechanical derivation.
#
# Deliberately parsed WITHOUT PyYAML: ci/verify-docs.sh must run with no
# application code and no pip install (see this file's own header), and
# PyYAML is not guaranteed on the CI image. The file is generated to a
# strict shape, so a line-oriented reader is sufficient and dependency-free.
if [ ! -f docs/remediation/findings.yml ]; then
  fail "docs/remediation/findings.yml is missing"
else
  python3 - <<'LEDGER' || fail "findings ledger is malformed"
import re, sys

VALID_STATUS = {"open", "mitigated", "in_review", "resolved"}
VALID_SEV = {"Critical", "High", "Medium", "Low", "Info"}

records, cur = [], None
for line in open("docs/remediation/findings.yml"):
    m = re.match(r"- id: (\S+)$", line)
    if m:
        cur = {"id": m.group(1)}
        records.append(cur)
        continue
    if cur is None:
        continue
    m = re.match(r"  (\w+):\s*(.*)$", line)
    if m:
        cur[m.group(1)] = m.group(2).strip()

errs = []
if not records:
    errs.append("no records parsed")

seen = set()
for r in records:
    rid = r["id"]
    if rid in seen:
        errs.append(f"{rid}: duplicate id")
    seen.add(rid)
    # Dimension prefixes may contain digits (I18N), so not [A-Z]+ alone.
    if not re.fullmatch(r"[A-Z][A-Z0-9]*-\d+", rid):
        errs.append(f"{rid}: id is not <DIM>-<NN>")
    if r.get("severity") not in VALID_SEV:
        errs.append(f"{rid}: severity {r.get('severity')!r} not in {sorted(VALID_SEV)}")
    if r.get("status") not in VALID_STATUS:
        errs.append(f"{rid}: status {r.get('status')!r} not in {sorted(VALID_STATUS)}")
    for key in ("statement", "recommendation", "status_evidence", "status_verified"):
        if key not in r:
            errs.append(f"{rid}: missing {key}")
    # A hand-corrected status must carry the quotation that justifies it.
    if r.get("status_verified") == "true" and "status_note" not in r:
        errs.append(f"{rid}: status_verified true without status_note")
    # Anything claimed closed must name the PR that closed it.
    if r.get("status") in {"resolved", "mitigated", "in_review"} and r.get("status_evidence") == "[]":
        errs.append(f"{rid}: status {r['status']} with empty status_evidence")

if errs:
    for e in errs[:20]:
        print(f"    {e}", file=sys.stderr)
    if len(errs) > 20:
        print(f"    ... and {len(errs) - 20} more", file=sys.stderr)
    sys.exit(1)

print(f"    {len(records)} findings, ids unique, status vocabulary closed")
LEDGER
  if [ "$FAIL" -eq 0 ]; then
    pass "remediation findings ledger is well-formed"
  fi
fi

# ---------------------------------------------------------------------------
head2 "GATE 16 — host specification is not restated outside host-facts.md"
# ---------------------------------------------------------------------------
# docs/operations/host-facts.md is the single source of truth for what the
# non-production host IS (AGENTS.md §Documentation: "Do not duplicate canonical
# catalog data in multiple hand-maintained documents or code locations").
#
# This gate exists because of a real, found failure, not a hypothetical one.
# The host's specification was restated in seven operations documents and ALL
# SEVEN WERE WRONG: they described a 2 vCPU / 4 GB / Ubuntu 22.04 machine — the
# retired `adrivm` host — while the stack has run on an 8 vCPU / 31 GB /
# Ubuntu 24.04 machine since the 17 Aug 2026 migration. Capacity limits, swap
# thresholds and "too small for X" judgements were all being argued against a
# machine that no longer existed. Correcting one copy would not have corrected
# the other six, which is exactly what a gate is for.
#
# Allowlist, and why each entry is legitimate rather than an exemption:
#   docs/operations/host-facts.md          the canonical file itself
#   docs/adr/**                            ADRs are immutable decision records;
#                                          ADR-0027's own FILENAME contains
#                                          "ubuntu22-2v4g", so every link to it
#                                          carries the string by necessity
#   docs/operations/2026-08-17-*yiemvm.md  a dated migration record; its
#                                          "2 vCPU" describes adrivm, the host
#                                          being retired, and is correct there
#   any line containing "former line"      a document quoting its own
#                                          superseded text verbatim as history
#   docs/superpowers/**                    append-only historical tree
#                                          (AGENTS.md:154)
#   docs/review/**                         dated review summaries; each records
#                                          what was believed at its own date
#   docs/planning/sprint-plan.md           a dated sprint ledger: its remaining
#                                          hits are risk-register rows, Sprint 1
#                                          open questions and dated task rows,
#                                          all recording a past position. It is
#                                          ALSO slated for W0b, which migrates
#                                          its N-xx/OQ-xx ledgers out; gating it
#                                          now would obstruct that work rather
#                                          than help it. Revisit after W0b.
#   any line containing "superseded"       a correction that must quote the
#                                          old value in order to supersede it
#
# Every allowlist entry above is a document RECORDING history, never a document
# ASSERTING the current state. That is the line: if a sentence tells a reader
# what the host is today, it belongs in host-facts.md or references it.
HOSTSPEC_HITS=$(grep -rInE '2 vCPU|2/4 host|2/4 combined host|4 GB RAM|Ubuntu 22\.04' \
      --include='*.md' docs/ 2>/dev/null \
      | grep -v '^docs/operations/host-facts\.md:' \
      | grep -v '^docs/adr/' \
      | grep -v '^docs/superpowers/' \
      | grep -v '^docs/review/' \
      | grep -v '^docs/planning/sprint-plan\.md:' \
      | grep -v '^docs/operations/2026-08-17-makam-migration-to-yiemvm\.md:' \
      | grep -v '0027-combine-dev-staging-on-ubuntu22-2v4g' \
      | grep -v 'former line' \
      | grep -v 'superseded' || true)
if [ -z "$HOSTSPEC_HITS" ]; then
  pass "host specification lives only in docs/operations/host-facts.md"
else
  fail "host specification restated outside host-facts.md — reference that file instead of copying its numbers"
  printf '%s\n' "$HOSTSPEC_HITS" | sed 's/^/    /'
fi

# ---------------------------------------------------------------------------
head2 "GATE 17 — host-facts.md carries a last-verified date that is not stale"
# ---------------------------------------------------------------------------
# A canonical facts file is only worth trusting if someone re-checked it
# recently. GATE 16 stops the numbers from being COPIED; it cannot tell whether
# they are still TRUE. This one bounds how long a wrong number can sit there
# unchallenged. It detects staleness, never incorrectness — re-verifying is a
# human/agent action against the live host, and the date is the claim that it
# happened.
python3 - <<'HOSTFACTS'
import datetime, re, sys, pathlib

p = pathlib.Path("docs/operations/host-facts.md")
if not p.exists():
    print("    docs/operations/host-facts.md is missing", file=sys.stderr); sys.exit(1)

text = p.read_text(encoding="utf-8")
m = re.match(r"---\n(.*?)\n---\n", text, re.S)
if not m:
    print("    host-facts.md has no YAML front matter", file=sys.stderr); sys.exit(1)

d = re.search(r"^last-verified:\s*(\d{4})-(\d{2})-(\d{2})\s*$", m.group(1), re.M)
if not d:
    print("    host-facts.md front matter has no last-verified: YYYY-MM-DD", file=sys.stderr); sys.exit(1)

verified = datetime.date(int(d.group(1)), int(d.group(2)), int(d.group(3)))
today = datetime.date.today()
age = (today - verified).days
MAX_AGE_DAYS = 90

if verified > today:
    print(f"    last-verified {verified} is in the future", file=sys.stderr); sys.exit(1)
if age > MAX_AGE_DAYS:
    print(f"    last-verified {verified} is {age} days old (max {MAX_AGE_DAYS}) — "
          "re-run the commands in the file's 'How to re-verify' section and update the date",
          file=sys.stderr)
    sys.exit(1)

print(f"    last-verified {verified} ({age} day(s) old, max {MAX_AGE_DAYS})")
HOSTFACTS
if [ $? -eq 0 ]; then
  pass "host-facts.md last-verified date is present and within 90 days"
else
  fail "host-facts.md last-verified date is missing, malformed, or stale"
fi

# ---------------------------------------------------------------------------
head2 "GATE 18 — every operations document declares whether anyone has checked it"
# ---------------------------------------------------------------------------
# W4 found that operations documents drift silently, and that the drift is
# invisible precisely because a confident-sounding document reads the same
# whether or not anyone has ever checked it. Four separate documents claimed
# the backups were "daily encrypted ... to remote object storage"; none of
# those three words was true. A rollback runbook told an operator to restart
# containers that do not exist. Nothing in the corpus distinguished a claim
# somebody had verified from a claim somebody had merely written.
#
# So every .md under docs/operations/ must declare one of three states in YAML
# front matter, and the honest one is the default:
#
#   verification: verified     someone checked this document's substantive
#                              claims against the live system. REQUIRES
#                              `last-verified: YYYY-MM-DD`, max 90 days old.
#   verification: historical   a dated record of a past event. Correct as
#                              history; never re-verified; no date required.
#   verification: unverified   nobody has checked it. REQUIRES a
#                              `verification-note` saying so plainly.
#
# `verification-note` is required in all three cases. For `verified` it says
# WHAT was checked, which is what stops a one-line correction being passed off
# as a whole-document verification — the distinction this gate exists to keep.
#
# This gate deliberately cannot tell whether a document is CORRECT. It tells
# you whether anyone has claimed to check it, and when. That is the honest
# limit of what a mechanical check can know, and it is stated here so nobody
# reads a green gate as "the runbooks are accurate".
python3 - <<'OPSVERIFY'
import datetime, pathlib, re, sys

MAX_AGE_DAYS = 90
VALID = {"verified", "historical", "unverified"}
base = pathlib.Path("docs/operations")
files = sorted(list(base.glob("*.md")) + list(base.glob("runbooks/*.md")))
errs = []
tally = {"verified": 0, "historical": 0, "unverified": 0}
today = datetime.date.today()

if not files:
    print("    no operations documents found - is the path right?", file=sys.stderr)
    sys.exit(1)

for p in files:
    rel = str(p)
    text = p.read_text(encoding="utf-8")
    m = re.match(r"---\n(.*?)\n---\n", text, re.S)
    if not m:
        errs.append(f"{rel}: no YAML front matter (needs a verification: field)")
        continue
    fm = m.group(1)

    state = re.search(r"^verification:\s*(\S+)\s*$", fm, re.M)
    if not state:
        errs.append(f"{rel}: front matter has no verification: field")
        continue
    state = state.group(1)
    if state not in VALID:
        errs.append(f"{rel}: verification: {state!r} not one of {sorted(VALID)}")
        continue
    tally[state] += 1

    if "verification-note:" not in fm:
        errs.append(f"{rel}: verification: {state} without a verification-note")

    if state == "verified":
        d = re.search(r"^last-verified:\s*(\d{4})-(\d{2})-(\d{2})\s*$", fm, re.M)
        if not d:
            errs.append(f"{rel}: verification: verified without last-verified: YYYY-MM-DD")
            continue
        when = datetime.date(int(d.group(1)), int(d.group(2)), int(d.group(3)))
        if when > today:
            errs.append(f"{rel}: last-verified {when} is in the future")
        elif (today - when).days > MAX_AGE_DAYS:
            errs.append(f"{rel}: last-verified {when} is {(today - when).days} days old (max {MAX_AGE_DAYS})")

if errs:
    for e in errs[:20]:
        print(f"    {e}", file=sys.stderr)
    if len(errs) > 20:
        print(f"    ... and {len(errs) - 20} more", file=sys.stderr)
    sys.exit(1)

print(f"    {len(files)} operations documents: {tally['verified']} verified, "
      f"{tally['historical']} historical, {tally['unverified']} unverified")
print("    (this gate proves only that the state is DECLARED, never that a document is correct)")
OPSVERIFY
if [ $? -eq 0 ]; then
  pass "every operations document declares its verification state"
else
  fail "an operations document is missing or misdeclaring its verification state"
fi

printf '\n'
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mRESULT: ALL DOC GATES PASS\033[0m\n'; exit 0
else
  printf '\033[31mRESULT: AT LEAST ONE DOC GATE FAILED\033[0m\n'; exit 1
fi
