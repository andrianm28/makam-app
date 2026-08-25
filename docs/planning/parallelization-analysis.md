# Parallelization Analysis — Sprint Plan + Kiro Spec Tasks

> **Superseded, 09 Aug 2026.** This document describes the pre-Superpowers batch model. New work and retrofits follow `AGENTS.md` §Development methodology. Retained for historical record of Sprint 1-4's actual execution.

**Version:** v0.1
**Date:** 25 Juli 2026
**Question answered:** can the work in [`sprint-plan.md`](sprint-plan.md) and the 378 Kiro spec tasks be compressed by running multiple concurrent Claude subagents, and if so, by how much?
**Companion to:** [`sprint-plan.md`](sprint-plan.md) §3.4 (foundation dependency order) and §11 (estimates)

---

## 0. Headline

**Agent parallelization buys about 1.6×, not 4×. And agents are not the binding constraint — the twelve human review gates are.**

```
378 Kiro implementation tasks across 27 specs
 51 sprint tasks (S1:10 S2:10 S3:12 S4:10 S5:9)
 12 human-gated tasks
96.5 pd total → ~20.5 weeks at one developer
```

Roughly **52 % of the work is genuinely parallelizable.** Amdahl's law caps the speedup at 2.08× even with unlimited agents, and coordination overhead flattens the curve above four concurrent tracks. With a part-time reviewer, twelve serial gates land at about the same twelve weeks that four-agent fan-out reaches — so beyond four agents, **review throughput is what determines the date, not agent count.**

---

## 1. Parallelizable fraction, per sprint

Derived from the dependency structure in `sprint-plan.md` §3.4 and from file-contention analysis.

| Sprint | Effort | Serial / gated | Parallel | % parallel |
|---|---:|---:|---:|---:|
| 1 — Foundation | 12 pd | 10 | 2 | **17 %** |
| 2 — Design + infra | 19 pd | 14.5 | 4.5 | **24 %** |
| 3 — Tier-0 foundations | 21.5 pd | 9.5 | 12 | **56 %** |
| 4 — MVP vertical slices | 27 pd | 5 | 22 | **81 %** |
| 5 — Test + gates | 17 pd | 7 | 10 | **59 %** |
| **Total** | **96.5** | **46** | **50.5** | **52 %** |

### Why Sprint 1 is nearly unparallelizable

Track A (the C-2 chain) is a serial dependency chain with a human gate in the middle. Track B is worse for a different reason: the scaffold is **single-writer by nature** — one `composer.json`, one `Dockerfile`, one lockfile resolution. Two agents cannot each write half of it. CI depends on the scaffold existing. That leaves only the documentation tasks (H-1, M-2, ADRs) as fan-out material — which is exactly the batch executed on 25 Jul 2026 (§6).

### Why Sprint 4 is the best target

Six independent vertical slices — FAQ, homepage, directory, wizard, renewal, marketplace — each with its own routes, components, and tests, per `information-architecture.md`. They share only the `<x-mk.*>` primitives (built in Sprint 2) and `StatusIntent` (S2-T3). The one serial head is S4-T1 seeds, which every slice consumes.

---

## 2. Amdahl arithmetic

At 52 % parallelizable:

| Concurrent tracks | Speedup | Calendar (from 20.5 weeks) |
|---:|---:|---|
| 1 | 1.00× | 20.5 weeks |
| 2 | 1.35× | ~15 weeks |
| 3 | 1.53× | ~13.5 weeks |
| **4** | **1.64×** | **~12.5 weeks** |
| 6 | 1.76× | ~11.6 weeks |
| ∞ | 2.08× | ~10 weeks (theoretical floor) |

Add 15–25 % coordination overhead above three or four concurrent agents on one codebase, and **six agents performs about the same as four**. Four is the sweet spot.

---

## 3. The real bottleneck: twelve human gates

Gates do not parallelize, and every one of them sits on the critical path.

| Sprint | Gates |
|---|---|
| 1 | G1 create databases · G2 secrets / APP_KEY |
| 2 | G3 DNS + firewall · G4 Redis auth · G5 backup + restore |
| **3** | **mandatory MFA · re-authentication** — two security gates at the heart of Tier 0 |
| 4 | G6 capacity decision · G7 product / legal decisions |
| 5 | G8 release sign-off · G9 rollback rehearsal · G10 brand + navigation |

If reviewer capacity is one gate per week, **twelve gates is a twelve-week floor** — indistinguishable from the four-agent parallel floor. Adding agents past four then buys nothing.

> This makes **OQ-9** (who reviews, and how available are they) the highest-value open question in the plan — above OQ-12 on scope versus dates. A named reviewer with two gates per week of capacity is worth more than two extra agents.

`AGENTS.md` is explicit that human review is mandatory before security, authorization, financial, privacy, destructive-migration, DNS, firewall, or production-affecting changes. Agent throughput is irrelevant to that class of work.

---

## 4. Never parallelize these

Single-writer artefacts. Two agents touching one of these produces either a merge conflict or — worse — two sources of truth.

| Artefact | Reason |
|---|---|
| `resources/css/tokens.css` | Single source of truth by design |
| `StatusIntent` | One resolver, mandated by design-system §3.7, consumed by 8 specs |
| Canonical catalogue enums | `AGENTS.md`: do not duplicate canonical catalogue data |
| Migrations | Shared schema plus timestamp ordering — concurrent writers collide |
| `composer.json` / lockfiles | One dependency resolution |
| `app.css`, Tailwind config, CI workflow | One configuration file each |
| **Anything touching live infrastructure** | Sprint 1 Track A, S2-T5/T6/T7 — and `makam.co.id` is live |
| **Anything behind a human gate** | Needs judgement, not throughput |

---

## 5. Excellent fan-out targets

| Work | Tracks | Note |
|---|---:|---|
| Sprint 4: six vertical slices | 6 | After the S4-T1 seeds head. Use worktree isolation — migrations collide |
| Nine `<x-mk.*>` primitives | 8 | Build `button` first as the convention reference, then fan out the rest |
| Sprint 3: three foundations | 3 | After `ActorContext`: audit ∥ feature-gate ∥ outbox |
| E2E suites per journey | 5 | E2E-HOME · FAQ · BOOK · MKT · REN |
| Accessibility audit per screen | N | 30 public screens, each independent |
| Authorization negative tests | N | Per surface, per scope |

---

## 6. Guardrail first — and why it is not optional

N agents without a mechanical check produce N conventions. The guardrail must exist **before** fan-out, not after.

[`ci/verify-docs.sh`](../../ci/verify-docs.sh) was written for this purpose on 25 Jul 2026 and runs with no application code present. Ten gates:

1. WCAG AA contrast (46 pairs, via `docs/design/verify-contrast.py`)
2. No hex literals outside `tokens.css`
3. No Tailwind arbitrary values for design decisions
4. Every relative markdown link resolves
5. Spec structural integrity (27 complete triads)
6. Every spec references `design-system.md`
7. No unevidenced `Covered` in traceability (finding H-3)
8. Marketplace spec references the canonical catalogue (finding D1)
9. Compose example does not strand PGDATA (finding H-1)
10. No `cat`-based bypass of secret read denials (finding M-2)

On its first run it correctly failed gates 7, 9, and 10 — the three open findings the fan-out batch then fixed. A guardrail that passes on a known-broken tree is not a guardrail.

Complementary controls already in the specs, which matter more once work is concurrent:

- **`Table ownership (normative)` statements** — added 25 Jul 2026 to resolve five duplicate-ownership conflicts. Under parallel work these prevent two agents writing a migration for the same table.
- **The wizard ↔ orchestration boundary** (`kiro-specs-analysis.md` §5.4) — eight overlapping acceptance criteria. The risk of double implementation rises sharply with concurrency.
- **Reference-implementation-first** — one agent establishes the pattern, the rest follow. Without it, eight primitives get eight styles.
- **`isolation: worktree`** for any batch that writes migrations.

---

## 7. Executed fan-out — 25 Jul 2026

Six concurrent `general-purpose` subagents on a **disjoint file set**, chosen because every item was independent, mechanically verifiable, and free of human gates.

| Agent | Finding | Owned files |
|---|---|---|
| 1 | H-1 | `docs/operations/examples/docker-compose.dev-stg.yml` |
| 2 | H-3 | `docs/domain/traceability-matrix.md` |
| 3 | L-4 | `technology-baseline.md`, `specs/README.md`, `.kiro/steering/project.md` |
| 4 | — | `docs/adr/0028-adopt-token-driven-design-system.md` (new) |
| 5 | — | `docs/adr/0029-platform-foundation-specs.md` (new) |
| 6 | M-2, L-5 | `.claude/settings.json`, `CLAUDE.md` (new) |

**Contention avoided by design:** `traceability-matrix.md` carries both an H-3 status change and an L-4 version header. Rather than let two agents share it, agent 2 was given the whole file including its version bump, and agent 3 was scoped to the other three version files. Disjointness was a deliberate assignment decision, not a happy accident — and it is the single most important thing to get right when fanning out.

**Deliberately excluded from fan-out**, per §4: the C-2 infrastructure chain (live systems, gated), the marketplace multi-vendor contradiction and the missing category codes (product decisions, not doc work), and CSP definition (OQ-11, a decision).

---

## 8. Retrospective — what this session should have parallelized

Honest assessment. The specification work completed earlier in this session was textbook fan-out material and was done serially.

| Work | Ideal tracks | Actually done |
|---|---:|---|
| `## Design system` section appended to 19 `tasks.md` | **19** — independent files, one template | serial |
| 8 foundation specs × 3 files = 24 files | **8** — independent specs | serial |
| 5 duplicate-table-ownership resolutions | 5 | serial |

Both conditions for safe fan-out were satisfied perfectly — zero file contention and mechanical verifiability (grep, link check, contrast gate) — so this would plausibly have completed in around a quarter of the wall-clock time.

By contrast the C-2 chain (secret ownership → init script → DDL → healthcheck) was correctly serial: each step depended on the previous, it touched a live database, and it carried a human gate.

---

## 9. Agent-days are not developer-days

The 96.5 pd in `sprint-plan.md` §11 is **developer** person-days. Agents change the rate of *production*, not the rate of *review*. Six agents produce six diffs; human review does not fan out.

Practical rule:

- **Fan out** work that is mechanically verifiable — components behind CI gates, tests, documentation, independent verticals.
- **Do not fan out** work that needs judgement — gates, product decisions, financial invariants, anything touching live systems.

---

## 10. Recommendations

1. **Four concurrent agents is the sweet spot.** Above that, coordination overhead consumes the gain.
2. **Target fan-out at Sprint 4** (81 % parallel), not Sprint 1 (17 %).
3. **Answer OQ-9 first.** If reviewer capacity is one gate per week, twelve gates already equals the four-agent floor and agent count stops mattering.
4. **Build the mechanical guardrail before any fan-out.** `ci/verify-docs.sh` exists for this; extend it with the six design gates from design-system §9.5 once the scaffold lands.
5. **Never fan out** live infrastructure, migrations, single-writer artefacts, or gated work.

---

## 11. NOT TESTED

- The **52 % parallelizable fraction is an estimate derived from spec dependency structure**, not a measurement. No task in the plan has been executed either serially or concurrently, apart from the C-2 chain and the §7 documentation batch.
- The **15–25 % coordination overhead** figure is a general rule of thumb, not measured on this codebase.
- The **one-gate-per-week reviewer assumption in §3 is purely illustrative.** Actual reviewer availability is unknown — that is the content of OQ-9. Every calendar figure that depends on it should be treated as conditional.
- **File-contention claims** (for example migration collisions across six Sprint 4 slices) are reasoned from reading the specs, not observed with real concurrent agents.
- Amdahl figures assume the parallel portion divides cleanly across tracks. Real work has uneven task sizes, so achieved speedup will be lower than the table shows.
- The §8 retrospective claim that serial spec work "would have completed in around a quarter of the wall-clock time" is an **estimate**, not a measured comparison. No A/B was run.
