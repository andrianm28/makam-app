# Homepage Visual Refresh — More "Interaktif" Within the Existing Design System

## Context

The user wants the public homepage to feel more interactive/lively, loosely benchmarked
against two sites: **kamboja.co.id** (a real funeral-services competitor, fetched live this
session — muted earthy palette, dual-CTA hero, alternating section bands, icon-led service
cards, trust signals) and **kitabisa.com** (Indonesia's best-known donation platform, named
for its "motifs and colors" and general energy). The explicit instruction was not to copy
either site too literally — "switch up the layout a bit" — and the user confirmed (via
clarifying question) that this means **restyling the existing homepage sections, not
reordering or adding/removing them**.

**Kitabisa caveat, stated once, binding for the whole plan:** kitabisa.com returned HTTP 403
on every fetch attempt this session, including via web.archive.org. Nothing below depends on
kitabisa specifically — every concrete visual decision traces to `kamboja.co.id` (verified
live), to this repo's own already-approved tokens, or to `design-system.md`'s own written
rules. Kitabisa's *reputation* (vibrant green, rounded cards, energetic donation-platform feel)
is real background context, not a verified source, and its actual devices — gradients, pill
buttons, progress bars, donor avatar stacks, celebration motion — are directly incompatible
with this platform's documented tone and are not being borrowed.

**The real tension driving this plan:** `docs/design/design-system.md` §2.1–2.3 deliberately
bans exactly the things that usually make a site feel "interactive" — gradients on interactive
surfaces, pill-shaped buttons, celebratory/attention-seeking motion, testimonials-as-decoration,
transform-based hover. The tone target is "a well-run public office staffed by kind people —
calm, unhurried, unmistakably competent, never selling," which is correct for a bereavement
platform and should not be abandoned for a livelier feel. The resolution used throughout this
plan: **rhythm, not energy.** The homepage today is not calm, it is *undesigned* — eight
sections, seven of them plain white, one background tint used once, zero icons anywhere, hand-
rolled boxes where a card primitive already exists. Composing the page properly with the
ingredients the design system already ships (a full tint scale on two brand colour families,
18 committed icons, a card primitive with an underused hover state) reads as noticeably more
alive without touching a single banned device.

Palette note: the tokens **already lean green/brown** — Earth (warm brown, primary) and Leaf
(green, secondary), adopted 17 Aug 2026 via ADR-0034. This plan does not introduce a new color
tone; it uses more of the tint range (50–300) that already exists, which is what makes the
result read "lighter/younger" as asked, with no new token and no new ADR required.

## Governance decisions (made once, upfront — do not relitigate per-component)

| Question | Decision | Reason |
|---|---|---|
| New design token? | **No.** | Every value needed (`primary-50/100/800`, `secondary-50/100/400/800`, `radius-xl`, `shadow-md`, `duration-fast`, `ease-standard`, `tracking-wide`, `text-5xl`) already exists in `tokens.css`. |
| New ADR? | **No.** | §9.4 requires an ADR only to *add or change* a token. This plan only uses existing, already-approved values. |
| Leaf green as an icon-medallion tile background? | **Yes, permitted — with one clarifying sentence added to the docs in the same PR.** | §1.2's cage already permits Leaf as "surface tint 50–200, text 700–900." A `secondary-100` tile with a `secondary-800` icon is exactly that pairing, and `verify-contrast.py` already asserts `secondary-800 on secondary-100` as a required pair — meaning this combination was already anticipated. §9.2 MUST-NOT #7 bans Leaf as "a fill, badge, button, or alert" specifically because it reads as the `success` status colour; a decorative, `aria-hidden` marketing tile with no status meaning, never next to order/payment data, doesn't trigger that risk. Document this boundary explicitly rather than leave it to be re-derived later. |
| Hover "lift" (transform)? | **No.** | design-system.md §5's normative interaction table states, for hover: "Transform: none." The livelier hover comes from adding a background-colour tint to the existing border+shadow hover state — colour-only, no rule change needed. |
| Decorative motif from the brand's 8-leaf mark? | **No, not yet.** | The mark exists only as a 96px raster (`public/brand/mark-*.png/webp`); there is no vector source (OQ-12 still open per ADR-0034). Upscaling a 96px raster for a hero background would look soft, and hand-redrawing brand artwork would be fabricating a mark that isn't ours to invent. Use the already-sanctioned decorative device instead: a short `secondary-400` accent rule (§1.2's "decorative rule/icon" usage). Revisit a real leaf-motif treatment once OQ-12 closes with a real vector. |
| Circular icon badges (pill/circle)? | **No.** | `tokens.css` §1.7 restricts `radius-full` to "avatar, stepper dot, progress track only." Use `rounded-xl` squircle tiles instead — which also keeps the result visually distinct from kitabisa's circular badges, consistent with "don't copy too literally." |
| Fabricated stats, counters, or testimonials? | **No, never.** | No real data exists for either (no reviews, no ratings, no usage counts). This codebase has a consistent anti-fabrication discipline everywhere else (see `HomePage::render()`'s own doc block on why even the *existing* fictional cemetery rows required explicit user authorization and honest labelling). Inventing "500+ keluarga terbantu" style copy for decoration would break that discipline. §2.2 explicitly lists "testimonials-as-decoration" as a banned trust-signal anti-pattern. |

## What actually changes

### 1. New component — `resources/views/components/mk/icon-medallion.blade.php`

An icon-or-numeral-on-a-tinted-tile figure, following the exact conventions every existing
`components/mk/*` file uses (long doc-block header explaining rationale/precedent, `@props`
with typed defaults, one PHP block composing classes once).

```
@props([
    'icon' => null,   // icon.* component name; omit and use the slot for a numeral instead
    'tone' => 'earth', // 'earth' | 'leaf' — closed list, same defensive pattern as button's $variant
    'size' => 'md',    // 'md' (44px / size-11) | 'lg' (size-13)
])
```

Doc block must state: this uses only already-contrast-asserted pairs (`primary-800` on
`primary-100`, `secondary-800` on `secondary-100`); it is `rounded-xl`, never `rounded-full`
(§1.7); it is always `aria-hidden="true"` and never substitutes for a real text label (same
rule `badge.blade.php` states for its `dot` prop); and — critically — the two `$tones` entries
must be **static, complete, literal class strings**, never built by interpolating `$tone` at
render time. `card.blade.php` and `badge.blade.php` both carry scars from getting this wrong
once (Tailwind's `@source` scanner reads file text for literal class-shaped strings; an
interpolated class is invisible to it and silently compiles to nothing) — read `card.blade.php`
lines 62–78 before writing this file's `$tones` array.

### 2. `resources/views/components/mk/card.blade.php` — enrich the interactive hover state

Currently (`$interactiveClasses`, lines 92–96): transitions `[border-color,box-shadow]`,
`hover:border-primary-300 hover:shadow-md`. Add `background-color` to the transitioned
properties and `hover:bg-primary-50`, **gated on `$intent === null`** so status-intent cards
(`neutral`/`info`/`pending`/`success`/`danger`/`urgent`) keep their own surface untouched. This
is the single highest-leverage change — every interactive card site-wide (directory,
marketplace, FAQ, homepage) gets the livelier hover in one place, matching §9.2 MUST #2 ("extend
primitives rather than forking"). Update the doc block; update design-system.md §3.3's
Interactive recipe line to match, since the component quotes that line as its source of truth.

### 3. Two real defects to fix in the same pass (found during exploration, not cosmetic)

- **Trust band uses the wrong token.** `home-page.blade.php` line ~223 uses `bg-secondary-50`
  with a comment claiming that traces to `--mk-surface-warm`. Since ADR-0034,
  `--mk-surface-warm` maps to `--color-primary-50`, and design-system.md §2.3/§4.5 item 6
  explicitly say to use it (Earth, not Leaf) for trust/reassurance sections. Fix to
  `bg-primary-50` — this also produces a better two-tone rhythm once Leaf tint moves to the
  Cara Kerja band instead.
- **Featured-cemetery cards are dead ends that shouldn't be anymore.** Section 5's doc block
  says cards are deliberately not links because the cemetery detail route "has not been built
  yet." It has — `routes/web.php` registers `cemeteries.show`, and
  `resources/views/livewire/public/directory/index.blade.php` already renders this exact
  pattern (`<x-mk.card as="a" interactive :href="...">`). Wire the homepage's featured cards to
  the same route, using `CemeteryPresenter::photoUrl()`/`priceRange()`/`priceAttribution()` (the
  directory page's own pattern) so price figures never render without their source — the
  homepage currently prints `Rp {min}–{max}` with no attribution, which §2.3's DO
  ("show the source and last-updated time on any fee figure") requires and the directory page
  already does correctly. Add a `<x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>`.
  Deliberately skip the availability badge the directory shows (needs a per-row
  capability-profile lookup; not worth the homepage's query budget for six cards).

### 4. Section-by-section restyle (order unchanged — verified against `HomePageRouteTest`, see Verification)

| # | Section | Change |
|---|---|---|
| 1 | Urgent fallback alert | No change. |
| 2 | Hero | Full-bleed `bg-primary-50` band (contained inner `max-w-content`, same pattern the footer already uses). Add an uppercase eyebrow (`text-sm tracking-wide text-primary-700` — `--tracking-wide`'s own token comment says "uppercase eyebrow"). Promote `h1` to `text-4xl md:text-5xl` (the scale tokens already label "hero mobile"/"hero desktop"). Add a short `h-1 rounded-sm bg-secondary-400` decorative rule under the eyebrow (the one sanctioned Leaf-as-decorative-accent moment, and the page's one green "motif"). Replace both hand-written button class blobs with `<x-mk.button variant="primary" size="lg" href="/pemesanan-makam">Pesan Makam</x-mk.button>` plus a new secondary CTA `<x-mk.button variant="secondary" size="lg" :href="route('cemeteries.index')">Lihat TPU & TPS</x-mk.button>` — dual CTA matches kamboja's hero pattern while staying inside §2.3's "exactly one primary action." **Copy unchanged** — verified `<x-mk.button>` renders `href="{{ $href }}"` and the slot text verbatim, satisfying `HomePageRouteTest::test_pemesanan_makam_is_the_primary_call_to_action`'s literal `assertSee('Pesan Makam')` / `assertSee('href="/pemesanan-makam"')`. |
| 3 | Four service cards | Add `<x-mk.icon-medallion tone="earth">` above each card's `h3` (document-text / truck / clock / question-mark-circle — all 4 icons already exist). Cards get the new hover tint automatically via the card.blade.php change. Order (`HomePage::PRIMARY_MENUS`) untouched. |
| 4 | Cara Kerja | Promote to full-bleed `bg-secondary-50` band (the Leaf tint moves here from the trust band). Replace the hand-rolled bordered `<li>` with `<x-mk.card>` (non-interactive) per §9.2 MUST #2. Each card's numeral moves into `<x-mk.icon-medallion tone="leaf">{{ $index + 1 }}</x-mk.icon-medallion>` (slot, no icon — a number is the right affordance for an ordered list). Keep `<ol>` and all four step titles/bodies verbatim. |
| 5 | TPU & TPS Unggulan | See item 3 above (real links, badge, attributed pricing). |
| 6 | Trust "Kenapa Makam.co.id" | Band fixed to `bg-primary-50` (item 3 above). Add `<x-mk.icon-medallion tone="leaf">` above each of the 3 points (shield-check / check-badge / alert-circle — all exist) — Leaf tiles on the warm Earth band is the page's duotone moment. Left-align the stack (currently centred). Copy unchanged. |
| 7 | FAQ highlights | No per-card icon (four question-mark tiles in a row is noise, not rhythm). Cards inherit the new hover tint. Upgrade "Lihat semua FAQ" to `<x-mk.button variant="secondary">`. Both degraded-state branches (unavailable / empty) keep their exact existing copy — `HomePageRouteTest` asserts "Pertanyaan populer sedang tidak tersedia" verbatim. |
| 8 | CS CTA | Restyle as a contained `bg-primary-50 border-primary-200` panel with `<x-mk.icon-medallion icon="question-mark-circle" tone="earth" size="lg">` above the heading. Replace the hand-written button with `<x-mk.button variant="secondary" href="/bantuan">`. Contact data still resolves only through `App\Support\ContactInfo`. Copy unchanged (`HomePageRouteTest::test_customer_service_cta_is_present`). |

Resulting band rhythm top to bottom: **warm (Earth) → white → leaf (Leaf) → white → warm (Earth)
→ white → warm panel.**

## What is explicitly NOT being built, and why

- Fabricated stats/counters/testimonials/ratings — no real data exists; see governance table.
- Gradients anywhere — banned by §2.2/§2.3, and any gradient stop would be a hardcoded hex,
  which `ci/verify-docs.sh` GATE 2 rejects outright.
- Pill/circular buttons or badges — banned by tokens.css §1.7 and §2.3.
- Confetti, count-up animation, scroll-reveal, parallax, carousels, accordions — banned by
  §2.3 ("no celebration"), and there are zero JS dependencies in `package.json` beyond the
  Alpine that ships transitively with Livewire 4 (used in exactly two places today). Adding a
  new Alpine interaction pattern is its own decision with its own ADR, not a side effect of a
  visual refresh — everything in this plan is pure CSS/Blade.
- Transform-based hover lift — banned by §5's normative table ("Transform: none").
- A hero photo/image band — no real cemetery photography exists yet (only fictional
  illustration SVGs, already doing double duty as fake cemetery photos in section 5 — reusing
  one in the hero would misrepresent a specific real place). If real, permitted photography of
  partner TPU/TPS becomes available later, a hero image is a good follow-up within the existing
  performance budget (≤120KB AVIF/WebP, explicit dimensions, CLS < 0.1).
- A hand-drawn 8-leaf brand motif — no vector source exists yet (OQ-12 open); see governance
  table.
- Copying kitabisa's actual copy, layout, or graphics — never independently verified this
  session, and its donation-platform energy is tonally wrong for a bereavement platform anyway.
- Reordering, adding, or removing homepage sections — confirmed out of scope with the user;
  §4.5 documents section order as a product contract, and `HomePageRouteTest` enforces service-
  card order positionally.

## Files touched

**Create:**
- `resources/views/components/mk/icon-medallion.blade.php`

**Modify:**
- `resources/views/components/mk/card.blade.php` — hover background tint (intent-null only)
- `resources/views/livewire/public/home-page.blade.php` — section-by-section restyle above;
  doc-block corrections for the two defects in item 3
- `docs/design/design-system.md` — §3.3 Interactive recipe line; one clarifying sentence on
  §1.2/§9.2 MUST-NOT #7's medallion boundary; §7.1 contrast table gains the new pairs; add
  `<x-mk.icon-medallion>` to the component inventory (§3 / §10 quick reference)
- `docs/design/verify-contrast.py` — **add** (never weaken existing assertions) pairs for
  text-on-`secondary-50`, text-on-`secondary-50` (strong), and focus-ring-on-`primary-50`
- `ci/verify-docs.sh` — GATE 1's pass message hardcodes a pair count; update it to match
- `docs/product/screen-inventory.md` — note PUB-001's featured cemeteries now link to a real
  detail route (a real affordance change, not just styling)

**Not touched:** `resources/css/tokens.css` (no new token), `resources/views/layouts/app.blade.php`
(the only file with drift from local HEAD vs. origin — the beta banner lives there; stay out of
it entirely), `app/Livewire/Public/HomePage.php` (no query changes — presenters called from the
view exactly as the directory page already does it), `package.json`, any `icon/*.blade.php`
(all needed icons already exist).

## Branch / worktree / PR plan

Base off **`origin/docs/design-system-and-planning`**, not local HEAD — local is missing PRs
#96–#109 (async spine, observability, the beta banner and its hard-won skip-link-ordering fix,
CSP, rate limits, the payment sandbox warning, the noindex middleware, and the live cutover to
makam.co.id, all merged this session). Building from local HEAD would silently drop all of it.

```
git fetch origin
git worktree add .worktrees/home-visual-primitives -b lane/home-visual-primitives origin/docs/design-system-and-planning
```

**Two PRs, sequential:**
1. `lane/home-visual-primitives` — the shared-blast-radius layer: `icon-medallion.blade.php`,
   `card.blade.php`, `design-system.md`, `verify-contrast.py`, `ci/verify-docs.sh`. The
   `card.blade.php` change alone restyles every interactive card site-wide (directory,
   marketplace, FAQ), so it deserves independent review and its own revert unit, separate from
   whatever iteration the homepage's specific look goes through.
2. `lane/home-visual-refresh` — page integration: `home-page.blade.php`,
   `screen-inventory.md`. Branch after PR 1 merges (`git fetch && git rebase
   origin/docs/design-system-and-planning`).

No separate governance/ADR PR — there is no token change, so nothing to carve out. Ledger at
`.superpowers/sdd/home-visual-refresh/progress.md` inside the worktree per this repo's
Superpowers SDD convention.

**If item 5's cemetery-linking change grows uncomfortable in review**, split it into its own
third PR — it stands alone cleanly (it's arguably an honesty bugfix, not styling) and doesn't
need to block the rest of the visual refresh.

## Verification

**Runs on this host directly (no `vendor/`, no build needed):**
1. `python3 docs/design/verify-contrast.py` — must exit 0 after the new pairs are added.
2. `bash ci/verify-docs.sh` — specifically confirm GATE 1 (contrast, new pair count), GATE 2
   (no hardcoded hex outside tokens.css — the medallion and accent-rule must be pure utility
   classes), GATE 3 (no Tailwind arbitrary values), GATE 4 (markdown links, since two docs are
   edited), GATE 11 (no raw z-index), GATE 12 (no focus suppression).

**CI-only** (host PHP is 8.3.6 vs. `composer.lock`'s ≥8.5 requirement — report these as NOT RUN
LOCALLY, never as PASS, per this repo's own "never report PASS for an unexecuted check" rule):
3. `php artisan test` — `HomePageRouteTest` is the real gate. It asserts on copy, nav order,
   and literal `href=` strings, not CSS classes, so a pure restyle is safe as long as no copy
   changes and the four service-card labels keep their order — both already verified against
   this plan's proposed markup above. Re-check `test_section_5_shows_published_dummy_cemeteries_
   and_excludes_the_draft_one` specifically once section 5's markup changes, since it asserts on
   which example cemetery names appear/don't appear.
4. `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
5. `npm run build` — confirm every new utility class actually compiled (all are standard
   generated utilities from existing tokens; nothing new to register).
6. Playwright + axe (`tests/browser/smoke.spec.ts`, asserts zero violations on `/`) — the
   highest-risk gate for this change. Before pushing, confirm every medallion/accent-rule is
   `aria-hidden="true"`, heading order stays h1→h2→h3 with no skipped level, every `<section>`
   keeps its `aria-labelledby`, the hero eyebrow is not a heading element, and the two hero CTAs
   have distinct accessible names.

**Manual, on the deployed beta stack** (composer/npm builds don't run on this host — push, wait
for CI green, then look at the real deployment): check `127.0.0.1:8083` or the now-public
`makam.co.id` at 320/360/768/1024/1280px widths. Keyboard-only tab through the page (focus ring
visible against the warm band). Toggle OS "reduce motion" and confirm hovers snap instantly
(tokens.css collapses all durations to 1ms). Click one featured cemetery card and confirm it
lands on a real `/cemeteries/{slug}` page.
