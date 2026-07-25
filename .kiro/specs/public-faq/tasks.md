# Tasks — Public FAQ

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [ ] Create FAQ schema and seed six categories. _Requirements: 2_
- [ ] Build admin FAQ resource with preview/publish. _Requirements: 5_
- [ ] Build public list, filter, search, and detail. _Requirements: 1, 3, 4_
- [ ] Add related articles and customer-service CTA. _Requirements: 4, 8_
- [ ] Seed minimum initial questions. _Requirements: 2_
- [ ] Add authorization, publishing, search, and responsive tests. _Requirements: 6, 9_

## Design system

Governed by [`docs/design/design-system.md`](../../../docs/design/design-system.md) (component contracts, state patterns) and [`resources/css/tokens.css`](../../../resources/css/tokens.css) (every design value).

**Rule:** never hardcode a hex, px, ms, or shadow; never use Tailwind arbitrary values. See design-system.md §9.2.

### Primitives and tokens

| Element | Primitive | Tokens |
|---|---|---|
| Category filter chips | `<x-mk.badge>` §3.6 | `--mk-intent-neutral-fg/bg/border`; active uses `--color-primary-100` + `--color-primary-800` |
| Search input | `<x-mk.field>` §3.2 | `--mk-border-interactive` (**not** `--color-neutral-300` — fails 1.4.11), `--mk-control-h-md`, `--text-base` (16 px floor) |
| Article list item | `<x-mk.card as=a interactive>` §3.3 | `--radius-lg`, `--shadow-sm`, `--mk-border-subtle` |
| Article body | prose container | `--container-prose` (640 px ≈ 70 ch), `--text-base`, `--mk-text-default` |
| Article headings | §1.4 scale | `--text-3xl` (h2), `--text-2xl` (h3), `--font-weight-semibold`, `--tracking-tight` |
| Updated timestamp | muted meta | `--text-sm`, `--mk-text-muted` |
| Related articles | `<x-mk.card>` §3.3 | `--mk-stack-gap` |
| Customer-service CTA | `<x-mk.button variant=secondary>` §3.1 | `--color-primary-600` border, `--mk-control-h-md` |
| Draft badge (admin only) | `<x-mk.badge intent=neutral>` §3.6 | `--mk-intent-neutral-*` — never `success` for an unpublished article |

Admin CMS uses Filament 5 with the same tokens via design-system.md §8.3; do **not** restyle the panel independently.

### Required UI states

All ten states apply — design-system.md **§6**. This spec is the cheapest complete vertical slice, so it is the reference implementation for the state patterns.

| Screen | State notes |
|---|---|
| PUB-040 loading | §6.1 skeleton list, `--mk-skeleton-base`, `sr-only` announcement |
| PUB-040 empty (no result) | §6.2 — AC8: show related categories + customer-service path. Three parts: what is empty, why, next action |
| PUB-040 empty (empty category) | §6.2 — "Belum ada artikel di kategori ini." + `Lihat kategori lain` |
| PUB-041 error | §6.3 — article fetch failure keeps navigation usable |
| authorization | §6.4 — AC6: unpublished articles must 404-as-not-found without revealing existence; explanatory page for gated content |
| provider unavailable | §6.5 — search backend down → fall back to category browse, state it plainly |
| duplicate/retry-safe | §6.6 — repeated search submit is idempotent |
| pending | §6.7 — admin publish in progress uses `pending` |
| success | §6.8 — quiet publish confirmation; no celebration |
| support | §6.10 — AC4 customer-service CTA on **every** article |
| responsive | §4.3 — 1 col → `--breakpoint-md` sidebar + list |

### Tasks

- [ ] Reference tokens for all colour/spacing/type; zero hardcoded values.
- [ ] Implement all ten required states per the table above; this spec is the reference slice.
- [ ] Use `--container-prose` for article body; never full-bleed paragraphs.
- [ ] Verify accessibility (design-system.md §7): 16 px input floor, focus ring, 44 px targets, `lang="id"`.
- [ ] Confirm Filament FAQ resource inherits tokens (design-system.md §8.3), not its own palette.
