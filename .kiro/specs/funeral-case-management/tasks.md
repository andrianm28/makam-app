# Tasks — Funeral Case Management

- [ ] Define case/task/communication schema and state machine.
- [ ] Implement case creation from At-Need intake.
- [ ] Implement assignment/handover and task templates.
- [ ] Implement escalation scheduler and notifications.
- [ ] Build Filament case workspace and customer timeline projection.
- [ ] Add SLA, authorization, waiver, retry, and handover tests.
- [ ] Add Urgent capacity dashboard and runbook links.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

This spec has **two audiences with opposite needs**: an internal case workspace (dense, operational) and a customer timeline (AC8: empathetic, simplified, no internal notes). They share tokens but not density or tone.

> **Gap:** the internal workspace maps loosely to ADM-040, but the **customer-facing case timeline has no screen-inventory ID** (closest is PUB-050 Customer order status). Add it to [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Case workspace (Filament) | tables + cards §3.5/§3.3 | inherits tokens via §8.3; `--mk-control-h-sm` permitted for dense row actions on pointer devices only |
| Case status badge | `<x-mk.badge>` §3.6 + §3.7 | see mapping below |
| Task list | `<x-mk.table>` §3.5 | overdue task uses `pending`, escalated uses `danger` |
| Due-time / SLA indicator | `<x-mk.badge>` §3.6 | on-time `neutral` · approaching `pending` · overdue `danger` — **plus an icon and text**, never colour alone (§7.5) |
| Communication log | `<x-mk.card>` §3.3 stacked | `--mk-stack-gap`; AC4 — record channel/participants/purpose without storing unnecessary sensitive content |
| Handover dialog | `<x-mk.modal>` §3.4 | reason field mandatory (AC5); destructive-adjacent, so do not default-focus confirm |
| Waiver dialog | `<x-mk.modal>` §3.4 | `danger` confirm; **authorized waiver reason mandatory** (negative criteria) |
| **Customer timeline** | `<x-mk.card>` §3.3, generous spacing | `--mk-surface-warm` for reassurance sections; `--container-prose` measure; `--text-base`; **no dense tables** |
| Urgent capacity dashboard | `<x-mk.card>` + badges | `--mk-intent-urgent-*` (alias of warning); always with explicit availability text |

### Case status → intent (normative)

Extends design-system.md §3.7. Register these in the shared `StatusIntent` helper alongside the order-lifecycle states — do not create a second resolver.

| Status | Intent | Note |
|---|---|---|
| `NEW` | `neutral` | Received |
| `TRIAGED` | `info` | Progressing |
| `COORDINATING` | `info` | Active work |
| `READY_FOR_SERVICE` | `info` | — |
| `IN_SERVICE` | `info` | — |
| `COMPLETED` | `success` | AC7 — **payment alone cannot complete a case** |
| `DECLINED` | `danger` | Reason mandatory |
| `CANCELLED` | `neutral` | Not an error |
| `TRANSFERRED` | `info` | Handover preserved |

Case state and commercial order state are **separate indicators** and must never be merged into one badge.

### Required UI states

All ten states apply — design-system.md **§6**.

| Surface | State notes |
|---|---|
| Case workspace | loading §6.1 skeleton · empty ("Belum ada kasus ditugaskan") · validation §6.3 on task completion evidence |
| Task engine | `pending` §6.7 for awaiting-evidence tasks; overdue is observable (AC3) |
| **Customer timeline** | loading §6.1 — **quiet skeleton, no pulsing anxiety**; empty ("Kami sedang menyiapkan langkah berikutnya") · success §6.8 **quiet, no celebration** · support §6.10 **always visible** |
| authorization | §6.4 — AC negative criteria: internal notes must never surface to the customer; denial must not reveal record existence |
| provider unavailable | §6.5 — notification/escalation dispatch failure creates a fallback task (AC6), never a silent block |
| duplicate/retry-safe | §6.6 — task template instantiation is idempotent; escalation jobs use unique case/task/window keys |
| Urgent gate closed | §6.4 + `--mk-intent-urgent-*` — AC9 states hours/coverage **without an acceptance claim**, hotline shown |
| responsive | §4.3 — case managers work on phones; workspace tables reflow to cards below `--breakpoint-md` |

### Tone constraint

The customer timeline is the most emotionally sensitive surface in the product. design-system.md §2.3 applies with full force: no urgency manufacturing, no countdown, no celebration, no euphemism-dodging. Quiet, factual, respectful.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Register case statuses in the shared `StatusIntent` helper; do not create a second resolver.
- [ ] Render case state and commercial state as separate indicators.
- [ ] Build the customer timeline with prose measure and generous spacing — not a dense internal table.
- [ ] Add the customer case-timeline screen to `screen-inventory.md` with its states.
- [ ] Implement all ten required states per the table above.
- [ ] Verify SLA indicators use icon + text, never colour alone (§7.5).
