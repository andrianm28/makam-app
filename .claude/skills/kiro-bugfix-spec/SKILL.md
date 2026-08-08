---
name: kiro-bugfix-spec
description: Run the Kiro Bugfix Spec workflow — bugfix.md capturing current/expected/unchanged behaviour, then root-cause design and property-based regression tasks. Use when fixing a defect in a critical code path, when a previous fix caused a regression, when the root cause is not obvious, or when a documented fix record is needed for compliance.
---

# Bugfix Specs

Source: [kiro.dev/docs/specs/bugfix-specs](https://kiro.dev/docs/specs/bugfix-specs/).

Same three phases as a Feature Spec, specialised for defects. Its value is the third behaviour
class: what must **not** change.

## When to use one — and when not to

Use a Bugfix Spec when:
- the defect sits in a critical code path;
- an earlier fix attempt caused a regression;
- the root cause is not immediately apparent;
- a documented record is needed for compliance.

A typo or a one-line correction does not need a spec. Fix it and write the regression test —
`AGENTS.md` requires a regression test for **every** bug fix regardless of whether a spec exists.

## Phase 1 — `bugfix.md`

Three behaviour classes, all in EARS:

```markdown
## Current behaviour (defect)
WHEN <condition> THEN the system <incorrect behaviour>

## Expected behaviour (correct)
WHEN <condition> THEN the system SHALL <correct behaviour>

## Unchanged behaviour (regression prevention)
WHEN <condition> THEN the system SHALL CONTINUE TO <existing behaviour>
```

A good bug description carries exact reproduction conditions, the current defective behaviour, the
expected behaviour, and the constraints on what must stay untouched.

**The third section is the one that earns the spec.** Be specific about what must keep working —
"the rest of the app" is not a constraint a test can check.

## Phase 2 — `design.md`

- **Root cause analysis** — the actual mechanism, traced and reproduced, not a plausible theory.
- **Proposed fix strategy.**
- **Properties to validate** — bug reproducible before, resolved after, no side effects.

This repo has a hard-won precedent. Finding **N-14**: a Blade failure was first blamed on
Livewire's rendering context and chased through Livewire's source. That theory was wrong. The real
cause was `BladeCompiler::compileString()` calling `storeUncompiledBlocks()` before
`compileComments()`, so prose containing the literal `@php` inside a `{{-- --}}` comment swallowed
the real `@props()` block. It was only settled by running the affected files through the real
installed compiler. **Reproduce the defect deterministically before proposing a fix.** A root
cause you have not reproduced is a hypothesis.

## Phase 3 — `tasks.md`

Tasks include property-based tests proving three things:

1. the bug **is** reproducible in the current code;
2. the bug **is** resolved in the fixed code;
3. **no** unintended side effect occurred — the unchanged-behaviour list still holds.

Write the failing test first. A fix whose test passes before the fix proves nothing.

See `kiro-correctness` for turning the unchanged-behaviour statements into properties.

## Recording the finding

Non-obvious defects in this repo get a numbered finding in `docs/planning/sprint-plan.md`
(Appendix A / "New findings surfaced while planning", currently N-1…N-15). Record: mechanism,
evidence that it was reproduced, fix applied, and what still needs to happen. State it plainly if
the fix is partial.
