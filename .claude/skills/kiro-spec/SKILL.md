---
name: kiro-spec
description: Entry point for all Kiro spec-driven work in this repository (.kiro/specs/**). Use when asked to create, refine, analyse, or implement a spec; when starting a feature or bug fix that needs requirements/design/tasks; or whenever someone says "spec", "Kiro", "EARS", "acceptance criteria", "requirements.md", "design.md", or "tasks.md". Routes to the right workflow and states the repo rules that override the vendor defaults.
---

# Kiro specs — router and house rules

This repository already holds **28 Kiro specs** under `.kiro/specs/<feature-name>/`: 27 Feature
Specs, each a triad of `requirements.md` · `design.md` · `tasks.md`, plus 1 Bugfix Spec
(`help-centre-missing-route`), whose first-phase file is `bugfix.md` instead — see
`kiro-bugfix-spec`. `ci/verify-docs.sh` GATE 5 enforces that every spec has its correct triad
(accepting either first-phase file); GATE 6 enforces that each references
`docs/design/design-system.md`.

Source: [kiro.dev/docs/specs](https://kiro.dev/docs/specs/). Kiro is an IDE/CLI product; several of
its features are buttons, not files. Where that is true, this skill set gives the **manual
equivalent** and says so — never pretend a Kiro UI action ran.

## Pick the workflow

| Situation | Use | Skill |
|---|---|---|
| New feature, behaviour known, architecture flexible | Feature Spec, **Requirements-First** | `kiro-feature-spec` |
| New feature, architecture/constraints fixed first | Feature Spec, **Design-First** | `kiro-feature-spec` |
| Defect in a real code path | **Bugfix Spec** (`bugfix.md`) | `kiro-bugfix-spec` |
| Well-understood feature, want one pass, no gates | **Quick Spec** | `kiro-quick-spec` |
| Complex task, no formal docs wanted | **Plan mode** | `kiro-plan` |
| Implementing an existing spec's task list — waves of independent tasks run concurrently, dependent ones wait | **Parallel task execution** | `kiro-execute-tasks` |
| Writing/refining one artefact | — | `kiro-requirements` · `kiro-design` · `kiro-tasks` |

Typo fixes and one-line corrections do **not** need a spec. Say so and just fix them.

**Not alternative entry points — composed with the above, not chosen instead of it.** Analyze
Requirements (`kiro-analyze-requirements`) stress-tests a `requirements.md` that already exists,
most useful right after Quick Spec since that path has no requirements-review gate of its own.
Correctness (`kiro-correctness`) property-tests an implementation against its requirements once
built. Both slot into a Feature/Bugfix/Quick Spec already in progress; neither creates a spec on
its own.

**Availability** (per kiro.dev's own capability matrix): Feature Specs, Bugfix Specs, and Quick
Spec work in IDE, CLI, and Web. Parallel task execution works in IDE, CLI, and Web too. Analyze
Requirements and Correctness are **IDE-only** — there is no button here in this headless
environment, so their skills give the manual equivalent instead (see each skill's own honesty
note). None of the six is available on Mobile.

## House rules that override the vendor docs

These come from `AGENTS.md` and win wherever kiro.dev suggests otherwise.

1. **Source precedence** — RKS K23–K35 → `docs/product/mvp-scope.md` → approved ADR/specs →
   approved benchmark extensions. A spec may not contradict a higher source.
2. **A closed gate never deletes a requirement.** *"Never remove a stakeholder MVP item merely
   because an external gate is closed. Implement the documented fallback."* Write the fallback as
   its own `WHILE the gate is closed …` criterion — see `kiro-requirements`.
3. **Never duplicate canonical catalogue data.** Service, marketplace, and FAQ catalogues live in
   `docs/product/*-catalog.md`. Specs **reference** them; GATE 8 checks this.
4. **`tasks.md` is planning only; the issue tracker owns progress.** Checkboxes there are a
   planning aid, not a status system of record.
5. **Never report `PASS` for a check that was not executed** — use `BLOCKED` or `NOT TESTED`.
   This applies to every task annotation you write.
6. **Human gates are not delegable.** Security, authorization, financial, privacy, destructive
   migration, DNS, firewall, and production-affecting changes need recorded human approval.
   Mark them `⚠️ HUMAN` in `tasks.md` and stop.
7. **Every spec's `tasks.md` carries a `## Design system` section** — component primitives,
   tokens, and the ten required UI states from `docs/design/design-system.md` §6. GATE 6 depends
   on the reference; the section is this repo's convention on top of Kiro's format.

## A spec lives in the repo

Kiro's own best practice and this repo agree: specs are committed alongside code so requirement ↔
implementation stays connected. Never write a spec to a scratch directory.

```
.kiro/specs/
├── public-faq/            requirements.md · design.md · tasks.md
├── public-booking-wizard/
└── …25 more
```

## Before writing anything

Read the existing neighbours first. `public-faq` is the reference triad — it is the only complete
vertical slice in the repo and its `tasks.md` shows the house format in full. Match it rather than
inventing a new shape.
