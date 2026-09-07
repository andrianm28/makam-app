#!/usr/bin/env bash
# API-04 (audit finding, 07 Sep 2026) — diffs the real, currently-registered
# public route table against docs/product/route-manifest.json, so
# docs/product/information-architecture.md §1 cannot silently drift from
# the shipped route table the way it did before this gate existed.
#
# Needs a built vendor/ (php artisan route:list), so unlike ci/verify-docs.sh
# this cannot run with "no build required" — it runs as its own CI job,
# AFTER `composer install`, never on the combined dev+staging host (see
# CLAUDE.md's "Composer and npm builds run in CI, never on this host").
#
# Usage: bash ci/verify-route-manifest.sh   (run from the repo root, after
# `composer install` has populated vendor/)
set -uo pipefail
cd "$(dirname "$0")/.."

MANIFEST=docs/product/route-manifest.json
FILTER=ci/filter-public-routes.py

if [ ! -f "$MANIFEST" ]; then
  echo "FAIL: $MANIFEST is missing"
  exit 1
fi

php artisan route:list --json > /tmp/route-list.json 2>/tmp/route-list.err
if [ $? -ne 0 ]; then
  echo "FAIL: 'php artisan route:list --json' did not run cleanly:"
  cat /tmp/route-list.err
  exit 1
fi

python3 "$FILTER" /tmp/route-list.json > /tmp/route-manifest-actual.json

python3 - "$MANIFEST" /tmp/route-manifest-actual.json <<'PY'
import json
import sys

expected_path, actual_path = sys.argv[1], sys.argv[2]

with open(expected_path) as f:
    expected = {(r['method'], r['uri'], r['name']) for r in json.load(f)['routes']}

with open(actual_path) as f:
    actual = {(r['method'], r['uri'], r['name']) for r in json.load(f)}

missing = sorted(expected - actual)   # documented/manifested but no longer a real route
extra = sorted(actual - expected)     # a real route the manifest (and the IA doc) doesn't know about

if not missing and not extra:
    print(f"PASS: {len(expected)} manifested public route(s) match the real route table exactly")
    sys.exit(0)

if missing:
    print(f"FAIL: {len(missing)} manifested route(s) no longer exist in the real route table:")
    for m in missing:
        print(f"    {m}")
if extra:
    print(f"FAIL: {len(extra)} real route(s) are not in {expected_path} (undocumented drift):")
    for e in extra:
        print(f"    {e}")
print()
print("Fix: update docs/product/information-architecture.md §1 to match reality,")
print(f"then regenerate {expected_path} to match (see that file's own _generated_by note).")
sys.exit(1)
PY
