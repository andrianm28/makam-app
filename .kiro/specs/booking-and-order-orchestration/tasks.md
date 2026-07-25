# Tasks — Booking and Order Orchestration

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Add product-type router. _Requirements: 4_
- [ ] Link submissions to FuneralCase/PreNeedCase. _Requirements: 5_
- [ ] Generalize confirmation guard for manual or reservation evidence. _Requirements: 6, 7_
- [ ] Preserve immutable quote/version acceptance. _Requirements: 8_
- [ ] Add payment and document security tests. _Requirements: 9, 10_
- [ ] Add browser tests for resumable intake and duplicate submission. _Requirements: 2, 9_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

### Boundary with `public-booking-wizard`

This spec is the **domain** layer; [`public-booking-wizard`](../public-booking-wizard/tasks.md) is the **presentation** layer. To avoid double implementation:

- `public-booking-wizard` owns: step rendering, stepper, draft UI, field components, per-step states.
- **this spec owns**: product-type routing, quote versioning, payment guard, order state machine, and the **state → intent contract** those states are rendered with.

This spec therefore contributes **status semantics**, not screens.

### Order lifecycle → intent (normative — the single source consumers must use)

design-system.md §3.7. Implement once in `StatusIntent`; the public site, the admin panel, and the vendor panel all consume it. Components must never `match` on an enum string.

| Status | Intent | Note |
|---|---|---|
| `MASUK` | `neutral` | Received, nothing decided |
| `DIVERIFIKASI` | `info` | Progressing |
| `MENUNGGU_KETERSEDIAAN` | `pending` | Waiting on operator (AC12 fallback) |
| `PENAWARAN_TERKIRIM` | `info` | Customer action available |
| `DISETUJUI_PEMESAN` | `info` | Accepted, **not paid** |
| `MENUNGGU_PEMBAYARAN` | `pending` | Awaiting user action |
| `MENUNGGU_VERIFIKASI_PEMBAYARAN` | `pending` | Manual fallback. **Never `success`** |
| `DIBAYAR` | `success` | Money confirmed by valid webhook or approved manual verification (AC9) |
| `DIPROSES` | `info` | Fulfilment underway |
| `SELESAI` | `success` | Terminal success |
| `DITOLAK` | `danger` | Terminal; **reason mandatory** |
| `DIBATALKAN` | `neutral` | Terminal, not an error |
| `KEDALUWARSA` | `neutral` | Expiry is factual, not alarming |

AC11 requires commercial transitions to stay separate from case/work/certificate states — so those **must render as separate indicators**, never merged into one "done" badge.

### Gated fallback modes → UI contract

AC7 and `overview.md` §15. Mode values are read from the **server**; a front-end flag is insufficient (design-system.md §6.9).

| Mode | Value | Banner |
|---|---|---|
| `PaymentMode` | `MANUAL_COORDINATION` | `<x-mk.alert intent=info>` — Step 8 uses manual coordination. **Step 8 is never removed** |
| `WhatsAppMode` | `EMAIL_IN_APP_FALLBACK` | `intent=info` — email + in-app sent; WhatsApp not yet available |
| `PreNeedMode` | `INTEREST_ONLY` | `intent=info` — interest registered, **no payment created** |
| `GraveSearchMode` | `MANUAL_ASSISTANCE` | `intent=info` — manual assistance path |

Banners are **not dismissible** when they change how a user must pay.

### Required UI states owned by this layer

design-system.md **§6**. These are the states the domain layer must expose so the presentation layer can render them.

| Concern | State contract |
|---|---|
| Draft save (AC2) | `pending` while saving → `success` with server-confirmed timestamp → `danger` + retry. Inline `aria-live`, never a toast (§3.9). **AC negative criteria: no loss of draft** |
| Blocked early payment (AC6) | §6.4 — the payment guard denial must produce an explanatory state, never a silent no-op and never a raw 403 |
| Provider unavailable (AC7) | §6.5 — payment provider down must offer the manual path or a truthful pending state. **Never a dead end on Step 8** |
| Duplicate submission (AC9) | §6.6 — exactly-once effects must surface as the **same** confirmation, not a second order |
| Document access (AC10) | §6.7 — quarantined file is never previewable/downloadable/thumbnailed; surface the 5-minute signed-URL validity |
| Step 9 content (AC13) | §6.8 — quiet success; three distinct delivery visuals (`success` / `pending` / `neutral` when a channel is unavailable) |
| Notification status (AC14) | **Never claim a delivery without delivery state** |
| Operator silence (AC12) | `pending` + admin fallback remains actionable; never a blocking state |
| **loading** | §6.1 — the domain layer must expose deterministic in-flight flags per action (quote issue, payment open, submit) so the presentation layer can attach `wire:loading.delay` + `wire:target` to the *specific* action, not the whole page |
| **empty** | §6.2 — read models must distinguish *no data yet*, *no result for this filter*, and *access-restricted*. Collapsing them into one null response makes an honest empty state impossible upstream |
| **validation error** | §6.3 — server is authoritative; return field-keyed errors (not a single message string) so inline `aria-invalid` + a summary alert can both be rendered |
| **support** | §6.10 — every domain failure must carry a correlation reference that is safe to show a user; the raw ID goes to logs, the support reference goes to the UI |

### Tasks

- [ ] Implement `StatusIntent` as the single status → intent + icon + label resolver (§3.7), consumed by public, admin, and vendor surfaces.
- [ ] Expose gated-fallback mode values from the server for UI consumption (§6.9); no front-end flags.
- [ ] Ensure blocked-payment and authorization denials return explanatory states (§6.4), never raw 403 or silent failure.
- [ ] Ensure duplicate submission renders the same confirmation (§6.6).
- [ ] Add a "Boundary" note to this spec and to `public-booking-wizard` so the eight overlapping acceptance criteria are not implemented twice.
- [ ] Verify no design value is hardcoded in any Action, Service, or notification template.
