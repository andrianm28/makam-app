---
name: kiro-requirements
description: Write or refine a Kiro requirements.md — user stories plus numbered acceptance criteria in EARS notation (WHEN/WHILE/IF/WHERE ... THE SYSTEM SHALL ...). Use when authoring acceptance criteria, converting prose or a stakeholder document into testable requirements, adding a criterion to an existing spec, or when asked about EARS syntax, "acceptance criteria", or "requirements.md".
---

# requirements.md — EARS acceptance criteria

Source: [kiro.dev/docs/specs/feature-specs](https://kiro.dev/docs/specs/feature-specs/) and
`requirements-first`. EARS = Easy Approach to Requirements Syntax.

## What the file contains

- **Authority line** — where this spec's power comes from. Every spec here opens with one, e.g.
  `**Authority:** Stakeholder Workflow MVP — FAQ.` Trace it to `docs/product/mvp-scope.md`, an RKS
  item (K23–K35), or an approved ADR. A spec with no authority is scope creep.
- **User stories** with acceptance criteria.
- **Numbered acceptance criteria** in EARS.
- **Edge cases and error/failure scenarios** — not optional; Kiro's Analyze pass looks for these.
- **Negative criteria** — what must never happen. This repo uses a `Negative criteria` section for
  things like *"No cross-scope read reachable by changing an identifier in a URL."*

## EARS patterns

**kiro.dev itself shows only one pattern, the event-driven form** — this is the only one either of
its feature-spec pages demonstrates:

```
WHEN [condition/event] THE SYSTEM SHALL [expected behavior]
```

The other six rows below are **not from kiro.dev** — they are the broader EARS standard (a
requirements-syntax methodology that predates and is external to Kiro) that this repo applies in
full, consistently, across all 28 specs. Kiro's docs neither show nor forbid these forms; treat
the table as this repo's own convention layered on top of Kiro's one demonstrated pattern, not as
something kiro.dev itself specifies:

| Pattern | Form | Use for |
|---|---|---|
| Ubiquitous | `THE SYSTEM SHALL <behaviour>` | always-true behaviour |
| Event-driven | `WHEN <trigger> THE SYSTEM SHALL <behaviour>` | a discrete event |
| State-driven | `WHILE <state> THE SYSTEM SHALL <behaviour>` | continuous condition — **the gate pattern** |
| Unwanted | `IF <unwanted condition> THEN THE SYSTEM SHALL <response>` | errors, abuse, failure |
| Optional | `WHERE <feature is present> THE SYSTEM SHALL <behaviour>` | capability-dependent behaviour |
| Prohibition | `THE SYSTEM SHALL NOT <behaviour>` | hard negative invariants |
| Complex | combine, e.g. `WHILE … WHEN … THE SYSTEM SHALL …` | conditional events |

Real examples from this repo:

```
2. THE SYSTEM SHALL present the six FAQ categories defined in `faq-catalog.md`.
6. THE SYSTEM SHALL NOT display an unpublished article in any public view or public search result.
9. WHILE the online-payment gate is open THE SYSTEM SHALL support online payment in Step 8;
   WHILE the gate is closed THE SYSTEM SHALL provide an explicit manual fallback in Step 8.
```

## Rules for this repository

**Numbering is permanent.** `tasks.md` annotations (`_Requirements: 3_`) and cross-references
(`AC6`) point at numbers. Append new criteria at the end. Never reorder, never renumber, never
delete — supersede in place and say so.

**A closed gate gets its own criterion, never a deletion.** `AGENTS.md`: *"Never remove a
stakeholder MVP item merely because an external gate is closed. Implement the documented
fallback."* Write both halves as one `WHILE … ; WHILE … ` criterion, like AC9 above. The 17 gates
are in `docs/governance/assumptions-and-gates.md` §2; the fallback UX table is
`docs/product/mvp-scope.md` §7.

**Reference catalogues, never restate them.** Write *"the six FAQ categories defined in
`faq-catalog.md`"*, not the six names. GATE 8 of `ci/verify-docs.sh` enforces this for the
marketplace catalogue and `AGENTS.md` §Documentation for all of them.

**No unverifiable claim.** A criterion asserting a price, an SLA, an operating hour, or a phone
number that nothing in the repo defines is a fabrication. Either point at the document that owns
it or write the criterion so the honest fallback is the requirement.

## Quality bar — a criterion must be

- **Testable** — a test can pass or fail it. "SHALL be user-friendly" is not a requirement.
- **Atomic** — one behaviour. Two behaviours means two numbers.
- **Unambiguous** — no "appropriate", "as needed", "if possible", "etc.".
- **Complete** — the failure path is stated, not implied.
- **Traceable** — its authority is identifiable.

## Procedure

1. Read the authority documents first (`mvp-scope.md`, the relevant `docs/product/*`, the ADRs).
2. Read the neighbouring spec's `requirements.md` for house voice — `public-faq` is the reference.
3. Draft user stories, then convert each into numbered EARS criteria.
4. Add edge cases, failure scenarios, and negative criteria.
5. For every capability behind one of the 17 gates, add the fallback criterion.
6. Stop at the approval gate and show the criteria before writing `design.md`.
7. Optionally run `kiro-analyze-requirements` before design — recommended for anything touching
   money, privacy, or authorization.
