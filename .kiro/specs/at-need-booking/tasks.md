# Tasks — At-Need Booking

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Define minimum intake fields and validation. _Requirements: 1_
- [ ] Build area/hours/capacity gate. _Requirements: 2_
- [ ] Connect intake to FuneralCase and task templates. _Requirements: 3_
- [ ] Implement progressive document collection. _Requirements: 4_
- [ ] Add service time/transport milestones. _Requirements: 8_
- [ ] Add empathetic closure/degraded browser tests. _Requirements: 9_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

Users on this flow are in the first hours of bereavement. design-system.md §2 (Brand and mood) is a functional requirement here, not decoration.

### Entry-point constraint — read before building UI

`AGENTS.md`: *"Booking exposes Steps 1–9 exactly as documented."* This spec's negative criteria correctly forbid imposing a long wizard on an urgent family, and `booking-wizard-fields.md` §Branching resolves the tension: **the UI retains the nine-step framing; the internal workflow may shorten operational data collection.** So:

- The **entry point remains** the [`public-booking-wizard`](../public-booking-wizard/tasks.md) nine-step shell (Step 3 → `URGENT_TODAY`).
- The lightweight intake in this spec's `design.md` is the **internal** sequence behind that entry, not a parallel public form.
- Do not build a separate urgent landing form outside the nine-step entry.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Urgent path emphasis | `<x-mk.alert intent=urgent>` §3.8 | `--mk-intent-urgent-fg/bg/border` (alias of warning — **no new hue**), always with explicit availability text |
| Minimum intake fields | `<x-mk.field>` §3.2 | `--mk-control-h-md`, `--text-base` (16 px floor), `--mk-field-gap`; `inputmode="tel"` + `autocomplete="tel"` for contact |
| Persistent human contact (AC2) | `<x-mk.button variant=secondary>` §3.1 | **always visible** for accepted service areas — this is the most important control on the screen |
| Capacity-closed state | `<x-mk.alert intent=urgent>` §3.8 | states hours/coverage + hotline, **no acceptance claim** while `G-OPS-01` is closed |
| Quote breakdown (AC6) | `<x-mk.card>` + `<x-mk.table>` §3.5 | land/package, service, add-ons, transport, **exclusions**; `tabular-nums`, `--font-mono` |
| Progressive document collection | upload states §6.7 | AC4 post-service completion renders as `pending`, **never as a blocking error** |
| Service/transport milestones | `<x-mk.card>` timeline §3.3 | `--mk-stack-gap`; quiet, factual |

### Required UI states

All ten states apply — design-system.md **§6**. Urgent-specific emphasis in bold.

| Concern | State notes |
|---|---|
| loading | §6.1 — **short, quiet skeletons.** No spinner-heavy screens for a user under stress |
| empty | §6.2 — no available capacity → honest message + alternative contact + next step (this spec's `design.md` "Failure behavior") |
| validation | §6.3 — **never clear entered data**; a family re-typing a deceased person's details is a real harm. Inline + summary, focus to summary |
| authorization | §6.4 — explanatory state, never a raw 403 |
| provider unavailable | §6.5 — **active case failures escalate to humans** (design.md); surface the human route, not a technical error |
| duplicate/retry-safe | §6.6 — repeated intake submission must not create two FuneralCases (AC3) |
| **pending** | §6.7 — outstanding documents (AC4) and awaiting-confirmation states are `pending`; **payment timing follows approved policy (AC7) — never imply a DP requirement that does not exist** |
| success | §6.8 — **quiet**. AC9 delivers receipt, evidence, and certificate status **separately**; do not merge them into one "complete" indicator |
| **support** | §6.10 — AC2 makes the human contact route persistent, not an escape hatch of last resort |
| responsive | §4.3 — assume 320–360 px on mobile data, one-handed, possibly outdoors |

### Tone constraints (design-system.md §2.3)

- ❌ No countdown timers, no "hanya tersisa", no urgency manufacturing. The urgency is already real.
- ❌ No celebration on completion.
- ❌ No stock grief imagery.
- ✅ Plain Indonesian, direct, no euphemism-dodging.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Add an explicit "Entry point" note citing `booking-wizard-fields.md` §Branching so no parallel urgent form is built.
- [ ] Render Urgent emphasis with `--mk-intent-urgent-*` plus explicit availability text; never imply capacity that is not configured.
- [ ] Implement all ten required states per the table above; validation must never clear entered data.
- [ ] Render receipt, evidence, and certificate status as three separate indicators (AC9).
- [ ] Keep the human contact route persistently visible (AC2).
- [ ] Verify accessibility (§7) at 320 px, one-handed reach, 44 px targets.
