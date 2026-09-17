#!/usr/bin/env bash
#
# Refuse a pull request whose base branch has ALREADY merged into trunk.
#
# -----------------------------------------------------------------------------
# The failure this exists for
# -----------------------------------------------------------------------------
# On 16 Sep 2026, three pull requests merged and their work never reached trunk:
#
#   #307  base feat/refund-obligation-ledger        Tahap R1 of the refund plan
#   #312  base feat/kamboja-tahap2-surface-brand    Kamboja Tahap 4
#   #313  base feat/kamboja-tahap4-component-hierarchy  Kamboja Tahap 8
#
# Each had been opened against its parent branch, which is a legitimate way to
# stack work. Each was then merged AFTER that parent had itself merged to
# trunk — #302 merged at 03:15:12Z and #307 merged into #302's branch at
# 03:15:22Z, ten seconds later. Nothing merged that branch again, so the
# commits sat in a branch no longer on anyone's path to trunk.
#
# GitHub showed all three as MERGED. Nothing else showed anything at all. The
# refund system's keystone was missing from trunk for a day and the only
# symptom was that `OpenRefundObligation` had no caller.
#
# -----------------------------------------------------------------------------
# What this checks, and what it deliberately allows
# -----------------------------------------------------------------------------
# Stacking is not the defect and this does not forbid it. A PR based on a
# branch that has NOT yet merged is fine: that branch still carries this work
# to trunk when it lands.
#
# The defect is narrower and exactly checkable — a base branch that is ALREADY
# an ancestor of trunk. Merging into it cannot reach trunk by any later step,
# because there is no later step: that branch's journey is over.
#
#   base IS trunk                  -> pass
#   base not yet merged to trunk   -> pass (ordinary stacking)
#   base already merged to trunk   -> FAIL, this merge would strand the work
#
# -----------------------------------------------------------------------------
# It fails closed
# -----------------------------------------------------------------------------
# If either ref cannot be resolved, this exits non-zero rather than passing.
# A check that cannot see what it is checking must say so — the lesson of
# DB-13, where a gate reported PASS against data it could not match, and of
# GATE 19, which refuses to run on a shallow clone for the same reason. Needs
# `fetch-depth: 0` and both refs fetched.
#
# Usage: ci/verify-pr-base.sh <base-ref> [trunk-ref]
set -uo pipefail

BASE="${1:-}"
TRUNK="${2:-docs/design-system-and-planning}"

if [ -z "$BASE" ]; then
  printf 'FAIL  no base ref given\n' >&2
  printf 'Usage: ci/verify-pr-base.sh <base-ref> [trunk-ref]\n' >&2
  exit 2
fi

printf 'PR base check — base=%s trunk=%s\n' "$BASE" "$TRUNK"

if [ "$BASE" = "$TRUNK" ]; then
  printf 'PASS  base is trunk\n'
  exit 0
fi

resolve() {
  # Accept a bare branch name or an already-qualified ref, local or remote.
  for candidate in "$1" "refs/heads/$1" "origin/$1" "refs/remotes/origin/$1"; do
    if git rev-parse --verify --quiet "${candidate}^{commit}" >/dev/null 2>&1; then
      git rev-parse "${candidate}^{commit}"
      return 0
    fi
  done
  return 1
}

BASE_SHA=$(resolve "$BASE") || {
  printf 'FAIL  cannot resolve base ref %s — fetch it before running this check\n' "$BASE" >&2
  exit 1
}

TRUNK_SHA=$(resolve "$TRUNK") || {
  printf 'FAIL  cannot resolve trunk ref %s — fetch it before running this check\n' "$TRUNK" >&2
  exit 1
}

if git merge-base --is-ancestor "$BASE_SHA" "$TRUNK_SHA"; then
  printf 'FAIL  base branch %s has ALREADY merged into %s\n' "$BASE" "$TRUNK" >&2
  printf '      Merging into it would put this work in a branch nothing merges again.\n' >&2
  printf '      Retarget this pull request at %s.\n' "$TRUNK" >&2
  printf '      (This is what stranded #307, #312 and #313 on 16 Sep 2026.)\n' >&2
  exit 1
fi

printf 'PASS  base %s has not merged yet — this stack still reaches trunk\n' "$BASE"
exit 0
