---
name: kiro-tasks
description: Write or refine a Kiro tasks.md — discrete trackable implementation tasks with _Requirements N_ traceability annotations, dependency awareness, optional-vs-required marking, and this repo's mandatory Design system section. Use when authoring the task phase of a spec, adding tasks after requirements change, or when asked about "tasks.md", task breakdown, or requirement traceability.
---

# tasks.md — the implementation plan

Source: [kiro.dev/docs/specs/feature-specs/requirements-first](https://kiro.dev/docs/specs/feature-specs/requirements-first/).

Kiro says `tasks.md` holds discrete, trackable tasks with clear descriptions and expected
outcomes, dependency mapping, and optional-vs-required designation.

`AGENTS.md` adds the boundary: **`tasks.md` is planning only; the issue tracker owns progress.**
Checkboxes here are a planning aid, not the system of record.

## House format

```markdown
# Tasks — <Feature Name>

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md).

- [x] Create FAQ schema and seed six categories. _Requirements: 2_ — done 26 Jul 2026 (Batch 4.1), CI green
- [ ] Add authorization, publishing, search, and responsive tests. _Requirements: 6, 9_ —
      authorization/publishing/search tests done and passing in CI; **responsive verification is
      NOT done** (no browser on this host)
```

Rules:

- **One task, one outcome.** If the description needs "and then", split it.
- **Every task carries `_Requirements: N_`.** Comma-separate multiple criteria.
- **Every criterion is covered by at least one task.** A criterion with no task is a planning
  defect — finding **N-8** catalogues the specs where this is already true and unfixed.
- **A task with no criterion is scope creep.** Either add the criterion or drop the task.
- **Mark optional tasks** explicitly (`(optional)`), per Kiro's optional-vs-required designation.
- **Mark human gates** `⚠️ HUMAN` — security, authorization, financial, privacy, destructive
  migration, DNS, firewall, production. An agent may prepare these; it may not execute them.

## Completion annotations — the honesty rule

`AGENTS.md`: *"Never report `PASS` for a check that was not executed; use `BLOCKED` or `NOT
TESTED` explicitly."* That governs these checkboxes.

- `[x]` means done **and** verified. Say how: `done 26 Jul 2026 (Batch 4.2), CI green`.
- Partially done stays `[ ]` with the split stated plainly — see the second example above. A box
  ticked on unverified work is the exact failure mode finding **H-3** exists for (32 traceability
  items once claimed `Covered` with zero tests in the repository).
- This host cannot run `composer install`, `npm run build`, PHPUnit, Pint, or PHPStan (`vendor/`
  is empty by policy — `CLAUDE.md`, `ci-cd-and-release.md` §10). **CI is the oracle.** Do not write
  "tests pass" from a local run that never happened.

## Dependency order

Kiro builds a dependency graph and runs independent tasks concurrently in waves. Write tasks so
that graph is derivable: schema before the code that reads it, domain Action before the UI that
calls it, seeds before the tests that assert on them. State a dependency in the description when
it is not obvious.

See `kiro-execute-tasks` for running them.

## The `## Design system` section — mandatory in this repo

Every `tasks.md` here ends with it, and `ci/verify-docs.sh` GATE 6 fails without the
`design-system.md` reference. It has four parts:

1. **Governance line** — points at `docs/design/design-system.md` and `resources/css/tokens.css`,
   with the rule: never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values.
2. **Primitives and tokens table** — each UI element → its `<x-mk.*>` primitive (with the
   design-system § number) → the exact tokens. Only reference primitives that exist:
   `button · field · card · modal · table · badge · alert · stepper · header · logo · spinner ·
   gate-closed-banner · gate-closed-page`.
3. **Required UI states table** — all ten of design-system §6: loading · empty · validation error ·
   authorization failure · provider unavailable · duplicate/retry-safe · pending · success · gated
   fallback banner · support escape hatch. Plus responsive. `AGENTS.md`: *"Every transactional
   screen has loading, empty, error, pending, success, and support states."*
4. **Design-system task list** — checkboxes for token compliance, the ten states, accessibility
   (16 px input floor, focus ring, 44 px touch targets, `lang="id"`), and Filament inheritance.

`public-faq/tasks.md` is the reference implementation of this section. Copy its shape.

## Procedure

1. Walk `requirements.md` criterion by criterion; list what must be built for each.
2. Group into tasks that are independently completable and independently verifiable.
3. Annotate `_Requirements: N_`; then check both directions for orphans.
4. Order by dependency; mark optional and `⚠️ HUMAN` tasks.
5. Write the `## Design system` section against the real primitives and tokens.
6. Run `bash ci/verify-docs.sh`.
