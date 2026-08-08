---
name: kiro-plan
description: Run Kiro's Plan mode — structured requirements questioning plus read-only codebase exploration, producing a step-by-step implementation plan without writing spec files. Use for complex tasks or multi-file changes where "understand this first, then implement" applies and formal spec documents are not wanted; faster and more conversational than a Feature Spec.
---

# Plan mode — think before acting, without formal artefacts

Source: [kiro.dev/docs/specs/plan](https://kiro.dev/docs/specs/plan/).

Plan mode is conversational. It produces **no** `requirements.md` or `design.md` — just a plan.
kiro.dev frames it as "complex tasks where you want to think through the approach before writing
code" and "multi-file changes where understanding existing architecture matters" — faster and more
conversational than a Feature Spec, with no formal documents required. (An earlier version of this
skill stated a specific "15–60 minute" duration for Plan mode; that number does not appear on the
real kiro.dev page and has been removed — it was this repo's own unsourced guess, not a Kiro
claim.) For anything that deserves permanent documentation, use `kiro-feature-spec` instead.
Available in IDE and CLI; not in Web or Mobile.

## The four phases

**1 · Requirements gathering.** Structured questions that sharpen the initial idea. Ask them as
discrete questions the user can answer one by one or in prose — not one wall of text.

**2 · Research and analysis — read-only.** Read files, search, analyse existing conventions,
research the technology. **The planner does not modify the project.** Honour that: no edits, no
writes, no migrations, no commits during planning. Claude Code's own plan mode enforces this;
if you are not in it, enforce it yourself.

In this repo, research means reading the real thing rather than assuming:
- the authority documents (`AGENTS.md`, `docs/product/mvp-scope.md`, the relevant `.kiro/spec`);
- the actual installed framework source when behaviour is in question — `vendor/` is empty here by
  policy, so use the sibling project `/home/ubuntu/platform-galang-dana-app`, which pins the same
  versions;
- `docs/planning/sprint-plan.md` findings N-1…N-15 — several traps are already documented.

**3 · Implementation plan.** A step-by-step plan with clear objectives, broken into concrete tasks
with measurable outcomes. Name the files each step touches. Name what is out of scope.

**4 · Handoff to execution.** On approval, the full plan context carries into implementation.

## What a good plan states here

- Which files change, and which are deliberately untouched.
- Where a human gate falls (`⚠️ HUMAN`) — and that work stops there.
- How each step is verified. CI is the oracle: this host cannot run `composer install`,
  `npm run build`, PHPUnit, Pint, or PHPStan.
- What will remain `NOT TESTED` when the plan finishes, stated up front rather than discovered
  at the end.

## Plan mode vs the alternatives

| Need | Use |
|---|---|
| Permanent requirements/design record | `kiro-feature-spec` |
| Fast artefacts, one pass | `kiro-quick-spec` |
| Understand-then-implement, no artefacts | **Plan mode** |
| A defect with regression risk | `kiro-bugfix-spec` |
