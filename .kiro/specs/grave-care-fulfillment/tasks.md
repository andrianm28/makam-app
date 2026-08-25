# Tasks — Grave Care Fulfillment

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Separate billing cycles from work orders. _Requirements: 1, 2_
- [ ] Implement schedule/assignment/checklist/evidence. _Requirements: 3_
- [ ] Add customer acceptance/complaint/make-good. _Requirements: 5_
- [ ] Implement vendor replacement/reschedule. _Requirements: 7_
- [ ] Add duplicate cycle, privacy, and paid-not-completed tests. _Requirements: 4, 6, 8_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

AC4 makes before/after evidence **private to authorized parties unless explicitly published**. That is the single most important design constraint here: photographs of a family's grave are restricted data.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Work order card (vendor) | `<x-mk.card>` §3.3 | inherits Filament vendor-panel tokens §8.3 |
| Service checklist | `<x-mk.field>` checkbox §3.2 | 20 px box in a **44 px** row; vendors work outdoors on phones |
| Schedule / assignee | `<x-mk.field>` §3.2 | `--mk-control-h-md`, `--mk-border-interactive` |
| **Before/after evidence** | upload states §6.7 | `idle → uploading → scanning (pending) → accepted (success) → rejected (danger)`. **A quarantined image is never previewable or thumbnailed** — show filename, type icon, size only |
| Billing status | `<x-mk.badge>` §3.6 + §3.7 | **separate badge** from work-order status (AC2) |
| Work-order status | `<x-mk.badge>` §3.6 | via `StatusIntent`; `SELESAI` success, missed service `pending` + exception |
| Customer acceptance | `<x-mk.button variant=primary>` §3.1 | AC5 accept / complain / request make-good |
| Complaint form | `<x-mk.field>` textarea §3.2 | `--text-base`, generous rows; tone per §2.3 |
| Make-good order | `<x-mk.alert intent=info>` §3.8 | linked to the original cycle, not a new charge |
| Vendor replacement / reschedule | `<x-mk.modal>` §3.4 | reason captured, audited (AC7) |

### Billing and work are two indicators (AC2, AC6)

design-system.md §3.7: **`DIBAYAR` ≠ `SELESAI`.** A paid cycle with a missed service renders as `DIBAYAR` (success) **and** an operational exception (`pending`/`danger`) — never collapsed. AC6 requires that a failed service does **not** alter payment history, so the UI must not imply a refund or a reversal that has not happened.

### Required UI states

All ten states apply — design-system.md **§6**.

> **Gap:** vendor evidence maps to **VND-050**, but the **customer-facing care-history / acceptance view has no screen-inventory ID.** Add it to [`screen-inventory.md`](../../../docs/product/screen-inventory.md).

| Surface | State notes |
|---|---|
| Vendor work list | loading §6.1 · empty ("Tidak ada pekerjaan terjadwal" — a good state, phrase it positively) |
| Vendor evidence upload | §6.7 full upload state machine; scanner outage → `pending`, **never `accepted`** (fail-closed) |
| Customer care history | loading · empty ("Belum ada riwayat perawatan") |
| Customer acceptance | validation §6.3 · success §6.8 quiet |
| Complaint / make-good | `pending` §6.7 while under review; resolution `success` |
| **Missed/failed service** | §6.5-style honest state: what happened, what is being done, who to contact. **Not styled as a customer error** |
| authorization | §6.4 — **AC4: evidence must not leak to unauthorized parties**; denial must not reveal whether evidence exists |
| duplicate/retry-safe | §6.6 — AC8: one cycle cannot produce a duplicate invoice or work order under retries |
| support | §6.10 — complaint path needs a human route |
| responsive | §4.3 — vendor UI must work at 320 px, one-handed, outdoors, on mobile data; checklist rows 44 px |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Render billing status and work-order status as two separate badges (AC2).
- [ ] Implement the full upload state machine for evidence (§6.7); no preview or thumbnail before scan acceptance.
- [ ] Ensure a missed service shows an honest operational state without implying a payment reversal (AC6).
- [ ] Add the customer care-history/acceptance view to `screen-inventory.md` with its states.
- [ ] Implement all ten required states per the table above.
- [ ] Verify vendor UI at 320 px with 44 px checklist rows.
