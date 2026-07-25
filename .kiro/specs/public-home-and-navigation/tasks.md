# Tasks — Public Home and Navigation

- [ ] Implement homepage route and layout.
- [ ] Implement exact four service cards.
- [ ] Implement desktop/mobile navigation.
- [ ] Add urgent truthful-state banner.
- [ ] Add customer-service CTA.
- [ ] Add route and feature-gate explanatory pages.
- [ ] Add responsive, keyboard, and analytics tests.

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values (`text-[#12545E]`, `p-[13px]`, `z-[9999]`). See design-system.md §9.2.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Global header | `<x-mk.header>` §3.10 | `--mk-header-h`, `--mk-header-h-lg`, `--mk-z-header`, `--mk-safe-top`, `--mk-border-subtle` |
| Skip link | §3.10 | `--mk-z-skiplink`, `--color-primary-600`, `--mk-text-inverse` |
| Four service cards | `<x-mk.card interactive>` §3.3 | `--radius-lg`, `--shadow-sm`, `--mk-border-subtle`, `--color-primary-300` (hover) |
| Primary CTA `Pesan Makam` | `<x-mk.button variant=primary size=lg>` §3.1 | `--color-primary-600/700/800`, `--mk-control-h-lg`, `--radius-md` |
| Urgent availability banner | `<x-mk.alert intent=urgent>` §3.8 | `--mk-intent-urgent-fg/bg/border` (alias of warning — **no new hue**) |
| Trust/safety section | surface tint | `--mk-surface-warm` (`--color-secondary-50`) |
| Footer | inverse surface | `--mk-surface-inverse` (`--color-primary-900`), white text verified 14.40:1 |
| Nav active state | §3.10 | `--color-primary-700` + 2px `--color-primary-600` underline, `aria-current="page"` |

Layout: `--container-content` shell, `--mk-gutter` → `--mk-gutter-md` → `--mk-gutter-lg`, `--mk-section-gap` / `--mk-section-gap-lg` between the nine homepage sections. Section order is normative — design-system.md §4.5 mirrors `information-architecture.md` §3.

Grid progression for the four cards (design-system.md §4.3): 1 col → `--breakpoint-sm` 2 → `--breakpoint-lg` 4.

### Required UI states

Per `AGENTS.md` (Mandatory MVP UX) and [`screen-inventory.md`](../../../docs/product/screen-inventory.md) §D, all ten states apply — design-system.md **§6**.

| Screen | State notes |
|---|---|
| PUB-001 normal | — |
| PUB-001 loading | §6.1 skeleton for the featured-cemetery section only; header and four cards render server-side (no skeleton for static nav) |
| PUB-001 empty | §6.2 — when no featured TPU/TPS data exists, **hide section 5 entirely**; do not render an empty shell |
| PUB-001 error | §6.3 — homepage must still render the four menus if a secondary panel fails |
| PUB-001 authorization | §6.4 — AC6 gate-explanatory page, never a generic 404 |
| PUB-001 provider unavailable | §6.5 — degraded notification banner (`intent=info`) |
| PUB-001 pending | §6.7 — Urgent "checking availability" uses `pending`, never `success` |
| PUB-001 success | n/a (non-transactional screen) |
| PUB-001 support | §6.10 — persistent `Bantuan` in header is **mandatory** (IA §2), never collapsed into the hamburger |
| PUB-001 responsive | §4.3 — verify 320 / 360 / 768 / 1024 / 1280 px |

### Constraint — mobile navigation

`<x-mk.bottomnav>` (design-system.md §3.11) is **PROPOSED and NOT APPROVED**. IA §2 specifies logo + hamburger + persistent Bantuan. Ship the §3.10 header only. Do not add a bottom nav without a product decision (design-system.md OQ-04).

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values (CI-enforced, design-system.md §9.5).
- [ ] Implement the four service cards with `<x-mk.card>`; preserve exact stakeholder order.
- [ ] Implement all ten required states for PUB-001 per the table above.
- [ ] Verify contrast/focus/touch-target compliance (design-system.md §7): 44 px minimum, visible focus ring, no colour-only status.
- [ ] Confirm the nine-section homepage order matches design-system.md §4.5.
