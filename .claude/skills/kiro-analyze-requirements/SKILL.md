---
name: kiro-analyze-requirements
description: Stress-test a set of acceptance criteria before design begins — find contradictions, vague language, unsatisfiable constraint combinations, undefined concepts and implicit assumptions, and missing edge cases. Use after requirements are written (especially Quick Spec output), for complex interdependent requirements, or for compliance-sensitive features where ambiguity has real cost.
---

# Analyze Requirements

Source: [kiro.dev/docs/specs/analyze-requirements](https://kiro.dev/docs/specs/analyze-requirements/).

> **Honesty note.** kiro.dev lists Analyze Requirements as **IDE-only**. There is no button here.
> This skill is the manual equivalent — the same five checks, run by reading. Never claim the Kiro
> feature was run.

The point is that it examines **cross-requirement interactions**, not each requirement in
isolation. Reading criteria one at a time will not find most of what follows.

## The five issue categories

1. **Logical inconsistency** — two criteria contradict each other.
2. **Vague language** — wording that permits materially different implementations.
3. **Conflicting constraints** — individually satisfiable, not simultaneously.
4. **Undefined concepts / implicit assumptions** — a term or a precondition nothing defines.
5. **Missing edge cases and failure scenarios** — the unhappy path is absent.

## How to run it

Read all criteria at once, then work the matrix — every criterion against every other. For each
issue found, output exactly three things:

```
Affected: AC4, AC9
Issue:    <plain-language explanation, no jargon>
Options:  a) …  b) …  c) leave as is, because …
```

Kiro streams these as clarifying questions the user can accept, adjust, or dismiss. Do the same:
**present, do not silently rewrite.** A criterion is a product statement; changing it is the
user's call.

## Repo-specific checks worth adding

- **Gate coverage.** Every capability behind one of the 17 gates in
  `docs/governance/assumptions-and-gates.md` §2 needs an explicit fallback criterion. A missing
  fallback is a category-5 gap, and `AGENTS.md` forbids resolving it by deletion.
- **Catalogue restatement.** A criterion that lists catalogue values instead of referencing
  `docs/product/*-catalog.md` violates `AGENTS.md` §Documentation and GATE 8.
- **Unverifiable claims.** A price, SLA, hotline, or operating hour that no document in this repo
  defines. This has bitten before — no real hotline number exists anywhere in the repository, so
  any criterion promising one is unbuildable as written.
- **Cross-document conflict.** Two canonical documents defining incompatible vocabulary for the
  same thing. Finding **N-7** is live: `docs/domain/order-lifecycle.md` §5 and
  `.kiro/specs/pre-need-contracting/design.md` define different Pre-Need status enums. Surface
  conflicts like this; do not pick a winner — that is a product decision.
- **Testability.** Ask of each criterion: what test fails if this is violated? If none, say so.
- **Orphans.** After analysis, check that every criterion will be reachable by a task, and that
  no criterion is a restatement of another under a different number.

## When to skip

Straightforward, well-understood specs. Kiro says so, and the analysis genuinely takes real
effort because of the pairwise reading.

## When it earns its cost

Complex interdependent requirements · financial, healthcare, or compliance contexts · anything
touching money, authorization, or privacy · **any Quick Spec output**, which by design had no
manual review on the way through.

## Re-running

After edits, re-check the affected criteria **and their interactions** — not just the lines that
changed. A fix to one criterion routinely creates a conflict with a distant one.
