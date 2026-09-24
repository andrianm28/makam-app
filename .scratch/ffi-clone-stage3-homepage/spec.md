## Problem Statement

Stage 1 (Foundation — PR #346) rebased every `tokens.css` colour/typography
value to FFI's palette. Stage 2 (Components — PRs #347/#348/#349) shipped
`<x-mk.skeleton>`, `<x-mk.bottom-nav>` (approved by ADR-0044), and the
icon-medallion tone rename, updating the `x-mk.*` component library to those
values. Neither stage touched a single Blade view's *structure*: no real
screen renders `<x-mk.bottom-nav>` or `<x-mk.skeleton>` yet, the homepage's
section order and content are still the pre-FFI arrangement, and the three
PRD-required secondary CTAs (Perpanjang Makam, Layanan Pemakaman, Wakaf
Tanah) and the "lokasi terverifikasi"/"harga transparan" trust element are
not yet in the position the PRD and the approved FFI design doc call for.

The owner cannot yet judge "does this look and feel like FFI" from a real
page, because no real page has been restructured — only the underlying
tokens and components have changed. Stage 3 closes that gap: restructure the
homepage into FFI's section order and content treatment, wire the two
unwired Stage 2 components into the live site, and apply Stage 1/2's now-
complete visual system to the rest of the public surface without changing
those pages' structure.

## Solution

1. **Restructure `resources/views/livewire/public/home-page.blade.php`**
   into the section order and content approved in
   `docs/superpowers/specs/2026-09-22-ffi-full-visual-clone-design.md` §4.1:
   keep the urgent-mode banner, hero (unchanged CTA/heading content),
   plot-availability preview, and the 4-card services section (FFI's tile
   treatment, still 4 cards); add the three secondary-CTA text links below
   the services grid (§4.2's placement, reusing the exact "Lihat TPU & TPS"
   precedent already documented in this file); replace "How it works" and
   the old "Featured cemeteries"/"Trust/safety" pair with three
   FFI-restyled TPU/TPS sections — urgent-availability, newest-published,
   and featured/verified (carrying the relocated trust badges) — built from
   Makam's own real cemetery data, never FFI's literal component code; keep
   family warmth, FAQ highlights, and the customer-service CTA; drop the
   `PrayerWall`-equivalent entirely (no Makam domain equivalent, already
   decided in the design doc).
2. **Wire `<x-mk.bottom-nav>` into `resources/views/layouts/app.blade.php`**
   — the single shared layout every public Livewire page already renders
   through — following the exact pattern `<x-mk.header>`'s own `active` prop
   already uses (`:active="$bottomNavActive ?? null"`, each page passing its
   own value via `->layout('layouts.app', [...])`). This is the Stage 3
   wiring ADR-0044 and `design-system.md` §3.11's "As implemented" paragraph
   both already name as pending.
3. **Replace the three existing hand-rolled loading-placeholder sites**
   (`renewal/start.blade.php` ×2 locations, `directory/index.blade.php` ×1,
   `faq/index.blade.php` ×1 — all `<div class="... bg-[var(--mk-skeleton-
   base)] animate-pulse">`) with real `<x-mk.skeleton>` instances, and use
   it for any new loading state the homepage restructure introduces.
4. **Apply Stage 1/2's visual system to the remaining public pages**
   (booking wizard, renewal, marketplace, FAQ, akun, cemetery directory,
   help centre/bantuan) — no structural change, no step-count change, no
   copy change; these pages already inherit most of the new palette and
   typography automatically since Stage 1 rebased token *values* under
   unchanged *names*, so this is primarily an audit for stragglers (any
   remaining hardcoded old-looking value, any component instance still on
   a Stage-2-superseded API) rather than a rewrite.

## User Stories

1. As a first-time visitor comparing Makam.co.id to the FFI reference site,
   I want the homepage's section order, card treatment, and navigation
   pattern to visibly match FFI's, so that the "buatkan ini seperti FFI"
   direction reads as delivered, not partially applied.
2. As a mobile visitor on any public page, I want a persistent 5-tab bottom
   navigation bar, so that I can reach Beranda, Pemesanan, Perpanjangan,
   Akun, and Bantuan without opening a menu.
3. As a mobile visitor who needs Layanan Pemakaman or FAQ, which the 5 tabs
   don't cover, I want the existing hamburger menu still reachable, so that
   I lose no navigation capability the bottom nav doesn't replace.
4. As a visitor on the homepage, I want to see availability-urgent TPU/TPS,
   newest-published TPU/TPS, and featured/verified TPU/TPS as three
   distinct sections, so that I can find the kind of listing I actually
   want faster than scanning one generic "featured cemeteries" grid.
5. As a visitor, I want the "lokasi terverifikasi" and "harga transparan"
   trust badges visible in the first screenful of real content, matching
   the PRD's "elemen kepercayaan di area pertama" requirement, so that I
   trust the platform before I scroll far.
6. As a visitor who wants to renew a grave lease, browse funeral services,
   or donate land (wakaf), I want a direct link to each from the homepage
   without it competing with the hero's one primary CTA, so that all three
   PRD-required secondary destinations are one click away.
7. As a visitor on a slow connection, I want a real shimmering skeleton
   placeholder while a section's data loads, not a blank gap or a
   plain-grey box, so that the page feels responsive and intentional.
8. As a screen-reader user, I want the bottom navigation's landmark,
   active-tab state, and every restructured section's heading hierarchy to
   remain correct after the restructure, so that the visual change doesn't
   regress my ability to navigate the page.
9. As a keyboard-only user, I want every new/moved interactive element
   (secondary CTA links, bottom nav tabs, restructured section cards) to
   remain reachable and visibly focused in the same tab order logic the
   page already uses, so that the restructure doesn't strand me anywhere.
10. As a visitor with `prefers-reduced-motion` set, I want the new skeleton
    shimmer and any other new animation to respect that setting, matching
    `<x-mk.skeleton>`'s own already-built reduced-motion behaviour.
11. As the product owner, I want the four-primary-service-card rule,
    `AGENTS.md`'s navigation invariants, and every one of the ten mandatory
    states (`design-system.md` §6) preserved on every touched screen, so
    that this visual restructure never becomes a silent regression of an
    already-decided product rule.
12. As a visitor on `/faq`, `/perpanjangan`, `/marketplace`,
    `/pemesanan-makam`, `/akun`, `/pemakaman`, or `/bantuan`, I want the
    same FFI-derived palette, card, button, and icon-medallion treatment
    the homepage now has, so that no public page feels visually
    inconsistent with the rest of the site.
13. As a developer touching this codebase later, I want the three existing
    hand-rolled skeleton placeholders replaced by the real component, so
    that there is exactly one loading-placeholder implementation to
    maintain, not four.
14. As the product owner, I want the FAQ highlights and the new
    featured/verified TPU/TPS section to absorb the "how it works" and
    "trust/safety" copy the restructure displaces, so that no existing
    explanatory content is silently deleted, only relocated.
15. As a visitor, I want the homepage's real `PrayerWall`-adjacent content
    (there is none in Makam's domain) to simply not exist, rather than a
    reskinned donation-site element with no honest Makam equivalent.
16. As an operator monitoring the real CI pipeline, I want
    `ci/verify-docs.sh`, `blade:verify-content-survival`, and the ten-
    mandatory-states check to stay green on every touched screen after
    this restructure, so that a passing pipeline still means what it did
    before.
17. As a visitor using the site with JavaScript disabled, I want the
    restructured homepage and the newly-wired bottom nav to remain usable
    (plain anchor links, no JS-only navigation), matching `<x-mk.header>`'s
    own `§6.10` no-JS-dependency precedent.

## Implementation Decisions

- **Homepage section order** — per the design doc's §4.1 table exactly:
  urgent-mode banner (unchanged) → hero (unchanged content, restyled) →
  secondary-CTA text links (new, §4.2 placement) → plot-availability
  preview (kept, restyled) → services, 4 cards (FFI tile treatment, restyled,
  count unchanged) → urgent-availability TPU/TPS (new, FFI `UrgentCampaigns`
  visual pattern, Makam data) → newest-published TPU/TPS (new, FFI
  `CampaignGrid` visual pattern, Makam data) → featured/verified TPU/TPS,
  carrying the relocated trust badges (new, FFI `CampaignGrid` visual
  pattern, Makam data) → family warmth (kept, restyled) → FAQ highlights
  (kept, restyled, absorbs redistributed "how it works" content) →
  customer-service CTA (kept, restyled). No `PrayerWall`-equivalent section.
  "Restyled"/FFI visual pattern always means: re-implement the section as
  Blade/Livewire markup carrying FFI's spacing/card/typography treatment
  against Makam's own real data and `App\Livewire\Public\HomePage`'s own
  queries — never importing or transliterating FFI's literal React/JSX
  source.
- **Secondary CTA links** — plain text links (not buttons, not inside
  `<x-mk.hero>`, which structurally supports no more than one CTA) placed
  below the services card grid, reusing the exact mechanism and visual
  weight `home-page.blade.php`'s own comment already documents for the
  prior "Lihat TPU & TPS" link. Three links: Perpanjang Makam
  (`/perpanjangan`), Layanan Pemakaman (`/marketplace`), Wakaf Tanah (route
  TBD at plan time — no existing route for this destination was found in
  `routes/web.php`; the implementation plan must either locate one or flag
  this as a real, named gap rather than inventing a URL).
- **Trust element relocation** — "lokasi terverifikasi" (active capability
  profile, evidence present — already-decided definition, unchanged) and
  "harga transparan" move into the featured/verified TPU/TPS section
  (position 7), ahead of where trust content sat in the old order,
  satisfying the PRD's "area pertama" requirement per the design doc's §7
  compliance table.
- **`<x-mk.bottom-nav>` wiring** — added to `layouts/app.blade.php`
  immediately alongside `<x-mk.header>`, using the identical
  `:active="$bottomNavActive ?? null"` / `->layout('layouts.app',
  ['bottomNavActive' => '<tab-key>', ...])` pattern the header's own
  `active` prop already establishes there — not new architecture, an
  extension of an existing one. Every public page passes its own pair of
  values: `active` (one of `header.blade.php`'s four keys or `null`) for
  the header, and `bottomNavActive` (one of `bottom-nav.blade.php`'s five
  keys or `null`) for the bottom nav — the two prop vocabularies are
  disjoint (header has `layanan`/`faq`, bottom nav has `beranda`/`akun`)
  because the four desktop items and the five mobile tabs are not the same
  set; a page maps onto whichever of the two vocabularies actually names
  it, and `null` on the other. Per ADR-0044 Amendment 1, the hamburger
  menu stays wired exactly as it is today — this stage does not touch
  `<x-mk.header>`'s own markup, only adds `<x-mk.bottom-nav>` beside it.
  Any sticky wizard CTA that coexists with the bottom nav on a wired page
  must respect the already-documented `z-sticky-cta` < `z-bottomnav`
  stacking order, and page content on every wired page must be padded by
  `--mk-bottomnav-total`, never overlapped by the now-live fixed bar —
  both requirements already named in `design-system.md` §3.11, exercised
  for real for the first time by this stage.
- **`<x-mk.skeleton>` wiring** — replace the three existing hand-rolled
  `bg-[var(--mk-skeleton-base)] animate-pulse` placeholder sites
  (`renewal/start.blade.php` ×2, `directory/index.blade.php` ×1,
  `faq/index.blade.php` ×1) with real `<x-mk.skeleton>` instances, choosing
  the closest predefined `shape` (`text`/`card`/`media`/`section`) per
  site. The component's four shapes have fixed heights (`h-4`, `h-40`,
  `aspect-video`, `h-64`) that do not exactly match every existing
  hand-rolled height (`h-16`/`h-20`/`h-28`/`h-72` are among the sites
  found) — this is a real, small visual-height delta at each retrofit
  site, accepted here as the cost of having one real loading-placeholder
  implementation instead of four hand-rolled ones; the implementation plan
  picks the closest shape per site and does not attempt pixel-exact height
  parity with the value it replaces.
- **`FFI's` `QuickActionTiles`/`UrgentCampaigns`/`CampaignGrid` visual
  patterns** are read from the FFI reference repository for spacing/card/
  typography treatment only (the same "read, don't import" approach ADR-
  0043 and Stage 1/2 already established) — the implementation plan must
  re-verify the exact current FFI markup at plan-writing time rather than
  trusting this spec's paraphrase, the same discipline Stage 2's own spec
  applied to icon provenance.
- **Ten mandatory states** (`design-system.md` §6.1–§6.10) — every touched
  screen keeps whichever of the ten already applied to it before the
  restructure (e.g. the homepage's existing Empty/Provider-unavailable/
  Gated-fallback-banister states for plot-availability, featured
  cemeteries, FAQ highlights, and urgent mode), re-verified against the
  new markup, not assumed to survive automatically.

## Testing Decisions

- **The seam for every touched public page is its existing HTTP-level
  route feature test**, named explicitly per page — this repo already has
  exactly one such test file per public route, all following the same
  `$this->get('<route>')` + `RefreshDatabase` + `withoutVite()` pattern
  (confirmed by reading each file directly, not assumed):
  - Homepage: `tests/Feature/Livewire/Public/HomePageRouteTest.php`
    (`$this->get('/')`) — the primary seam for the restructure itself:
    section order, secondary-CTA links, relocated trust badges, the three
    new TPU/TPS sections, and the wired bottom nav's presence/active-tab
    state.
  - Booking wizard: `tests/Feature/Livewire/Public/Booking/
    BookingWizardRouteTest.php`
  - Marketplace: `tests/Feature/Livewire/Public/Marketplace/
    MarketplaceIndexRouteTest.php`
  - Cemetery directory: `tests/Feature/Livewire/Public/Directory/
    CemeteryDirectoryIndexRouteTest.php`
  - FAQ: `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php`
  - Akun: `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php`
  - Help centre: `tests/Feature/Livewire/Public/Support/
    HelpCentreRouteTest.php`
  - Renewal start: `tests/Feature/Livewire/Public/Renewal/
    RenewalStartTest.php`
  This is the highest, already-established seam for every page in scope —
  no new seam type is proposed. The bottom-nav *component's own* behaviour
  (tab rendering, active-tab marking, accessibility) is already covered by
  `tests/Feature/View/Components/MkBottomNavTest.php` from Stage 2; this
  stage's tests only need to assert the component is actually *present* on
  each wired page's rendered HTML with the correct `active` tab, not
  re-test the component's internals.
- **What makes a good test here**: assert real rendered HTML content
  (section presence, heading text/order, link hrefs, `aria-current`
  placement) against real seeded data, the same discipline
  `HomePageRouteTest`'s own existing tests already use — never mock gate
  state, never fabricate fixtures, matching this file's own doc comment
  ("against real seeded data — never mocked gate state or fabricated
  fixtures").
- **Prior art**: `HomePageRouteTest.php`'s own existing test methods
  (`test_all_four_menus_appear_in_ac1s_exact_order`,
  `test_homepage_sections_alternate_surfaces_without_divider_lines`,
  `test_urgent_banner_is_absent_when_g_ops_01_is_open`) are the direct
  template for the new section-order and relocated-content assertions this
  stage adds to the same file. `MkBottomNavTest.php` (Stage 2) is the
  template for any bottom-nav-specific assertion needed elsewhere.
- **`blade:verify-content-survival`** and the ten-mandatory-states check
  (design doc §8, Stage 3 row) must pass for every touched screen, run for
  real, not assumed — same discipline this repo's own CI already enforces
  via `ci/verify-docs.sh` and the dedicated artisan command.

## Out of Scope

- **Filament admin/operator/vendor panels** — untouched, same boundary
  every prior FFI stage held.
- **Step structure and flow of the booking wizard, renewal, and
  marketplace** — visual system only; step count, field rules, and
  validation are unchanged (design doc §1).
- **Copy voice** — ADR-0041 pages 01–03 stay authoritative; no FFI
  donation-site vocabulary anywhere in Makam (design doc §1).
- **`AGENTS.md`'s four-primary-service-card rule** — not amended.
- **The hero CTA label mismatch** ("Pesan Makam" vs PRD's "Cari Makam") —
  a wording decision independent of this visual work, explicitly left open
  by the design doc §6 for whoever owns that PRD follow-up.
- **Whether `information-architecture.md` §3's "nine normative sections"
  count gets formally updated** to reflect the plot-availability-preview
  and family-warmth sections (already present before this stage, not
  introduced by it) — not blocking, noted for whoever next touches that
  document (design doc §6).
- **Exact icon/illustration set for the four service cards** under FFI's
  tile treatment — an asset decision for the implementation plan, not a
  structural one (design doc §6).
- **Wakaf Tanah's real destination route** — no such route exists in
  `routes/web.php` today; the implementation plan resolves this (find the
  real route, or flag the gap) rather than this spec inventing one.
- **Any `tokens.css` value or new token** — Stage 1 is closed; this stage
  consumes existing tokens (`--mk-z-bottomnav`, `--mk-bottomnav-total`,
  `--mk-skeleton-base`, etc.), it does not add or change one.
- **`design:verify-filament-palette`** must stay green, unmodified,
  proving Filament truly isn't touched by this stage either (design doc
  §8).

## Further Notes

- **This is larger than one implementation plan.** It bundles a genuinely
  structural change (homepage restructure) with a cross-cutting wiring
  change (bottom nav into the shared layout) and a multi-page visual audit
  (wizard/renewal/marketplace/FAQ/akun/directory/bantuan). Per this repo's
  own pipeline, this spec should go through `/specflow:to-tickets` next,
  not straight to `/specflow:spec-to-plan`. Natural slice candidates
  visible from this exploration (for `/specflow:to-tickets` to size and
  sequence, not decided here): (a) the homepage restructure itself
  (section reorder, secondary CTAs, trust relocation) — the one ticket
  with real product-visible risk; (b) `<x-mk.bottom-nav>` + generalized
  `active`/`bottomNavActive` wiring into `layouts/app.blade.php`, shared
  by every public page in one seam; (c) the three-site `<x-mk.skeleton>`
  retrofit, small and mechanical; (d) a visual-consistency audit pass
  across the seven non-homepage public pages, expected to be mostly
  verification rather than rewriting given Stage 1's token-value rebase
  already reaches most of them automatically.
- **Upstream authority chain**: `docs/superpowers/specs/
  2026-09-22-ffi-full-visual-clone-design.md` §4 (homepage restructure),
  §4.1 (section mapping), §4.2 (secondary CTA placement), §5 (three-stage
  sequencing — this spec covers the stage named "Homepage restructure"),
  §6 (explicitly not decided), §7 (PRD compliance), §8 (verification per
  stage). ADR-0043 remains the authority for the underlying FFI-adoption
  decision; ADR-0044 remains the authority for the bottom nav's existence
  and its five tabs, amended (Amendment 1) to also cover hamburger
  coexistence.
- **Branch state note**: this spec is written on `docs/ffi-clone-stage3-
  homepage`, branched from `docs/ffi-clone-stage2-components` after all
  three Stage 2 PRs (#347, #348, #349) were confirmed merged into it. That
  branch itself is not yet merged into the repository's actual default
  branch (`docs/design-system-and-planning`) — a pre-existing state from
  before this stage, not something this spec resolves.
