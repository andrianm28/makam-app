# Tasks — Cemetery Operator Dashboard

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Create cemetery assignment model/policy. _Requirements: 2_
- [ ] Build dedicated operator panel. _Requirements: 1_
- [ ] Implement request queue and decision action. _Requirements: 3_
- [ ] Implement availability update action. _Requirements: 4_
- [ ] Implement response target and admin escalation. _Requirements: 5, 6_
- [ ] Add audit and adoption metrics. _Requirements: 7, 8_
- [ ] Add cross-cemetery isolation tests. _Requirements: 2_
- [ ] Verify admin fallback works with operator disabled. _Requirements: 5, 6_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This is a **third Filament panel** alongside `/admin` and `/vendor`. It inherits the same tokens (design-system.md §8.3). Do not give the operator panel its own palette — an operator and an admin looking at the same request must see the same colours.

> **Gap:** [`screen-inventory.md`](../../../docs/product/screen-inventory.md) lists only PUB-, ADM-, and VND- screens. **No `OPR-` screen IDs exist**, so the state table below is keyed by view name instead. Add OPR- entries to `screen-inventory.md` — required by design-system.md §9.3 before this spec can be marked done.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Panel palette | Filament theme §8.3 | derived from `--color-primary-*` and the semantic families |
| Request queue | `<x-mk.table>` §3.5 → cards below `--breakpoint-md` | `--mk-table-hover`; operators frequently work on tablets/phones on site |
| Availability decision | `<x-mk.button variant=primary>` confirm / `variant=danger` reject §3.1 | `--color-primary-600` / `--color-danger-600`; **reject requires a mandatory note (AC3)** |
| Mandatory note field | `<x-mk.field>` §3.2 | `--mk-border-interactive`; AC3 note cannot be skipped — enforce server-side, surface inline error §6.3 |
| Response-target indicator | `<x-mk.badge>` §3.6 | within target `neutral` · approaching `pending` · exceeded `pending` **not `danger`** — an overdue operator response is not the operator's error state, it triggers admin escalation (AC6) |
| Decision history | `<x-mk.table>` §3.5 | read-only, audited (AC7) |
| Adoption metrics | `<x-mk.card>` §3.3 | `--mk-surface-raised`; metrics are observational (AC8), never gating |

### Status → intent

Use the shared `StatusIntent` helper (design-system.md §3.7), the same resolver as the public site and admin panel. Availability request states map to `pending` while awaiting decision, `success` on confirm, `danger` on reject with reason.

### Required UI states

All ten states apply — design-system.md **§6**.

| View | State notes |
|---|---|
| Pending availability requests | loading §6.1 skeleton rows · **empty** §6.2 — "Tidak ada permintaan menunggu" is a *good* state here; phrase it positively, not as a void |
| Assigned cemetery availability | loading · empty · validation §6.3 on availability update (AC4) |
| Orders by status | loading · filter no-result §6.2 with `Reset filter` |
| Decision history | loading · empty ("Belum ada keputusan") |
| authorization | §6.4 — **AC2 cross-cemetery access must return an explanatory state that does not reveal whether the other cemetery's record exists** |
| provider unavailable | §6.5 — if notification dispatch fails, the decision still commits (channel failure never changes business state) |
| pending | §6.7 — awaiting admin action after escalation |
| duplicate/retry-safe | §6.6 — repeated confirm submission must not create two decisions |
| success | §6.8 — quiet confirmation |
| support | §6.10 — internal escalation contact and runbook link |
| responsive | §4.3 — tables reflow to cards below `--breakpoint-md`; 44 px targets throughout |

### Non-blocking must be visible, not just true

AC5/AC6 make operator input advisory. The UI must **show** that: an exceeded response target renders as `pending` with an explicit "admin dapat melanjutkan tanpa keputusan operator" note, so nobody believes the workflow is stuck.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Confirm the operator panel inherits tokens (§8.3); no independent palette.
- [ ] Add `OPR-` screen IDs to `screen-inventory.md` with their required states.
- [ ] Implement all ten required states per the table above.
- [ ] Render exceeded response target as `pending` with an explicit non-blocking note, never `danger`.
- [ ] Ensure cross-cemetery denial does not leak record existence (§6.4).
- [ ] Verify accessibility (§7): 44 px targets, mandatory-note error handling, mobile/tablet reflow.
