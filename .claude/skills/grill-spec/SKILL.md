---
name: grill-spec
description: Stress-test a spec artifact under .kiro/specs/ — most often a thin or unclear design.md, but also usable on requirements.md before design starts or tasks.md before implementation — using a frontier/decision-tree interrogation that surfaces every silently-assumed decision before the next phase begins. Use before writing tasks.md, when a design.md reads as a stub, or whenever asked to "grill" a plan, design, or spec.
---

# grill-spec — decision-tree interrogation for a Kiro spec artifact

Source: adapted from mattpocock/skills' `grilling` skill
(`skills/productivity/grilling/SKILL.md`), installed in this environment via the
official Claude Code plugin `mattpocock-skills@claude-plugins-official`
(`claude-plugins-official` marketplace — `anthropics/claude-plugins-official`).
The mechanism below is that skill's, reproduced faithfully; everything under
"Applying it to a spec in this repo" is this project's own adaptation, not
part of the original.

## The mechanism (unchanged from `grilling`)

Map the artifact under review as a **design tree**: every decision branches
into the decisions that hang off it. Work the tree in **rounds**.

The **frontier** is every decision whose prerequisites are already settled —
the questions you can ask *now* without guessing at answers you haven't heard
yet. Ask the whole frontier in one round: number each question and give your
recommended answer. Then wait for the user's answers before the next round.

```
❓ **Q1** - **<question title>**: <question body — may be multiple
paragraphs, including multiple choices>

➡️ <your recommended answer>
```

Each round the user answers reshapes the tree — settled decisions push the
frontier outward and unblock questions that depended on them. Recompute the
frontier and ask the next round. A question whose answer depends on another
question still open in this round belongs to a *later* round, not this one.

Finding *facts* is your job, never the user's. When a frontier question needs
a fact from the environment, dispatch a sub-agent to find it — don't ask the
user for anything you could look up yourself. Don't block on it: a running
exploration is an unsettled prerequisite, so only the questions downstream of
it wait; ask the rest of the frontier now. The *decisions* are the user's —
put each to them and wait.

The session is done when the frontier is empty: every branch of the tree
visited, nothing left silently assumed. Do not edit the spec file until the
user confirms you've reached a shared understanding.

## Applying it to a spec in this repo

**Primary target: `design.md`.** This is the gap grilling closes. An audit
of this repo's 27 Feature Specs (8 Aug 2026) found 3 with a `design.md`
that was a genuine stub — 5 lines, an entity list plus a sentence, none of
Kiro's own named design sections present: architecture, sequence diagrams,
data models and interfaces, technology stack, error handling, testing
strategy (per kiro.dev/docs/specs/feature-specs/requirements-first — see
`memorial-and-qr`, `package-and-service-bundles`, `visitation-booking`,
fixed the same day this finding surfaced). Nothing in this repo caught a
thin `design.md` before a `tasks.md` got written against it. Grilling a
`design.md` draft before `tasks.md` exists is exactly that check.

Also usable on `requirements.md` (before design starts — a deeper,
question-driven pass than a plain read-through) or on `tasks.md` (before
implementation — to surface an assumed sequencing or scope boundary no one
stated out loud).

**Seed the tree from the artifact itself, not from scratch.** Read the
draft `design.md`/`requirements.md`/`tasks.md` in full first. Every
underspecified section, every "TBD", every table name invented without a
citation, every gate mentioned without its closed-state branch shown — each
is a root of the design tree. Don't invent branches the draft gives no hint
of; the draft is the evidence for what's actually unsettled.

**Sub-agent fact-finding must resolve against this repo's real authorities**,
not general knowledge:

- `docs/architecture/overview.md` §5 for module/table ownership
- `docs/contracts/event-catalog.md` for whether a needed event is
  catalogued (never invent a permanent event name — finding N-12)
- `docs/governance/assumptions-and-gates.md` for which `G-*` gate applies
  and its documented fallback
- the actual installed framework source in the sibling project
  (`/home/ubuntu/platform-galang-dana-app`) when a behavioural claim needs
  checking, not this host's empty `vendor/`
- sibling specs' own `design.md` files for an established pattern
  (`cemetery-directory-and-availability`'s is the house-style length/tone
  reference — 20-40 lines, not a textbook-length document)

A frontier question whose fact-finding turns up "not decided anywhere" is
not a dead end — it's a finding. Say so in the artifact rather than
guessing, the same way this repo's specs already surface an unresolved gate
or an unresolved open decision instead of picking an answer silently.

**When the frontier is empty:** edit the spec file directly with what was
settled. For `design.md`, that means writing (or finally filling) the six
sections above — don't leave the interrogation's answers only in chat
history. For anything the round surfaced as genuinely undecided (not just
unasked), record it as an explicit "not covered, deliberately" item with
the reason — several `design.md` files in this repo already carry such a
section; match that pattern rather than silently dropping a branch that was
opened.

## What this is not

- Not an implementation check. grill-spec never runs code and never asserts
  a requirement is met — it only surfaces decisions before they're made
  silently. Verifying an implementation against its requirements is a
  separate concern this skill doesn't cover.
- Not a substitute for human approval on human-gated content. A frontier
  question that resolves into an auth/money/privacy/destructive-migration
  decision still needs recorded human sign-off per `AGENTS.md`
  ("AI agents may prepare... but human review is mandatory before security,
  authorization, financial, privacy, destructive migration, DNS, firewall,
  or production-affecting changes") — grilling surfaces the decision; it
  does not authorize an agent to make it.
