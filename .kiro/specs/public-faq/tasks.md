# Tasks — Public FAQ

`_Requirements: N_` references the numbered acceptance criteria in [`requirements.md`](requirements.md), added 25 Jul 2026 to match Kiro's documented task-traceability convention.

- [x] Create FAQ schema and seed six categories. _Requirements: 2_ — done 26 Jul 2026 (Sprint 4 S4-T2, Batch 4.1), CI green
- [x] Build admin FAQ resource with preview/publish. _Requirements: 5_ — done 26 Jul 2026 (Batch 4.3), CI green
- [x] Build public list, filter, search, and detail. _Requirements: 1, 3, 4_ — done 26 Jul 2026 (Batch 4.2), CI green
- [x] Add related articles and customer-service CTA. _Requirements: 4, 8_ — done 26 Jul 2026 (Batch 4.2)
- [x] Seed minimum initial questions. _Requirements: 2_ — done 26 Jul 2026 (23 real questions across 6 categories, `docs/product/faq-catalog.md`'s minimum met)
- [ ] Add authorization, publishing, search, and responsive tests. _Requirements: 6, 9_ — authorization/publishing/search tests done and passing in CI; **responsive verification is NOT done** (no browser available on this host — see sprint-plan.md §14 NOT TESTED)

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

**Correction, 09 Aug 2026 (retrofit-faq):** three rows above describe primitives the shipped code deliberately does not use, and the deviations are now the canonical choice, not a gap. Category filter chips ship as `<x-mk.filter-chip>`, which `design-system.md:444` §3.6a now defines as canonical and explicitly names `public-faq` as one of the batches whose hand-written recipe motivated it — this row is superseded, not unmet. The search input is hand-written because `<x-mk.field>` merges `$attributes` onto its wrapper `<div>` only, so `wire:model` cannot reach the real control. The customer-service CTA is hand-written at three call sites. Each deviation is reasoned in-file at its own call site. A future implementer should not "fix" this working code back to a primitive design-system.md itself says is wrong.

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

- [x] Reference tokens for all colour/spacing/type; zero hardcoded values. — CI-enforced (`ci/verify-docs.sh`), green on every batch
- [ ] Implement all ten required states per the table above; this spec is the reference slice. — 9 of 10 implemented and test-covered (loading/empty×2/error/authorization/provider-unavailable/duplicate-retry-safe/pending/success/support); **responsive is NOT verified** (no browser on this host)

  **Correction, 09 Aug 2026 (retrofit-faq, Task 2 whole-module review):** this claim was wrong on both counts it made. **8 of 10 states are implemented, not 9** — `FaqArticleDetail.php` has no `try`/`catch` anywhere; its only non-happy path is `abort(404)`, which is §6.4's authorization state, not §6.3's "article fetch failure keeps navigation usable." §6.3 (error) is unimplemented, not merely untested. The two genuinely missing states are **error (§6.3) and responsive (§4.3)**. Of the 8 implemented states, real test coverage as of this retrofit: **covered** — empty (no result), empty (empty category), authorization, duplicate/retry-safe, success (closed by this retrofit's fix wave — publish/unpublish/reorder now send and assert a real notification); **not covered** — loading (skeleton markup exists, no test asserts it), provider unavailable (implemented as a real `try`/`catch` fallback, genuinely reachable for a search-specific failure, but has no test — see note below), pending (no admin UI state to assert), support (asserted on one seeded article only, not "every article" as the row requires). Implementing §6.3 is new UI-state construction, out of a review-and-fix retrofit's scope — ledgered as backlog. The provider-unavailable branch is not tested because `FaqPublicQuery::search()` is a `static` method with no injectable seam in the current design; writing a real failure-injection test needs that seam added first, which is a structural change beyond a bounded fix wave — also ledgered.
- [x] Use `--container-prose` for article body; never full-bleed paragraphs.
- [ ] Verify accessibility (design-system.md §7): 16 px input floor, focus ring, 44 px targets, `lang="id"`. — token/class-level compliance only (16px floor, focus-ring classes, `lang="id"` on the layout); **not verified with a real browser/axe/screen reader**

  **Correction, 09 Aug 2026 (retrofit-faq):** this framed the browser as the only blocker. It is not — this repository asserts accessibility *markup* (aria attributes, `lang`, focus classes) in ordinary feature tests with no browser at all in three other modules (`tests/Feature/Livewire/Public/Marketplace/MarketplaceIndexRouteTest.php:237-247`, `tests/Feature/Livewire/Public/Booking/BookingWizardAccessibilityTest.php:24,32`, `tests/Feature/FeatureGate/GateClosedBladeComponentsRenderTest.php:33,46`). The Faq suite had zero such assertions despite the views emitting real `aria-label`/`aria-live`/`role="status"` attributes. **This retrofit's fix wave closed the markup-layer half** (`tests/Feature/Livewire/Public/Faq/{FaqIndexRouteTest,FaqArticleDetailRouteTest}.php` now assert the real attribute values). The browser/axe/screen-reader layer remains genuinely blocked — no Dusk/Playwright/Cypress/Panther/Puppeteer/Selenium/axe exists anywhere in this repository (re-verified on this branch: `composer.json`, `package.json`, both lockfiles, and a repo-wide file/content grep all return nothing) — and stays ledgered as a program-level gap, matching the same finding the pilot retrofit (`CemeteryDirectory`+`CemeteryCapability`) recorded independently.
- [x] Confirm Filament FAQ resource inherits tokens (design-system.md §8.3), not its own palette.
