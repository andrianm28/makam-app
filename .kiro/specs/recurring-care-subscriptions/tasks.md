# Tasks — Recurring Care Subscriptions

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Implement cycle frequency and date calculation. _Requirements: 1_
- [ ] Implement subscription and cycle unique constraints. _Requirements: 2, 5_
- [ ] Implement scheduler with deterministic idempotency key. _Requirements: 2, 5_
- [ ] Issue payment-link invoices through shared payment contract. _Requirements: 3_
- [ ] Implement webhook-driven payment state. _Requirements: 4_
- [ ] Implement work order and evidence tracking. _Requirements: 5, 6_
- [ ] Implement tokenization adapter behind feature flag. _Requirements: 8_
- [ ] Add scheduler retry, duplicate, and timezone tests. _Requirements: 2, 5_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

AC6 requires billing, work scheduling, completion evidence, complaint, and make-good to be **separate states**. That is a rendering requirement as much as a domain one: **five separate indicators, never one "status" badge.**

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Care plan cards (PUB-021) | `<x-mk.card>` §3.3 | `--radius-lg`, `--shadow-sm`; four intervals from `marketplace-catalog.md` |
| Interval selector | `<x-mk.field>` §3.2 | 44 px rows, `--mk-control-h-md` |
| Subscription status | `<x-mk.badge>` §3.6 + §3.7 | see mapping below |
| Cycle status | `<x-mk.badge>` §3.6 | **separate badge** from subscription status |
| Billing amount / next charge | table §3.5 | `text-right tabular-nums`, `--font-mono` |
| Payment link (AC3) | `<x-mk.button variant=primary>` §3.1 | `--mk-control-h-lg` |
| Pause / cancel | `<x-mk.modal>` §3.4 | consequence stated; AC7 policies must be configured before the control is exposed at all |
| Work order + evidence | upload states §6.7 | evidence private; `pending` while scanning |
| Tokenization notice | `<x-mk.alert intent=info>` §3.8 | behind feature flag; **raw PAN/CVV never stored, never displayed, never in a form** |

### Status → intent (normative)

Register in the shared `StatusIntent` helper (design-system.md §3.7) — do not create a second resolver.

**Subscription:** `DRAFT` neutral · `ACTIVE` success · `PAUSED` pending · `ENDED` neutral · `CANCELLED` neutral

**Cycle:** `SCHEDULED` neutral · `INVOICED` pending · `PAID` success · `WORK_SCHEDULED` info · `COMPLETED` success · `EXPIRED` neutral

> **`PAID` ≠ `COMPLETED`.** A paid cycle whose work is not yet done renders as `PAID` **and** `WORK_SCHEDULED` — two indicators. Never a single "done" badge.
>
> **AC4 / design.md:** `ACTIVE` and `PAID` cannot be inferred from a notification or a browser return. The UI renders only server-confirmed state.

### Required UI states

All ten states apply — design-system.md **§6**. Customer-facing surface is PUB-021 (product) plus an account subscription view; vendor-facing work orders are in [`grave-care-fulfillment`](../grave-care-fulfillment/tasks.md).

> **Gap:** the customer subscription-management view has **no screen-inventory ID**. Add it to [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

| Concern | State notes |
|---|---|
| loading | §6.1 skeleton for cycle history |
| empty | §6.2 — no subscription yet ("Belum ada langganan perawatan" + `Lihat paket perawatan`) |
| validation | §6.3 inline + summary on plan change |
| authorization | §6.4 — explanatory state; must not reveal another customer's subscription |
| provider unavailable | §6.5 — payment provider down → truthful `pending`, **never a failed charge shown as cancelled** |
| **duplicate/retry-safe** | §6.6 — AC2/AC5: **one invoice per cycle, one work order per paid cycle.** The UI must never offer an action that would create a duplicate, and a retried scheduler run must render the same cycle, not a second one |
| **pending** | §6.7 — `INVOICED` awaiting payment, dunning/grace (AC7), scan-in-progress evidence. Never styled as success |
| success | §6.8 — quiet; payment confirmed and work completed are **separate** confirmations |
| support | §6.10 — billing questions need a human route |
| responsive | §4.3 — cycle history table reflows to cards below `--breakpoint-md` |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Register subscription and cycle statuses in the shared `StatusIntent` helper.
- [ ] Render billing, work, evidence, complaint, and make-good as five separate indicators (AC6).
- [ ] Never render `PAID` as completion; show payment and fulfilment separately.
- [ ] Render only server-confirmed paid state; no optimistic UI on the money path (AC4).
- [ ] Add the customer subscription view to `screen-inventory.md` with its states.
- [ ] Implement all ten required states per the table above.
- [ ] Ensure no card-data field exists in any form while tokenization is gated.
