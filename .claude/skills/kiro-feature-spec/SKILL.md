---
name: kiro-feature-spec
description: Run the full Kiro Feature Spec three-phase workflow (requirements → design → tasks) with approval gates, in either Requirements-First or Design-First variant. Use when creating a new feature spec under .kiro/specs/, converting an idea or an existing architecture doc into a spec triad, or when asked for "requirements-first", "design-first", or "a full spec" for a feature.
---

# Feature Specs — the three-phase workflow

Source: [kiro.dev/docs/specs/feature-specs](https://kiro.dev/docs/specs/feature-specs/).

A Feature Spec produces three files in `.kiro/specs/<kebab-case-feature>/`:

| File | Holds |
|---|---|
| `requirements.md` | user stories + acceptance criteria in EARS notation |
| `design.md` | architecture, sequence diagrams, data models, error handling, test strategy |
| `tasks.md` | discrete trackable tasks, each traced back to requirement numbers |

Use a Feature Spec for complex, multi-task, collaborative work. Not for bug fixes
(`kiro-bugfix-spec`) and not for exploratory coding.

## Choose the variant — this cannot be changed later

Kiro is explicit: **you cannot switch workflows after creating a spec.** If the approach must
change, start a new spec and carry content across.

**Requirements-First** — `Requirements → Design → Tasks`
- The behaviour is known; the architecture may flex to meet it.
- Customer/stakeholder-feedback driven.
- This is the default here: `docs/product/mvp-scope.md` fixes behaviour, not architecture.

**Design-First** — `Design → Requirements → Tasks`
- Architecture, pseudocode, or a diagram already exists.
- Non-functional constraints (performance, compliance) are strict.
- Technical feasibility must be validated before scope is committed.
- In this repo that means: an approved ADR or a `docs/architecture/*` document already fixes the
  shape, and the spec must be derived from it rather than the reverse.

Running several specs in one project is encouraged — e.g. prove feasibility with a Design-First
spec, then open a Requirements-First spec for the full feature.

## Phase 1 — Requirements

Load `kiro-requirements`. Produce `requirements.md`: user stories, EARS acceptance criteria,
functional requirements, edge cases and error handling.

**⛔ APPROVAL GATE.** Kiro requires the user to confirm the requirements meet their needs before
design begins. In Claude Code there is no button — so **stop, show the criteria, and ask.** Do not
roll straight into design. Being asked to "write a spec" is not approval of criteria that do not
exist yet.

## Phase 2 — Design

Load `kiro-design`. Produce `design.md` from the approved requirements.

**⛔ APPROVAL GATE.** Kiro requires confirmation that the design is feasible before tasks are
generated. Stop and ask again. This is also where `AGENTS.md`'s human-gate rule bites hardest: if
the design touches auth, money, privacy, migrations, DNS, or production, say so explicitly at this
gate.

## Phase 3 — Tasks

Load `kiro-tasks`. Produce `tasks.md`: discrete, trackable, dependency-aware, each annotated
`_Requirements: N_`. Kiro documents no formal approval gate here — review precedes execution but
progression is allowed. In this repo, still confirm scope before implementing, because the tasks
are what an agent will act on.

## Iterating an existing spec

Kiro's IDE offers **Refine** (regenerate `design.md` after requirement edits) and **Sync Files**
(map new requirements into `tasks.md`, detect already-finished work). Those are buttons this
environment does not have. The manual equivalent:

1. Edit `requirements.md` — renumbering is forbidden. Existing cross-references (`AC4`, `AC6`,
   `_Requirements: 3_`) point at numbers; append new criteria, never reorder old ones. Every spec
   here carries a note saying exactly that.
2. Re-read `design.md` against the changed criteria and update only the affected sections.
3. Add or amend tasks, keeping `_Requirements: N_` accurate in both directions.
4. Re-run `bash ci/verify-docs.sh`.

## Importing external content

- **Existing architecture** (diagram or prose) → Design-First. Paste or describe it, formalise
  into `design.md`, then derive requirements.
- **Existing requirements** → copy them into the repo and convert to EARS, preserving the original
  numbering wherever it already exists elsewhere.

## Definition of done for a new spec

- [ ] All three files exist (GATE 5) and `design-system.md` is referenced (GATE 6)
- [ ] Every acceptance criterion is numbered and in EARS form
- [ ] Every task carries `_Requirements: N_`, and every criterion is covered by ≥1 task
- [ ] Gated capabilities have an explicit fallback criterion, not a deletion
- [ ] Human-gated tasks marked `⚠️ HUMAN`
- [ ] `## Design system` section present in `tasks.md`
- [ ] `bash ci/verify-docs.sh` passes
