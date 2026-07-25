# Tasks — Cemetery Directory and Availability

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Add capability profile schema and validation. _Requirements: 4_
- [ ] Add safe-default resolver and server-side checks. _Requirements: 4, 12_
- [ ] Update public directory projection by capability. _Requirements: 2, 3, 5, 12_
- [ ] Add optional plot source adapter interface. _Requirements: 6, 7_
- [ ] Add stale-source monitoring and fallback. _Requirements: 8_
- [ ] Add cross-cemetery authorization and capability-combination tests. _Requirements: 9_
- [ ] Benchmark directory and map queries. _Requirements: 2, 3, 11_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Cemetery card | `<x-mk.card as=a interactive>` §3.3 cemetery variant | `--radius-lg`, `--shadow-sm`, `--mk-border-subtle`, hover `--color-primary-300` + `--shadow-md` |
| Type badge (TPU/TPS) | `<x-mk.badge intent=neutral>` §3.6 | `--mk-intent-neutral-fg/bg/border` |
| Availability badge | `<x-mk.badge>` §3.6 + §3.7 | see the availability mapping below |
| `Perlu konfirmasi` label | `<x-mk.badge intent=neutral>` | **`--mk-intent-neutral-*` — never `success`.** An indicative price/availability styled as success is a false promise (design-system.md §2.3 DO) |
| Price range + source | meta text | `--text-sm`, `--mk-text-muted`; source attribution is mandatory (AC3) |
| Facilities list | inline tags | `--mk-intent-neutral-*`, `--radius-sm` |
| Google Maps link | `<x-mk.button variant=secondary>` §3.1 | external navigation; AC11 — map-provider failure must not hide the textual address |
| Filter controls | `<x-mk.field>` §3.2 | `--mk-border-interactive`, `--mk-control-h-md`, 44 px targets |
| Card grid | layout §4.3 | 1 col → `--breakpoint-md` 2 → `--breakpoint-xl` 3; `--mk-gutter` |

### Availability → visual intent (normative)

Resolve through the single `StatusIntent` helper (design-system.md §3.7). Components must not switch on capability strings.

| Availability state | Intent | Rationale |
|---|---|---|
| Indicative package/class (default, AC5) | `neutral` + `Perlu konfirmasi` | Not a guarantee — AC negative criteria forbid implying one |
| Confirmed available | `success` | Only with authoritative evidence |
| Awaiting operator confirmation | `pending` | §6.7 — never styled as success |
| Unavailable (capacity/closed) | `neutral` | Not an error |
| `SPECIFIC_PLOT` active (AC7) | `info` | Gated capability, only with authoritative registry |
| Stale/degraded source (AC8) | `pending` + fallback notice | §6.5 — reservation disabled, request path retained |

### Required UI states

All ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-010 city list | loading §6.1 · empty "no city" §6.2 — **never silently omit a required MVP city** (negative criteria) |
| PUB-011 list | loading skeleton cards (reserve exact heights, CLS < 0.1) · **no-result** §6.2 with `Reset filter` |
| PUB-011 detail | error §6.3 — capability resolution failure falls back to safe defaults (AC4), not a blank page |
| authorization | §6.4 — restricted plot data must never reach a public projection (negative criteria); explanatory page for gated capability, not a 404 |
| provider unavailable | §6.5 — stale plot source → disable reservation, keep package/class request path, state the reason |
| pending | §6.7 — awaiting operator availability confirmation |
| success | §6.8 — quiet |
| support | §6.10 |
| responsive | §4.3 — 320 / 360 / 768 / 1024 / 1280 px |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Build the cemetery card with all AC3 fields; availability badge via `StatusIntent` only.
- [ ] Ensure indicative availability renders `neutral` + `Perlu konfirmasi`, never `success`.
- [ ] Implement all ten required states per the table above.
- [ ] Verify only active capabilities render (AC12) and no restricted field reaches the public projection.
- [ ] Verify accessibility (§7): 44 px targets, focus ring, no colour-only availability signalling.
