# Tasks — Plot Inventory and Reservation

- [ ] Define authoritative source adapter and reconciliation.
- [ ] Add stable plot identity and status history.
- [ ] Implement atomic hold/confirm/release/expire Actions.
- [ ] Add stale-source circuit breaker and fallback.
- [ ] Add public projection policy.
- [ ] Run race, retry, expiry, and failover tests.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

The design risk here is **implying availability that is not authoritative**. This spec's negative criteria are explicit: *"No direct purchase merely because a plot appears available."* Every visual state must be defensible.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Plot list / map projection | `<x-mk.card>` §3.3 | AC8 — only approved fields; `--radius-lg` |
| Plot status badge | `<x-mk.badge>` §3.6 + §3.7 | see mapping below |
| Source freshness indicator | `<x-mk.badge>` §3.6 | fresh `neutral` · **stale `pending` + reservation disabled** (AC6) |
| Reservation TTL countdown | `<x-mk.badge intent=pending>` §3.6 | ⚠️ **the one place a timer is permitted** — it is a real constraint, not manufactured urgency. Show remaining time factually, no red flashing, no `danger` styling |
| Hold / reserve action | `<x-mk.button variant=primary>` §3.1 | `--mk-control-h-lg`; disabled state must carry a visible reason (§3.1) |
| Release action | `<x-mk.modal>` §3.4 | reason captured (AC5); `danger` confirm, not default-focused |
| Admin override | `<x-mk.modal>` §3.4 | **privileged, reasoned, audited (AC9)**; `danger` confirm; requires recent re-authentication |
| Stale-source / degraded notice | `<x-mk.alert intent=pending>` §3.8 | §6.5 — reservation unavailable, package/class fallback retained |
| Conflict notice | `<x-mk.alert intent=danger>` §3.8 | AC9 — override must not *silently* resolve a conflict; the conflict must be visible |

### Plot status → intent (normative)

Register in the shared `StatusIntent` helper (design-system.md §3.7).

| State | Intent | Rationale |
|---|---|---|
| Available (authoritative, fresh) | `success` | Only with a fresh authoritative source |
| Available (non-authoritative / basic registry) | `neutral` + `Perlu konfirmasi` | **Never `success`.** Negative criteria forbid booking from a basic registry |
| Held / reserved by this user | `pending` | TTL applies |
| Held / reserved by another | `neutral` | Do not disclose who |
| Occupied | `neutral` | Not an error |
| Stale source (AC6) | `pending` + disabled action | New reservations stopped |
| Expired reservation (AC7) | `neutral` | Factual; **payment must be unreachable** |

### Required UI states

All ten states apply — design-system.md **§6**.

> **Gap:** this spec has **no screen-inventory ID** — consistent with `mvp-scope.md` §8, which excludes public specific-plot selection from MVP. If `G-PLOT-01` opens, add screens to [`screen-inventory.md`](../../../docs/product/screen-inventory.md) first.

| Concern | State notes |
|---|---|
| loading | §6.1 skeleton; reserve heights (map/list shift is disorienting) |
| empty | §6.2 — no plots matching filter + `Reset filter` |
| validation | §6.3 on reservation request |
| **authorization** | §6.4 — negative criteria: **restricted plot data must never reach a public projection**; denial must not reveal existence |
| **provider unavailable** | §6.5 — stale/degraded source: **disable reservation, keep the package/class request path, state the reason.** Never fall back to guessing availability |
| **duplicate/retry-safe** | §6.6 — AC3/AC4: acquisition is atomic and idempotent. A retried hold renders the **same** reservation; a lost race renders an honest "sudah dipesan orang lain" state, never a silent failure |
| **pending** | §6.7 — active hold with TTL; approaching expiry escalates `pending` emphasis, **not** `danger` |
| expiry (AC7) | `neutral` + payment unreachable; explain what to do next |
| success | §6.8 — quiet reservation confirmation; **reservation ≠ purchase** |
| support | §6.10 — a lost plot race needs a human route |
| responsive | §4.3 — plot lists must be usable at 320 px; no GIS map on mobile without a list fallback |

### Gate constraint

`G-PLOT-01` is closed and `mvp-scope.md` §8 excludes this from MVP. Until the gate opens, the default UI is package/class confirmation (see [`cemetery-directory-and-availability`](../cemetery-directory-and-availability/tasks.md)).

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Register plot states in the shared `StatusIntent` helper; non-authoritative availability renders `neutral` + `Perlu konfirmasi`, never `success`.
- [ ] Render the TTL countdown factually — the only permitted timer; no `danger` styling or flashing.
- [ ] Ensure a lost reservation race renders an honest state, never a silent failure (§6.6).
- [ ] Ensure stale source disables reservation and keeps the request-confirmation fallback visible (§6.5).
- [ ] Make conflicts visible; admin override must never silently resolve one (AC9).
- [ ] Add screens to `screen-inventory.md` before building, if `G-PLOT-01` opens.
- [ ] Implement all ten required states per the table above.
