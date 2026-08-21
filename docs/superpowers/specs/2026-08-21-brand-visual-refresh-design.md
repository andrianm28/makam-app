# Brand Visual Refresh — Design Spec

## 1. Problem statement

The user's own words: make the public site feel more interactive — closer to `kitabisa.com`'s
polish, benchmarked against the direct competitor `kamboja.co.id` — with a color tone that leans
more toward green and brown, lighter/younger than today, without copying either reference site
literally ("jgn terlalu presisi banget... di-switch aja tata letak nya").

Two things ground this beyond taste:

1. **`design-system.md` already carries an open question this directly resolves.** ADR-0034
   adopted "Earth" brown (`primary`) and "Leaf" green (`secondary`) as the brand palette, but every
   value is explicitly marked **PROVISIONAL, pending OQ-12 (official brand hex, vector source)** —
   the tokens were estimated from a logo *render*, never sampled from a real source file. A real
   logo file now exists in the repo (`logos/logo makam.co.id.png`, PNG/JPEG/ICO). Re-anchoring the
   palette to this file's actual sampled colors resolves OQ-12, not just refreshes the color.
2. **The site already has the primitives this needs — they're just under-used.** `<x-mk.card>`
   already supports an `interactive` variant with hover/tint transitions; the marketplace browse
   page already uses a card grid. This is an extension of an existing pattern to more of the site,
   not an invention of a new one.

## 2. What this does NOT change

- **The calm/respectful tone stays.** `design-system.md` §2's DON'Ts (no urgency manufacturing, no
  countdown timers, no celebration/confetti, no color-only status, no gradients, no pill buttons)
  are unchanged. "More interactive" here means richer visual rhythm and warmer color — not a shift
  toward a donation-platform's urgency/celebration mechanics. This was an explicit, deliberate
  choice made during design ("fresher & warmer, same calm tone" over "genuinely more like
  kitabisa's energy").
- **Leaf green's structural cage stays.** Secondary (Leaf) remains tint/accent-only — never a
  filled button, badge, or alert. This was chosen explicitly to avoid reopening the
  green-vs-`success`-status confusion risk §1.2b already resolved, and to keep this effort scoped
  to color values and imagery rather than also re-tuning the `success` status color's hue
  separation.
- **Admin and vendor Filament panels are out of scope.** This redesign covers the public
  customer-facing site only (homepage, booking wizard, marketplace, renewal, FAQ, cemetery
  directory, visitation, memorial/QR). The admin/vendor panels are internal tools with their own
  dense, functional conventions unrelated to this effort's "warm and interactive" goal.
- **No new color families, no redesign of the status/semantic colors** (`success`/`warning`/
  `danger`/`info`) — only `primary` (Earth) and `secondary` (Leaf) are re-anchored.

## 3. Color system

### 3.1 Source of truth

`logos/logo makam.co.id.png` (the real, official logo — not a render or approximation) sampled via
the repo's own existing tool, `docs/design/brand/sample-logo-colours.php` (already written, never
previously run against a real source — this is its first real use):

```
$ php docs/design/brand/sample-logo-colours.php "logos/logo makam.co.id.png"
brown #563B26  (99797 px)
green #336B3E  (49320 px)
```

These become the new **anchor** values — replacing today's provisional `--color-primary-600:
#5D3A1F` and `--color-secondary-600: #2E7D32` in `resources/css/tokens.css`.

### 3.2 Ramp regeneration

No existing tool generates a full 11-step tint/shade ramp (50→950) from a single anchor — this
needs to be derived by hand, following the same lightness-step *pattern* the current ramps already
use (each family's 50/100/200/... progression is roughly consistent in how much lightness/chroma
changes per step; the new ramp should follow that same cadence around the new anchor, not invent a
new progression shape).

**Every generated shade must be re-verified against WCAG AA before it's finalized** — this is not
optional. Two concrete checks the current tokens.css comments already show the convention for:
- White text on any shade used as a button/fill background must clear 4.5:1 (the current
  `--color-primary-600` comment records "white label AA 10.05:1" — the new anchor's own contrast
  ratio must be computed and recorded the same way).
- Any shade used for text-on-tint (e.g. `primary-700`/`800` used as link/text color on a
  `primary-50` background) must independently clear 4.5:1 against ITS background, not just inherit
  the old shade's pass.

If the real anchor color (`#563B26`/`#336B3E`) doesn't cleanly support a full accessible ramp at
every UI-usage point, the implementer's job is to find the nearest shade that both (a) stays
visually anchored to the real logo color and (b) passes AA — not to silently ship a failing
contrast pair. This is exactly the kind of judgment call this repo's own established review
process (task-scoped review, then a dedicated design-reviewer pass) exists to catch.

### 3.3 "Lighter/younger" in real UI

The regenerated ramp's true anchor (`600`, closest to the real sampled logo color) is not
necessarily what buttons/links use day-to-day. Matching the "fresher/younger" direction, primary
actions and links should use a step lighter on the new scale (provisionally `500`, to be confirmed
during implementation once the full ramp and its contrast numbers exist) — the same idea already
discussed and agreed during design, just anchored to real sampled data instead of a guess. The true
anchor shade remains available on the scale (used e.g. for the darkest UI needs — footer, inverse
header, per the current `900`/`950` convention) so the real logo color is still directly
represented in the system, not just used as a jumping-off point.

### 3.4 Downstream regeneration

Two things depend on `tokens.css`'s exact values and must be regenerated/re-verified once the new
ramp lands, per their own existing tooling:
- `App\Support\Design\FilamentPaletteGenerator` (`php artisan design:generate-filament-palette`,
  verified via `design:verify-filament-palette`) — the admin panel's color array is generated FROM
  tokens.css, never hand-copied. Regenerating this is mechanical once tokens.css is updated, but is
  a required step, not an afterthought.
- `docs/design/design-system.md` §1.2's own color table (the hex values, hue-separation math
  between `primary`/`secondary` and the status colors) needs its numbers updated to match, and its
  "PROVISIONAL pending OQ-12" language needs to change to reflect OQ-12 being resolved.

## 4. Imagery and interaction primitives

### 4.1 What already exists (reuse, don't reinvent)

- `<x-mk.card>` — already supports `interactive` (hover border-color/shadow transition, background
  tint on hover gated to `intent === null` per the 19 Aug homepage visual refresh), `as="a"`/`href`
  for whole-card-is-the-link navigation, and a `media` slot for full-bleed imagery above the card
  body. The marketplace browse page (`MarketplaceIndex`) already demonstrates the exact
  card-grid-as-navigation pattern this effort wants elsewhere.
- Motion budget: `duration-fast`/`ease-standard` on hover transitions, no transform/scale (per
  `design-system.md`'s "Motion: Barely noticeable" row) — already the established convention on
  `<x-mk.card interactive>`, not something new to invent.

### 4.2 What's new

**A photography-driven hero pattern.** No public page currently uses real cemetery/garden
photography (`design-system.md` §2.2 already permits this — "Real cemeteries/gardens, daylight, no
people in grief" — it's simply unused so far). A new hero block (likely a new `<x-mk.hero>`
primitive, or an extension of the homepage's existing top section) that pairs a real photo with the
page's primary heading/CTA. Exact photo sourcing (stock library with a real-cemetery/daylight
filter, or commissioned photography) is an implementation-time decision, not a design-spec
decision — flagged here so it isn't silently assumed.

**Primary navigation as a card grid.** The homepage's four primary nav items (`Pemesanan Makam`,
`Layanan Pemakaman`, `Perpanjangan Makam`, `FAQ`) become an interactive card grid (icon + label),
reusing `<x-mk.card interactive as="a">` exactly as marketplace already does, rather than a new
component. This directly matches the `kamboja.co.id` benchmark's "service cards" pattern the user
referenced, using a primitive the codebase already has.

## 5. Phasing

Even though the accepted scope is the whole public site, execution stays broken into reviewable
units — this repo's established discipline (worktree-isolated, task-scoped review, one PR per unit
of work) applies here the same as every other effort this session.

1. **Foundation** — color ramp regeneration (§3), `FilamentPaletteGenerator` regeneration,
   `design-system.md` update (resolves OQ-12), the new hero primitive and nav-card pattern built
   and documented (but not yet applied to a real page beyond what's needed to prove them work).
2. **Homepage** — the flagship: new hero with real photography, nav-as-cards, refreshed trust
   section using the new tint colors. First real visual proof of the whole direction.
3. **Per-journey rollout** — one phase per journey, same primitives reused: booking wizard (entry
   point + step visuals), marketplace (lightest touch — already has cards, just picks up the new
   color ramp), renewal, FAQ, cemetery directory, visitation, memorial/QR.

Each phase gets its own implementation plan and PR(s), following `superpowers:writing-plans` +
`superpowers:subagent-driven-development` the same way every other piece of work has this session.
This spec's own immediate scope is Phase 1 (Foundation) — Phases 2-3 get their own follow-up plans
once Phase 1's actual regenerated colors and primitives exist to build against, matching this
repo's own established "each phase gets its own plan doc" pattern.

## 6. Testing and verification

- **`ci/verify-docs.sh`** already gates hardcoded design values and arbitrary Tailwind values
  (Gates 1-2) and WCAG AA color-pair assertions (Gate 1) — every new/changed shade must pass this
  gate, not just look right.
- **`design:verify-filament-palette`** must pass after `tokens.css` changes (no drift between the
  source of truth and the generated admin palette).
- **Existing E2E suites** (`tests/browser/*.spec.ts`, including the just-built `e2e-marketplace.spec.ts`)
  assert real rendered text/roles, not colors directly — they should be unaffected by a pure color
  change, but must still be re-run after each phase to catch any incidental breakage (e.g. if a
  hover-state class change accidentally affects an accessible name or focus order).
- **New a11y scans**: any new page state (hero, card grid) needs the same `AxeBuilder` scan
  discipline already established this session — zero violations, verified against a real running
  server, not assumed from reading the code.
- **Manual visual check**: since this is fundamentally a visual change, each phase needs a real
  browser look (via `dev.makam.co.id`, never a raw container port, per this repo's own established
  lesson) before being considered done — automated tests can prove text/roles/contrast but not
  "does this actually look like the agreed direction."

## 7. Open items for implementation time (not decided here)

- The exact final hex values for every shade 50-950 of both re-anchored families — §3.2's method
  is specified, the numbers themselves are implementation work, checked against AA at generation
  time.
- Exact photo sourcing for the new hero pattern.
- Whether `primary`'s "fresher" working shade lands on `500` or needs a different step — provisional
  in §3.3, confirmed once the real ramp and contrast numbers exist.
