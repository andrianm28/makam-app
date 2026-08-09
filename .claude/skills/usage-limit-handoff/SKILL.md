---
name: usage-limit-handoff
description: Prepare a clean, disk-durable handoff when approaching or after hitting an API usage/session limit mid-task, especially during multi-agent parallel work (Superpowers SDD retrofit lanes, parallel worktrees). Use proactively when told to "prepare for handoff", "prepare for usage limit", or "stop subagents" — or reactively the moment any dispatched agent's notification reports a session-limit API error. Also covers resuming cleanly from a handoff, whether by this same session after reset or a different agent harness entirely.
---

# Usage-Limit Handoff

## Why this exists

Written after a real incident: several parallel Superpowers SDD retrofit lanes were mid-flight when the account hit its session limit. Multiple dispatched subagents' internal task-scoped reviewers died mid-run with no report ever written — that reasoning is genuinely gone, not recoverable. But nothing on disk was lost: every worktree's git state was clean, every plan doc and task brief that had already been committed survived. The difference between "real setback" and "clean pause" was entirely about what had been written to disk versus what only existed in a conversation that was about to disappear. This skill exists to make that distinction deliberate instead of accidental.

## When to trigger

- **Proactively:** the user says anything like "prepare for handoff", "prepare for usage limit", or "stop subagents" — or a usage-limit warning appears while agents are still in flight.
- **Reactively:** the moment ANY dispatched subagent's notification reports a session-limit or API error (not a normal completion/failure). Treat this as a signal the whole account may be affected, not just that one agent — stop assuming other in-flight agents are safe.

## Part 1 — Going into handoff

1. **Stop cleanly.** `TaskStop` every named subagent still running and every long-running `Monitor` task. A subagent killed by a hard API error loses any reasoning since its last file write — only what it already committed to disk survives. Stop deliberately before more half-finished work accumulates.
2. **Verify every in-flight worktree is actually clean** — don't trust a subagent's self-report. `git status --short` (expect empty, or only known in-progress untracked files) and `git log --oneline -5` (the real last-committed state). If a report and the git log disagree, the git log wins — reports can be wrong, or, per the incident above, simply never get written before the process dies.
3. **Check each worktree's branch base against current origin.** `git merge-base HEAD origin/<trunk>` should equal `origin/<trunk>`'s current tip. A worktree created before a sibling lane's PR merged is stale — resuming without fixing this silently reintroduces outdated shared-doc content (this repo's recurring example: `docs/planning/retrofit-backlog.md` / `docs/planning/sprint-plan.md`) into that lane's eventual PR. Rebase any stale worktree now, before writing the handoff, so the handoff doesn't have to explain a problem that's cheap to just fix.
4. **Write a `HANDOFF STATUS` section at the very top of the governing plan file** (this repo: `/home/ubuntu/.claude/plans/<slug>.md`) — never a separate throwaway note; the plan file is the one place already established as the resumption anchor for both Claude Code's own plan-mode tooling and a human re-reading it cold. For every in-flight unit of work, record:
   - Worktree path + branch name
   - Exact last commit hash and message (from git, not from a claimed report)
   - Ledger location (this repo's Superpowers convention: `.superpowers/sdd/<plan-slug>/progress.md` plus any `task-N-brief*.md` files) — note explicitly that these ARE real, reusable inputs on resume; only a dead agent's *unwritten* reasoning is gone
   - Exact pipeline stage reached (plan committed / task-scoped review dispatched / mid-implementation / whole-module review / fix wave / Task 5 disposition / PR open)
   - Any cross-lane decisions already made, so a fresh resuming agent doesn't re-litigate them (real example: two sibling lanes independently found paired dead-code stubs sharing files; the ruling — ledger both, delete neither inline, one future combined cleanup PR — had to be recorded once so both lanes honored it without re-deciding)
   - Exact resume instructions naming which skill/step comes next, against the EXISTING plan doc and EXISTING briefs — never "regenerate the plan," always "read the plan already there"
5. **Update the task list** (`TaskUpdate`): every in-flight task's status goes back to `pending` (nothing is actually running), and its description carries enough pointer information to resume from the task list alone — point at the plan file's `HANDOFF STATUS` section rather than duplicating it at length.
6. **Give the user one concise final summary**: what's safely done, the single most urgent remaining item if one exists (e.g., a blocking CI fix that gates a deadline), and where the full detail lives. Don't re-explain what's already written to disk.

## Part 2 — Resuming from a handoff

1. Read the plan file's `HANDOFF STATUS` section first. Treat it as authoritative — a fresh session or a different harness entirely may have no access to the conversation that produced it.
2. For each in-flight lane, re-verify the worktree's branch base against current origin — state may have moved further since the handoff was written. Rebase if stale, same as step 3 above.
3. Re-dispatch one fresh agent per lane, explicitly instructed to READ the existing plan doc and ledger/briefs rather than rewrite them, and to verify — not trust — any commit a now-dead prior agent claimed to have made (read the actual diff before treating a claimed step as done).
4. Re-apply every cross-lane decision recorded in the handoff verbatim. Don't re-decide them.
5. Continue the pipeline from wherever the ledger shows it genuinely stopped, not from wherever a dead agent's last message implied it was.

## Anti-patterns

- Writing the handoff only in a chat reply. The next session or harness cannot read prior conversation turns — only files on disk are guaranteed reachable.
- Trusting a dispatched agent's self-reported completion over the real git log, once that agent is known to have died mid-report.
- Re-planning a lane from scratch on resume "because it's faster than reading the old plan" — this discards real, already-reviewed decisions and risks silently contradicting them.
- Resuming a worktree without re-checking its branch base. The single most common real defect this pattern produces is a stale worktree quietly reintroducing already-superseded shared-doc content into a new PR.
