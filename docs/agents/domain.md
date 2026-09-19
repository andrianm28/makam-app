# Domain Docs

How the engineering skills should consume this repository's domain
documentation.

## Before exploring, read these

This repo has **no `CONTEXT.md`, and does not need one** — the glossary slot is
already filled:

- **[`docs/domain/domain-model.md`](../domain/domain-model.md)** — the
  glossary. Use its vocabulary.
- **[`docs/domain/`](../domain/)** — twelve further domain documents (order
  lifecycle, financial ledger, cemetery capability, plot inventory, and so on).
  Read the ones touching your area.
- **[`docs/adr/`](../adr/)** — 41 ADRs. Read those covering the area you are
  about to change.
- **[`.kiro/steering/`](../../.kiro/steering/)** — six steering files (product,
  tech, design, governance, planning, project).

`AGENTS.md` §Source precedence governs when these disagree:
RKS K23–K35 → `docs/product/mvp-scope.md` → approved ADR/specs → approved
benchmark extensions.

## Use the glossary's vocabulary

When your output names a domain concept, use the term as `domain-model.md`
defines it. If the concept is not there, either you are inventing language the
project does not use, or there is a real gap worth naming.

## Flag ADR conflicts

If your output contradicts an ADR, surface it rather than silently overriding:

> _Contradicts ADR-0007 (fuzzy grave search), but worth reopening because…_

This repo's own habit is stronger than flagging: a superseded ADR keeps its
original text and gains a correction note (see ADR-0034 and ADR-0035). An ADR
is a record, not a living document.
