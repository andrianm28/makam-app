# Tasks — Visitation Booking

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Add visitation capability configuration. _Requirements: 1_
- [ ] Add schedule/capacity/blackout model. _Requirements: 2_
- [ ] Build customer request and operator calendar. _Requirements: 3, 5, 6_
- [ ] Add navigation projection and privacy tests. _Requirements: 4_
- [ ] Add cancellation/no-show and duplicate submission tests. _Requirements: 7_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

AC1 gives two modes — information-only and bookable. **The visual difference must be unmistakable**, or a family will believe they have a confirmed slot when the cemetery only publishes opening hours.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Mode banner (information-only) | `<x-mk.alert intent=info>` §3.8 | `--mk-intent-info-*`; states plainly that booking is not available and visiting hours apply |
| Capability-driven rendering | server-side check | AC1 mode read from `CemeteryCapability`; **never a front-end flag** (§6.9) |
| Slot picker | `<x-mk.field>` §3.2 | 44 px targets; `--mk-control-h-md`; blackout dates visibly disabled **with a reason**, not silently missing |
| Visitor count / contact | `<x-mk.field>` §3.2 | `inputmode="numeric"` for count, `autocomplete="tel"` for contact, `--text-base` (16 px floor) |
| Accessibility needs field | `<x-mk.field>` textarea §3.2 | AC3 — a family requesting wheelchair access must not have to explain it in a 40-character input; generous rows |
| Facility requests | `<x-mk.field>` checkbox §3.2 | 20 px box in 44 px rows |
| Booking status | `<x-mk.badge>` §3.6 + §3.7 | requested `pending` · confirmed `success` · cancelled `neutral` · no-show `neutral` |
| Confirmation + instructions | `<x-mk.card>` §3.3 | AC5 — instructions, change/cancel status, **fallback contact** |
| Navigation projection | `<x-mk.button variant=secondary>` §3.1 | AC4/design.md — a **projection service**, never raw registry exposure |
| Operator calendar | `<x-mk.table>` §3.5 → cards below `--breakpoint-md` | inherits operator-panel tokens §8.3 |

### Required UI states

All ten states apply — design-system.md **§6**.

> **Gap:** this spec has **no screen-inventory ID** — consistent with `mvp-scope.md` §8, which excludes visitation booking from MVP. If `G-VISIT-01` opens, add screens to [`screen-inventory.md`](../../../docs/product/screen-inventory.md) first.

| Concern | State notes |
|---|---|
| loading | §6.1 skeleton for slot availability |
| **empty** | §6.2 — no slots available: say **why** (fully booked / blackout / outside hours) and offer the next available date or a contact route. A bare "tidak tersedia" is not acceptable |
| validation | §6.3 inline + summary; never clear entered data |
| **authorization** | §6.4 — AC6: operator sees only assigned-cemetery bookings; denial must not reveal another cemetery's data. AC4 navigation follows data-access policy |
| provider unavailable | §6.5 — AC design.md: notification is asynchronous with fallback; **channel failure never changes booking state** |
| **duplicate/retry-safe** | §6.6 — AC7: repeated submission must not duplicate a booking; render the **same** confirmation |
| pending | §6.7 — awaiting operator confirmation where confirmation policy applies; **never styled as confirmed** |
| success | §6.8 — quiet confirmation with instructions and fallback contact |
| support | §6.10 — fallback contact is required by AC5, not optional |
| responsive | §4.3 — slot picker usable at 320 px; no dense calendar grid on mobile without a list view |

### Mode distinction is a hard requirement

Information-only mode must **not** render a slot picker, a confirm button, or any control that looks bookable. Use the `intent=info` banner plus opening-hours content only.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Render information-only mode with no bookable controls at all; read the mode server-side.
- [ ] Show disabled blackout dates with a visible reason rather than omitting them.
- [ ] Give the accessibility-needs field generous room (AC3).
- [ ] Ensure `pending` (awaiting confirmation) is never styled as confirmed.
- [ ] Ensure repeated submission renders the same confirmation (AC7, §6.6).
- [ ] Add screens to `screen-inventory.md` before building, if `G-VISIT-01` opens.
- [ ] Implement all ten required states per the table above.
