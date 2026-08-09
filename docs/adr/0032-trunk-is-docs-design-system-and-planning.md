# ADR-0032: `docs/design-system-and-planning` Is the Working Trunk; PR #1 Closed Without Merging

## Status

Accepted.

## Context

`master` has been the repository's default branch since the documentation-only baseline import (commit `05f6f4d`), and holds zero lines of application code — `git ls-tree -r master -- app/Platform` returns no rows. Every line of Sprint 1-4 application code (~17.5k LOC across `app/Domain/**` and `app/Platform/**`, ~103 of the repository's 104 commits at the time of this ADR) was committed directly to a long-running branch, `docs/design-system-and-planning`, opened 25 Jul 2026.

That branch has sat under **PR #1**, opened 25 Jul 2026 against `master`, ever since — `mergedAt: null` as of this ADR. No review of PR #1 as a unit ever happened; every commit on it landed by direct push under the batch-fan-out process `sprint-plan.md`'s "Execution methodology (S4-T2 onward)" section describes (see the correction appended there the same day as this ADR). In practice, every deployment to `dev.makam.co.id` to date, every CI run that matters, and this repository's own documentation all already treat `docs/design-system-and-planning` as the real trunk — `master` is a historical artifact, not a branch anyone builds from or deploys.

This ambiguity became directly relevant when adopting Superpowers SDD as the standard development methodology (`AGENTS.md` §Development methodology, added the same day as this ADR): that methodology requires every unit of work to land as its own reviewed PR against a named trunk. Leaving PR #1 open indefinitely, with `master` nominally the base, would either imply the ~103 unreviewed commits on it are pending a review that will never happen, or would require reviewing 103 commits as one unit — neither is useful. The per-module retrofit program (see the same day's planning-doc entries) is the actual mechanism for reviewing that historical work, piece by piece, against the new standard.

## Decision

1. `docs/design-system-and-planning` is the de facto working trunk. All new PRs (new features and module retrofits alike) target this branch, matching the one PR this repository has actually merged (PR #2, the booking wizard).
2. `master` is retained as-is — a deliberately stale, documentation-only baseline — pending a future, separate decision about whether and when to promote `docs/design-system-and-planning`'s content onto it (e.g. at a release boundary). This ADR does not make that promotion decision.
3. **PR #1 is closed without merging.** Its ~103 commits were never reviewed as a unit, and merging it now would falsely imply that review happened. The actual review of that historical work is happening incrementally via the per-module retrofit program, each retrofit landing as its own new, real PR against `docs/design-system-and-planning`.

## Consequences

### Positive

- Removes a standing ambiguity about which branch is trunk — the answer now matches what every deployment, every real PR, and this ADR itself already assume.
- PR #1's closure is honest about what has and hasn't been reviewed, rather than leaving a stale, unreviewable PR open indefinitely or merging it as a false attestation of review.
- Establishes one consistent target branch for the Superpowers SDD methodology's "every unit of work lands as its own PR" rule.

### Negative

- `master` remains uninformative about the project's real state for anyone who checks out the default branch without knowing to look at `docs/design-system-and-planning` instead. This is a pre-existing condition, not introduced by this ADR, and is explicitly deferred rather than fixed here.
- Closing PR #1 discards its accumulated (but never actioned) review comments, if any exist. None were found attached to it at the time of this ADR.

## Reversal

If `master` is later promoted to match `docs/design-system-and-planning` (a separate decision), this ADR's distinction becomes moot and can be marked superseded rather than reversed. Re-opening PR #1 itself would serve no purpose even then, since its commits will already be reachable via `master` through the promotion, not through PR #1.
