# Issue Tracker

**This repository has no external issue tracker, and that is a recorded
decision — not an omission.** See
[`docs/adr/0038-tasks-md-owns-per-spec-progress-no-external-issue-tracker.md`](../adr/0038-tasks-md-owns-per-spec-progress-no-external-issue-tracker.md).

Do not run `gh issue create`. Measured 19 Sep 2026: 0 GitHub issues, 334 pull
requests. Work has always flowed through PRs.

## Where each kind of work item lives

| Kind | Home | Notes |
| --- | --- | --- |
| Per-spec progress | `.kiro/specs/<spec>/tasks.md` | The durable answer to "what does this spec still need" |
| Audit findings | `docs/remediation/findings.yml` | Closed status vocabulary, enforced by `ci/verify-docs.sh` GATE 15 |
| A unit of work in flight | a pull request | One PR per unit of work, against the working trunk (`AGENTS.md` §Development methodology) |
| How one pass was executed | `docs/superpowers/plans/` | **Append-only history. Never consult it for current state** (`AGENTS.md`) |

## What the skills should do instead

- **`to-tickets`** — write the items into the relevant `tasks.md`, or into
  `findings.yml` when they are audit findings. Follow each file's existing
  shape; `findings.yml`'s required fields are enforced by GATE 15.
- **`triage`** — triage arrives as pull requests, not issues. The labels in
  [`triage-labels.md`](triage-labels.md) are defined but nothing currently
  carries them.
- **`to-spec`** — specs live in `.kiro/specs/` and
  `docs/superpowers/specs/`. New work follows Superpowers SDD: plan doc first,
  worktree isolation, one PR per unit of work.

## PRs as a request surface

**Off.** Flip this line if external PRs should enter the triage queue.
