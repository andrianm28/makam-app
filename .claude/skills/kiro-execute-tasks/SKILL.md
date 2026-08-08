---
name: kiro-execute-tasks
description: Implement a Kiro spec's tasks.md — dependency waves for concurrent execution, syncing already-finished work back into checkboxes, and the verification each task needs before its box is ticked. Use when asked to implement a spec, run its task list, continue a paused spec, or reconcile tasks.md against what the code already does.
---

# Executing a spec's tasks

Source: [kiro.dev/docs/specs/best-practices](https://kiro.dev/docs/specs/best-practices/) and
`feature-specs`.

> **Honesty note.** "Run all Tasks" and "Sync Files" are Kiro IDE/CLI buttons. This skill is the
> manual equivalent. Do not report that a Kiro action ran.

## Dependency waves

Kiro analyses the task list, builds a dependency graph, and groups independent tasks into **waves**:
waves run sequentially, tasks *within* a wave run concurrently, and dependent tasks wait for their
prerequisites.

Do the same by hand:

1. Read the whole `tasks.md` before starting anything.
2. Derive the graph — schema before the code reading it; domain Action before the UI calling it;
   seeds before the tests asserting on them; a shared primitive before its consumers.
3. Group into waves. Only fan out work that is genuinely independent, and only if the user has
   asked for multi-agent execution — otherwise do it sequentially yourself.
4. Never start a wave whose prerequisites are unverified.

**Concurrent work must not share files.** This repo's finding **N-9** is exactly that lesson: three
concurrent agents each stayed inside their own file boundary, which was correct, and left three
cross-cutting integration seams *explicitly flagged* rather than silently wired. Do that — flag the
seam, do not reach into another wave's files.

## Verification before a box is ticked

`AGENTS.md`: *"Never report `PASS` for a check that was not executed."*

This host **cannot** run `composer install`, `npm ci`, `npm run build`, PHPUnit, Pint, or PHPStan —
`vendor/` is empty by policy (`CLAUDE.md`; `ci-cd-and-release.md` §10 keeps builds off the 2 vCPU
host). So:

| Check | Where it really runs |
|---|---|
| Syntax | `php -l` locally — the one thing that does work |
| Doc/design gates | `bash ci/verify-docs.sh` locally — 12 gates, no build needed |
| Style, static analysis, tests, frontend build | **CI only** — `.github/workflows/ci.yml` |
| Real framework behaviour when in doubt | sibling project `/home/ubuntu/platform-galang-dana-app` (same pinned versions) |
| Infrastructure gates | `ci/verify-infra.sh`, deployment host only |

**A batch is not done until its own CI run is green** — not when local checks pass. Watch the run,
then tick the box with how it was verified: `done <date> (Batch N), CI green`.

Anything not verified stays `[ ]` with the gap stated: partially done, `BLOCKED`, or `NOT TESTED`.
Responsive/accessibility work usually lands here — there is no browser on this host.

## Sync — reconciling tasks.md with reality

When work is already implemented (or was done outside the task list):

1. Read the code, not the checkbox.
2. For each task, decide: fully done and verified → `[x]` with evidence; partially done → `[ ]`
   with the split written out; not started → leave it.
3. Check `_Requirements: N_` still matches what was actually built. If the implementation drifted
   from the criterion, the criterion or the annotation is wrong — surface it, do not quietly
   re-point the annotation.
4. New criteria added since? Add tasks for them, and only then update the annotations.

## Stop conditions

Stop and ask, per `AGENTS.md` and `sprint-plan.md` §10:

- a task marked `⚠️ HUMAN` — security, authorization, financial, privacy, destructive migration,
  DNS, firewall, production;
- a destructive database or volume change;
- a missing secret or provider account;
- an incompatibility that would require changing the pinned baseline;
- anything involving production data or credentials.

An agent may prepare and propose all of these. None may be executed without recorded approval.

## After the batch

- Update `tasks.md` honestly.
- Record any non-obvious finding in `docs/planning/sprint-plan.md`'s finding list (N-…), including
  scope boundaries and deliberate omissions.
- Update the spec, `docs/domain/traceability-matrix.md`, `docs/product/screen-inventory.md`, the
  OpenAPI contract, and tests when behaviour changed — `AGENTS.md` §Documentation requires it.
- Leave `git status` clean.
