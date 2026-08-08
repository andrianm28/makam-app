---
name: kiro-quick-spec
description: Run Kiro's Quick Spec — front-load clarifying questions, then produce requirements.md, design.md, and tasks.md in one pass without per-phase approval gates. Use for well-understood features or rapid prototyping where the review cycles of a standard Feature Spec are not worth their cost.
---

# Quick Spec — one pass, no gates

Source: [kiro.dev/docs/specs/quick-spec](https://kiro.dev/docs/specs/quick-spec/).

Quick Spec compresses requirements, design, and task planning into a single continuous pass. It
produces the **identical three artefacts** in `.kiro/specs/<feature>/` — the only difference from a
standard Feature Spec is *where the review happens*.

> "Instead of approving each phase before the next begins, you answer clarifying questions up
> front and land directly on an actionable task list."

## The trade you are making

| | Standard Feature Spec | Quick Spec |
|---|---|---|
| Approval | gate after requirements, gate after design | none between phases |
| Your input | spread across the workflow | **all up front** |
| Suits | unfamiliar domain, uncertain requirements, compliance work | well-understood feature, prototyping |
| Artefacts | requirements.md · design.md · tasks.md | identical |

Quick Spec is not Vibe mode: Vibe is conversational with no saved artefacts. Quick Spec writes
real, persistent files.

## Front-loading is the whole technique

Because there are no gates, the clarifying questions must be asked **before** anything is written,
and the answers must be enough to finish without guessing. Ask about:

- exact scope boundary — what is explicitly out;
- which authority document governs (`mvp-scope.md`, RKS item, ADR);
- which of the 17 gates the feature touches, and the required fallback;
- which existing tables/modules it consumes vs. owns;
- what data it displays, and whether that data actually exists in this repo yet;
- what "done" means, and what will be left `NOT TESTED`.

If an answer is missing and the guess would change the artefacts materially, **stop and ask** —
that is one question now versus a wrong spec later.

## When NOT to use Quick Spec here

Fall back to the standard workflow (`kiro-feature-spec`) when the feature touches:

- authentication, authorization, or scope enforcement;
- money — payment, ledger, payout, refund, invoice;
- privacy or deceased-person data, uploads, or document retention;
- any capability behind a gate whose fallback is not already documented;
- a destructive migration.

These are `AGENTS.md` human-gate territory. Skipping review gates on them is exactly the wrong
trade — consistent with kiro.dev's own guidance that "for features where requirements quality is
critical - compliance-sensitive domains, high-stakes systems, unfamiliar territory - a standard
Feature Spec with explicit review gates is usually the better fit." (kiro.dev does not itemize
auth/money/privacy by name the way the list above does; that specific list is this repo's own
application of that general principle via `AGENTS.md`'s human-gate rules.)

## Procedure

1. Ask the front-loading questions in one message. Wait.
2. Write `requirements.md` (EARS, numbered) → `design.md` → `tasks.md` in one pass.
3. Run `bash ci/verify-docs.sh`.
4. Present all three together for review, and name explicitly what you assumed.
5. Consider following with `kiro-analyze-requirements` — Kiro recommends it precisely for
   Quick Spec output, which had no manual review on the way through.
