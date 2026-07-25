# Tasks — Renewal and Grave Registry

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Enable/configure PostgreSQL trigram support. _Requirements: 3, 4_
- [ ] Implement grave record model and access modes. _Requirements: 12, 14_
- [ ] Implement fuzzy search with benchmark at 100k records. _Requirements: 3, 4_
- [ ] Implement async 10k-row import and row error report. _Requirements: 13_
- [ ] Implement renewal quote with tariff source/effective time. _Requirements: 6, 7_
- [ ] Implement manual entry/empty state. _Requirements: 5_
- [ ] Implement external marking and duplicate-period guard. _Requirements: 10, 11_
- [ ] Integrate payment/invoice after gate. _Requirements: 8, 9_
- [ ] Implement reminder scheduler and idempotency key. _Requirements: 15_
- [ ] Add privacy, authorization, performance, and duplicate tests. _Requirements: 4, 11, 14, 16_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

The empty state on this journey carries unusual weight: a family searching for a grave record and finding nothing must not conclude the grave does not exist.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Six-step progress | `<x-mk.stepper>` §3.9 | same primitive as the booking wizard, **six** steps; `--mk-progress-track`, `--mk-progress-fill` |
| Search form | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, `--text-base` (16 px floor), `inputmode` hints for dates |
| Result rows | `<x-mk.table>` §3.5 → cards below `--breakpoint-md` | `--mk-table-hover`, `--mk-table-stripe` |
| Fee display | `<x-mk.card>` §3.3 | amount `--font-weight-bold` `--font-mono`; **source + last-updated mandatory** (AC6) in `--text-sm` `--mk-text-muted` |
| Tariff mismatch warning | `<x-mk.alert intent=pending>` §3.8 | `--mk-intent-pending-*` — a mismatch is a caution, not an error |
| Renewal status | `<x-mk.badge>` §3.6 + §3.7 | `MENUNGGU_PEMBAYARAN` → `pending`; `DIBAYAR` → `success`; `KEDALUWARSA` → `neutral` |
| Payment step | §6.9 mode banner | manual fallback = `intent=info`; **never remove the payment step** |
| Confirmation / invoice | `<x-mk.card>` | reference `--font-mono`, copyable; due date prominent |
| Import (admin) | progress + row errors | `role="progressbar"`; row-level errors in `<x-mk.table>` |

### Required UI states

All ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-030 city/cemetery | loading §6.1 · empty §6.2 — never omit a required MVP city |
| PUB-031 grave search — results | loading skeleton rows with `sr-only` announcement; reserve heights |
| PUB-031 — **no result** | §6.2, three parts: *what is empty · why (the registry may be incomplete) · next action*. AC5 requires an honest manual-entry / customer-service path. **Do not imply the record does not exist.** |
| PUB-031 — **privacy-limited** | §6.2 — **distinct from "not found"**. When `G-DATA-01` restricts the field projection, say so explicitly. Two different states, two different messages |
| PUB-031 — data gate closed | §6.4 explanatory state (AC16), never a generic 404 |
| PUB-032 fee | source + last-updated always visible · mismatch warning `pending` · **no invented late fine** (AC7) — if there is no written operator basis, show nothing rather than a computed figure |
| PUB-033 payment | online · manual fallback §6.9 · `pending` · failed §6.5 with a live fallback |
| PUB-034 confirmation | success §6.8 **quiet**; reference, status, invoice state, resulting due date |
| duplicate/retry-safe | §6.6 — AC11 duplicate-period guard must surface as "sudah diperpanjang untuk periode ini", not a second invoice |
| provider unavailable | §6.5 — search backend down → state it, offer manual assistance |
| support | §6.10 on every step |
| responsive | §4.3 — result tables become cards below `--breakpoint-md` |

### Performance affects design

AC4 targets < 500 ms at 100k records. Skeleton loading (§6.1) must reserve exact row heights to keep CLS < 0.1, and the search field should debounce rather than fire per keystroke. Weight budget: design-system.md §4.6.

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Build the six-step stepper with `<x-mk.stepper>` (same primitive, six steps).
- [ ] Implement **three distinct empty states**: no-result, privacy-limited, and gate-closed. Do not collapse them into one message.
- [ ] Always render tariff source + last-updated; never display a computed fine without written basis.
- [ ] Surface the duplicate-period guard as an informative state, not a failure.
- [ ] Implement all ten required states per the table above.
- [ ] Verify accessibility (§7) and that skeletons reserve exact heights (CLS < 0.1).
