---
name: makam-agent-batch
description: Brief and run a batch of concurrent subagents on this repository — the mandatory brief template, what must never be fanned out, migration timestamp slots, worktree isolation, and how batches gate. Use when planning or launching multi-agent work here, splitting a sprint task across agents, or writing a brief for any subagent.
---

# Running an agent batch

`docs/planning/agent-execution-plan.md` is the authority: §2 is the brief template, §1.1–1.5 the non-negotiable rules, §§3–8 the per-sprint batch assignments. `docs/planning/parallelization-analysis.md` derives why. Read both; this skill is the working summary.

```
BATCH = N concurrent agents, disjoint files, one mechanical gate at the end
GATE  = ci/verify-docs.sh + CI — must pass before the next batch
HUMAN = a review gate from AGENTS.md. Agents PREPARE; a human EXECUTES.
```

## Never fan out

- **Live infrastructure.** `makam.co.id` is live.
- **Anything behind a human gate** — needs judgement, not throughput.
- **Single-writer artefacts**: `routes/web.php` · `resources/css/tokens.css` · `StatusIntent` · `resources/views/components/mk/**` · canonical catalogue constant classes · `composer.json`/lockfiles · `.github/workflows/ci.yml` · `layouts/app.blade.php` · the cross-batch planning docs (`sprint-plan.md`, `traceability-matrix.md`, `screen-inventory.md`).
- **Product or legal decisions.** Marketplace category codes, multi-vendor, CSP, brand primary. An agent that hits one should report it, not resolve it.

Two writers on one file is either a conflict or two sources of truth. When a batch needs a shared file changed, the orchestrator makes that change **before** the fan-out, or wires it **after** — never inside it.

## Migrations

Both controls are mandatory for any batch touching `database/migrations/`:

1. **Pre-assign a timestamp slot per agent** in the brief (`2026_08_08_100000`–`100099`, etc.). This repo already has three pairs of colliding timestamps from a batch that skipped this.
2. **`isolation: worktree`** so no agent sees a sibling's half-written migration.

Table ownership is declared normatively in each spec's `design.md`. An agent needing a table it does not own **references it and reports the dependency** — it never creates it.

Worktrees only carry **committed** files. Anything uncommitted — including `.claude/skills/**` — is invisible inside one. Commit before fanning out.

## Reference implementation first

For a batch producing more than three similar artefacts, one agent (or the orchestrator) builds the **first** one alone, and it becomes the convention brief for the rest. Skip this and eight primitives get eight styles. Costs one serial step; saves a rewrite.

## Sizing

Four concurrent tracks is the sweet spot. Above that, coordination overhead eats the gain, and the real constraint moves anyway: **agents change the rate of production, not of review.** Six agents produce six diffs for one reviewer.

## The brief template

Every brief uses §2's shape. It is proven — a six-agent batch on 25 Jul 2026 returned usable work from all six, including two that correctly refused to game a check.

```markdown
You are implementing <TASK ID> — <one-line goal> in /home/ubuntu/makam-app.

## You own exactly these files. Touch nothing else:
<explicit list or glob>
## Explicitly NOT yours:
<files another agent in this batch owns, named so this agent does not open them>

## Spec
Read first: .kiro/specs/<spec>/requirements.md and design.md
Acceptance criteria in scope: AC<n>, AC<m>
Foundations you may CONSUME but must not redefine: <list>
Tables you own: <list>.  Tables you reference only: <list>.
Migration timestamp slot: <YYYY_MM_DD_HHMMSS> to <...>

## Design system (if any UI)
Load the makam-design-system skill. Tokens only; all ten §6 states; StatusIntent
for every status; never a hardcoded value or a Tailwind arbitrary value.

## Constraints
- Additive where possible. Do not restructure files you do not own.
- Do NOT run git commit/push. The orchestrator integrates.
- Do not generate an APP_KEY, a credential, or a secret of any kind.
- Do not touch live infrastructure.
- AGENTS.md: never report PASS for a check you did not execute. Use BLOCKED or
  NOT TESTED. If a gate fails for a reason you believe is wrong, report it —
  do NOT weaken the check to make it green.

## Verify before finishing (run these, report the raw output)
<explicit commands>
bash ci/verify-docs.sh

## Report back
What changed, the verification output, what you did NOT do and why, and any
finding you surfaced but did not fix.
```

Name the relevant skills in the brief. An agent that has to rediscover the conventions will invent its own.

**The last two sections carry the value.** Reporting an unfixed finding and refusing to weaken a check are how the two most useful outputs of that 25 Jul batch happened — one agent found a false positive in a gate and reported it instead of deleting the legend that tripped it; another found a numerical contradiction between two planning documents and reported it instead of picking a number.

## Gate before, gate after

`ci/verify-docs.sh` must pass **before** the batch launches, so any failure afterwards is attributable, and **after** it lands. Once code is involved, CI is the real gate — a batch is not done until its own CI run is green.

## Reviewing what comes back

Never commit on an agent's self-report. Read the diff against the spec and against the real installed framework. Two independent lenses for anything writing schema or new domain logic — spec compliance, and design system — one for a batch that copies an existing verified pattern.

## Integration is the orchestrator's job

Agents flag integration seams; they do not wire them. That is finding **N-9**: three concurrent agents each correctly stayed inside their boundary and left three cross-cutting seams explicitly flagged rather than silently connected. Collect the flags, wire them yourself, then gate.
